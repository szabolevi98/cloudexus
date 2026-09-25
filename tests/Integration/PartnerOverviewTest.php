<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Crm\PartnerOverviewModel;
use Cloudexus\Model\Finance\PaymentModel;
use Cloudexus\Model\Sales\InvoiceModel;

final class PartnerOverviewTest extends DatabaseTestCase
{
    /** An invoice of 1 000 net (1 270 gross). */
    private function invoice(int $partner, string $issued = 'today', string $due = '+8 days'): int
    {
        return (new InvoiceModel())->create([
            'order_id' => null, 'partner_id' => $partner, 'warehouse_id' => null, 'status' => 'unpaid',
            'issue_date' => date('Y-m-d', strtotime($issued)), 'fulfilment_date' => null, 'due_date' => date('Y-m-d', strtotime($due)),
            'payment_method' => 'transfer', 'shipping_cost' => 0, 'payment_cost' => 0, 'created_by' => null,
        ], [['product_id' => $this->product(1000, ['name' => 'Csengő']), 'quantity' => 1, 'unit_price' => 1000]]);
    }

    public function testTheCreditSaysWhatIsOpenOverdueAndLeft(): void
    {
        $partner = $this->partner();
        $this->pdo()->exec("UPDATE partners SET credit_limit = 3000, payment_terms_days = 30 WHERE id = $partner");
        $this->invoice($partner);
        $this->invoice($partner, '-40 days', '-10 days');

        $credit = (new PartnerOverviewModel())->credit($partner);

        self::assertSame(2540.0, $credit['open_balance']);
        self::assertSame(1270.0, $credit['overdue']);
        self::assertSame(460.0, $credit['credit_left']);
        self::assertSame(30, $credit['payment_terms_days']);

        $none = (new PartnerOverviewModel())->credit($this->partner('Keret nélkül'));
        self::assertNull($none['credit_limit']);
        self::assertNull($none['credit_left']);
        self::assertSame(PartnerOverviewModel::DEFAULT_TERMS_DAYS, $none['payment_terms_days']);
    }

    public function testTheFiguresCountRevenuePaymentHabitAndQuotes(): void
    {
        $partner = $this->partner();
        $late = $this->invoice($partner, '-20 days', '-10 days');
        (new PaymentModel())->record(PaymentModel::INVOICE, $late, 1270, date('Y-m-d'), 'transfer', null, null);
        $this->invoice($partner);
        $this->pdo()->exec("INSERT INTO quotes (quote_number, partner_id, status, quote_date, valid_until, created_at) VALUES
            ('AJ-T-1', $partner, 'accepted', CURDATE(), CURDATE(), NOW()), ('AJ-T-2', $partner, 'rejected', CURDATE(), CURDATE(), NOW()),
            ('AJ-T-3', $partner, 'ordered', CURDATE(), CURDATE(), NOW()), ('AJ-T-4', $partner, 'sent', CURDATE(), CURDATE() + INTERVAL 5 DAY, NOW())");

        $kpis = (new PartnerOverviewModel())->kpis($partner);

        self::assertSame(2540.0, (float) $kpis['revenue_year']);
        self::assertSame(10.0, $kpis['avg_delay_days'], 'paid today, due ten days ago');
        self::assertSame(67.0, (float) $kpis['quote_win_rate'], 'two won of three decided');
        self::assertSame(1, $kpis['quotes_open']);
        self::assertSame(1270.0, $kpis['open_balance']);
    }

    public function testTheTimelineHasEverythingNewestFirst(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner);
        (new PaymentModel())->record(PaymentModel::INVOICE, $invoice, 500, date('Y-m-d'), 'card', null, null);
        (new \Cloudexus\Model\Crm\PartnerActivityModel())->create([
            'partner_id' => $partner, 'type' => 'call', 'subject' => 'Felhívtuk', 'note' => '', 'activity_date' => date('Y-m-d H:i:s', strtotime('+1 minute')), 'created_by' => null,
        ]);

        $kinds = array_column((new PartnerOverviewModel())->timeline($partner), 'kind');

        self::assertSame('activity', $kinds[0]);
        self::assertContains('invoice', $kinds);
        self::assertContains('payment', $kinds);

        $top = (new PartnerOverviewModel())->topProducts($partner);
        self::assertSame('Csengő', $top[0]['name']);
    }
}
