<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Sales\QuoteModel;

final class QuoteTest extends DatabaseTestCase
{
    private function quote(int $partner, array $items, array $extra = []): int
    {
        return (new QuoteModel())->create($extra + [
            'partner_id' => $partner,
            'quote_date' => date('Y-m-d'),
            'valid_until' => date('Y-m-d', strtotime('+15 days')),
            'shipping_cost' => 0.0,
            'payment_cost' => 0.0,
            'note' => '',
            'created_by' => null,
        ], $items);
    }

    public function testLinesArePricedWithVatLikeAnInvoiceAndTheNumberIsTheYearsNext(): void
    {
        $partner = $this->partner();
        $bike = $this->product(10000, ['vat_rate' => 27]);
        $book = $this->product(1000, ['vat_rate' => 5]);

        $id = $this->quote($partner, [
            ['product_id' => $bike, 'quantity' => 2, 'unit_price' => 10000],
            ['product_id' => $book, 'quantity' => 3, 'unit_price' => 1000],
        ], ['shipping_cost' => 1000.0]);
        $quote = (new QuoteModel())->findById($id);

        self::assertMatchesRegularExpression('/^AJ-' . date('Y') . '-\d{4}$/', $quote['quote_number']);
        self::assertSame('draft', $quote['status']);
        self::assertCount(2, $quote['items']);
        self::assertSame(24000.0, (float) $quote['net_total'], '20 000 + 3 000 + 1 000 shipping');
        self::assertSame(5400.0 + 150.0 + 270.0, (float) $quote['vat_total'], '27% of 20 000, 5% of 3 000, 27% of the shipping');
        self::assertSame(24000.0 + 5820.0, (float) $quote['total_amount']);
        self::assertCount(2, $quote['vat_summary']);
    }

    public function testASentQuotePastItsValidityShowsAsExpired(): void
    {
        $id = $this->quote($this->partner(), [['product_id' => $this->product(100), 'quantity' => 1, 'unit_price' => 100]]);
        $quotes = new QuoteModel();
        $quotes->markSent($id, 'anna@kovacs.hu');
        self::assertSame('sent', $quotes->findById($id)['effective_status']);

        $this->pdo()->exec('UPDATE quotes SET valid_until = CURDATE() - INTERVAL 1 DAY WHERE id = ' . $id);
        self::assertSame('expired', $quotes->findById($id)['effective_status']);
        self::assertSame('anna@kovacs.hu', $quotes->findById($id)['emailed_to']);
    }

    public function testAnAcceptedQuoteBecomesAnOrderOnceWithTheSameLines(): void
    {
        $partner = $this->partner();
        $product = $this->product(5000);
        $id = $this->quote($partner, [['product_id' => $product, 'quantity' => 4, 'unit_price' => 4500]]);
        $quotes = new QuoteModel();

        self::assertTrue($quotes->decide($id, true));
        $orderId = $quotes->toOrder($id, null);

        $quote = $quotes->findById($id);
        self::assertSame('ordered', $quote['status']);
        self::assertSame($orderId, (int) $quote['order_id']);
        self::assertSame($id, (int) $this->scalar('SELECT quote_id FROM orders WHERE id = :id', ['id' => $orderId]));
        self::assertSame('4500.00', (string) $this->scalar('SELECT unit_price FROM order_items WHERE order_id = :id', ['id' => $orderId]), 'the quoted price, not the list price');

        $this->expectException(\DomainException::class);
        $quotes->toOrder($id, null);
    }

    public function testARejectedQuoteKeepsItsReasonAndCannotBeOrderedOrEdited(): void
    {
        $product = $this->product(100);
        $id = $this->quote($this->partner(), [['product_id' => $product, 'quantity' => 1, 'unit_price' => 100]]);
        $quotes = new QuoteModel();

        self::assertTrue($quotes->decide($id, false, 'Drágább a versenytársnál'));
        self::assertSame('Drágább a versenytársnál', $quotes->findById($id)['rejection_reason']);
        self::assertFalse($quotes->decide($id, true), 'decided already');
        self::assertFalse($quotes->update($id, ['partner_id' => $this->partner('Más'), 'quote_date' => date('Y-m-d'), 'valid_until' => date('Y-m-d'), 'shipping_cost' => 0.0, 'payment_cost' => 0.0, 'note' => ''], [['product_id' => $product, 'quantity' => 2, 'unit_price' => 100]]));

        try {
            $quotes->toOrder($id, null);
            self::fail('a rejected quote became an order');
        } catch (\DomainException) {
            self::assertTrue(true);
        }
        self::assertTrue($quotes->delete($id));
    }

    public function testAnOpenQuoteIsRepricedOnEdit(): void
    {
        $partner = $this->partner();
        $product = $this->product(1000);
        $id = $this->quote($partner, [['product_id' => $product, 'quantity' => 1, 'unit_price' => 1000]]);

        self::assertTrue((new QuoteModel())->update($id, ['partner_id' => $partner, 'quote_date' => date('Y-m-d'), 'valid_until' => date('Y-m-d', strtotime('+30 days')), 'shipping_cost' => 0.0, 'payment_cost' => 0.0, 'note' => 'Szállítás 3 nap'], [
            ['product_id' => $product, 'quantity' => 5, 'unit_price' => 900],
        ]));
        $quote = (new QuoteModel())->findById($id);

        self::assertCount(1, $quote['items']);
        self::assertSame(4500.0, (float) $quote['net_total']);
        self::assertSame('Szállítás 3 nap', $quote['note']);
    }
}
