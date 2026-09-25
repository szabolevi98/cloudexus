<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Crm\DealModel;
use Cloudexus\Model\Sales\QuoteModel;

final class DealTest extends DatabaseTestCase
{
    /** @param array<string, mixed> $extra */
    private function deal(int $partner, array $extra = []): int
    {
        return (new DealModel())->create($extra + [
            'title' => 'Új bolt berendezése', 'partner_id' => $partner, 'stage' => 'lead', 'amount' => 100000.0,
            'probability' => null, 'expected_close' => null, 'owner_id' => null, 'note' => '',
        ]);
    }

    public function testTheBoardSumsEachStageAndWeighsByProbability(): void
    {
        $partner = $this->partner();
        $this->deal($partner);
        $this->deal($partner, ['stage' => 'proposal', 'amount' => 200000.0]);
        $this->deal($partner, ['stage' => 'proposal', 'amount' => 100000.0, 'probability' => 90]);

        $board = (new DealModel())->board();

        self::assertSame(1, $board['lead']['count']);
        self::assertSame(10000.0, $board['lead']['weighted'], '10% of 100 000');
        self::assertSame(2, $board['proposal']['count']);
        self::assertSame(300000.0, $board['proposal']['amount']);
        self::assertSame(190000.0, $board['proposal']['weighted'], '50% of 200 000 and 90% of 100 000');
        self::assertSame(0, $board['won']['count']);
    }

    public function testMovingSetsTheStageTheOrderAndNeedsAReasonToLose(): void
    {
        $partner = $this->partner();
        $first = $this->deal($partner, ['stage' => 'qualified']);
        $second = $this->deal($partner);
        $deals = new DealModel();

        self::assertTrue($deals->move($second, 'qualified', [$first, $second]));
        $ids = array_column($deals->board()['qualified']['deals'], 'id');
        self::assertSame([$first, $second], array_map('intval', $ids));

        self::assertFalse($deals->move($first, 'lost'), 'no reason');
        self::assertFalse($deals->move($first, 'nowhere'));
        self::assertTrue($deals->move($first, 'lost', [], 'Drága volt'));
        $lost = $deals->findById($first);
        self::assertSame('lost', $lost['stage']);
        self::assertSame('Drága volt', $lost['lost_reason']);
        self::assertNotNull($lost['closed_at']);
        self::assertSame(0, $lost['chance']);

        self::assertTrue($deals->move($first, 'negotiation'));
        $back = $deals->findById($first);
        self::assertNull($back['lost_reason']);
        self::assertNull($back['closed_at']);
        self::assertSame(75, $back['chance']);
    }

    public function testAQuoteMovesTheDealOnAndItsOrderWinsIt(): void
    {
        $partner = $this->partner();
        $deal = $this->deal($partner, ['amount' => 0.0]);
        $quotes = new QuoteModel();
        $quote = $quotes->create([
            'partner_id' => $partner, 'quote_date' => date('Y-m-d'), 'valid_until' => date('Y-m-d', strtotime('+15 days')),
            'shipping_cost' => 0.0, 'payment_cost' => 0.0, 'note' => '', 'created_by' => null,
        ], [['product_id' => $this->product(1000), 'quantity' => 3, 'unit_price' => 1000]]);

        (new DealModel())->attachQuote($deal, $quote);
        $attached = (new DealModel())->findById($deal);
        self::assertSame('proposal', $attached['stage']);
        self::assertSame(3000.0, (float) $attached['amount'], 'the net total of the quote');
        self::assertSame($deal, (int) (new DealModel())->findByQuote($quote)['id']);

        $order = $quotes->toOrder($quote, null);
        $won = (new DealModel())->findById($deal);
        self::assertSame('won', $won['stage']);
        self::assertSame($order, (int) $won['order_id']);
        self::assertSame(100, $won['chance']);
    }

    public function testClosedDealsLeaveTheBoardAfterAWhile(): void
    {
        $partner = $this->partner();
        $old = $this->deal($partner, ['stage' => 'won']);
        $this->pdo()->exec("UPDATE deals SET closed_at = NOW() - INTERVAL 40 DAY WHERE id = $old");
        $this->deal($partner, ['stage' => 'won']);

        self::assertSame(1, (new DealModel())->board()['won']['count']);
        self::assertCount(2, (new DealModel())->forPartner($partner));
    }
}
