<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Cash\CashVoucherModel;
use Cloudexus\Model\Finance\PaymentModel;
use Cloudexus\Model\Sales\InvoiceModel;

final class PaymentTest extends DatabaseTestCase
{
    private PaymentModel $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payments = new PaymentModel();
    }

    /** An unpaid invoice of 1270 gross (1000 net + 27 % VAT), issued and due as given. */
    private function invoice(?int $partner = null, string $issued = 'today', string $due = '+8 days'): int
    {
        return (new InvoiceModel())->create([
            'order_id' => null, 'partner_id' => $partner ?? $this->partner(), 'warehouse_id' => null, 'status' => 'unpaid',
            'issue_date' => date('Y-m-d', strtotime($issued)), 'fulfilment_date' => null, 'due_date' => date('Y-m-d', strtotime($due)),
            'payment_method' => 'transfer', 'shipping_cost' => 0, 'payment_cost' => 0, 'created_by' => null,
        ], [['product_id' => $this->product(1000), 'quantity' => 1, 'unit_price' => 1000]]);
    }

    private function state(int $id): string
    {
        $row = (new InvoiceModel())->findById($id);

        return $row['status'] . '/' . (float) $row['paid_amount'];
    }

    public function testPartialPaymentsAddUpUntilTheInvoiceIsPaid(): void
    {
        $id = $this->invoice();

        $this->payments->record(PaymentModel::INVOICE, $id, 500, date('Y-m-d'), 'transfer', 'első', null);
        self::assertSame('unpaid/500', $this->state($id));

        $this->payments->record(PaymentModel::INVOICE, $id, 770, date('Y-m-d'), 'card', null, null);
        self::assertSame('paid/1270', $this->state($id));
    }

    public function testOverpaymentIsRefused(): void
    {
        $id = $this->invoice();
        $this->payments->record(PaymentModel::INVOICE, $id, 1000, date('Y-m-d'), 'transfer', null, null);

        try {
            $this->payments->record(PaymentModel::INVOICE, $id, 271, date('Y-m-d'), 'transfer', null, null);
            self::fail('overpayment must throw');
        } catch (\DomainException $e) {
            self::assertSame('overpayment', $e->getMessage());
        }
        self::assertSame('unpaid/1000', $this->state($id));
    }

    public function testAPaidInvoiceTakesNoMorePaymentsAndReversalReopensIt(): void
    {
        $id = $this->invoice();
        (new InvoiceModel())->markPaid($id);
        self::assertSame('paid/1270', $this->state($id));

        try {
            $this->payments->record(PaymentModel::INVOICE, $id, 1, date('Y-m-d'), 'transfer', null, null);
            self::fail('a paid invoice must not take payments');
        } catch (\DomainException $e) {
            self::assertSame('not_payable', $e->getMessage());
        }

        $paymentId = (int) $this->payments->forDocument(PaymentModel::INVOICE, $id)[0]['id'];
        $this->payments->delete($paymentId);
        self::assertSame('unpaid/0', $this->state($id));
    }

    public function testACashVoucherIsAPaymentAndDeletingItReversesThePayment(): void
    {
        $id = $this->invoice();
        $vouchers = new CashVoucherModel();

        $voucherId = $vouchers->create(['type' => 'bevetel', 'amount' => 270, 'partner_id' => null, 'invoice_id' => $id,
            'incoming_invoice_id' => null, 'note' => '', 'voucher_date' => date('Y-m-d'), 'created_by' => null]);
        self::assertSame('unpaid/270', $this->state($id));

        $paymentId = $this->payments->idForVoucher($voucherId);
        self::assertNotNull($paymentId);
        try {
            $this->payments->delete($paymentId);
            self::fail('a voucher payment is reversed through the voucher');
        } catch (\DomainException $e) {
            self::assertSame('voucher', $e->getMessage());
        }

        $vouchers->delete($voucherId);
        self::assertSame('unpaid/0', $this->state($id));
    }

    public function testAnOverpayingVoucherLeavesNothingBehind(): void
    {
        $id = $this->invoice();

        try {
            (new CashVoucherModel())->create(['type' => 'bevetel', 'amount' => 5000, 'partner_id' => null, 'invoice_id' => $id,
                'incoming_invoice_id' => null, 'note' => '', 'voucher_date' => date('Y-m-d'), 'created_by' => null]);
            self::fail('overpayment must throw');
        } catch (\DomainException) {
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM cash_vouchers'));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM document_sequences WHERE doc_type = 'cash_voucher' AND last_number > 0"));
    }

    public function testAgingBucketsByDaysPastDue(): void
    {
        $partner = $this->partner('Késlekedő Kft.');
        $this->invoice($partner, '-20 days', '+5 days');   // not yet due
        $this->invoice($partner, '-40 days', '-10 days');  // 10 days late
        $late = $this->invoice($partner, '-100 days', '-95 days'); // 95 days late
        $this->payments->record(PaymentModel::INVOICE, $late, 270, date('Y-m-d'), 'transfer', null, null);

        $report = $this->payments->aging(PaymentModel::INVOICE, date('Y-m-d'));
        $row = $report['rows'][0];

        self::assertCount(1, $report['rows']);
        self::assertSame(3, (int) $row['document_count']);
        self::assertEqualsWithDelta(1270, (float) $row['current_amount'], 0.001);
        self::assertEqualsWithDelta(1270, (float) $row['d1_30'], 0.001);
        self::assertEqualsWithDelta(1000, (float) $row['d90_plus'], 0.001, 'the partial payment is taken off');
        self::assertEqualsWithDelta(3540, $report['totals']['total_open'], 0.001);
        self::assertSame(95, (int) $row['oldest_days']);
    }

    public function testAgingAsOfAnEarlierDayIgnoresLaterInvoicesAndPayments(): void
    {
        $partner = $this->partner();
        $old = $this->invoice($partner, '-30 days', '-20 days');
        $this->invoice($partner, '-2 days', '+6 days');
        $this->payments->record(PaymentModel::INVOICE, $old, 1270, date('Y-m-d', strtotime('-1 day')), 'transfer', null, null);

        $asOf = date('Y-m-d', strtotime('-5 days'));
        $then = $this->payments->aging(PaymentModel::INVOICE, $asOf);
        self::assertSame(1, (int) $then['rows'][0]['document_count'], 'only the old invoice existed, still unpaid');
        self::assertEqualsWithDelta(1270, (float) $then['rows'][0]['d1_30'], 0.001);

        $now = $this->payments->aging(PaymentModel::INVOICE, date('Y-m-d'));
        self::assertSame(1, (int) $now['rows'][0]['document_count'], 'today only the new one is open');
        self::assertEqualsWithDelta(1270, (float) $now['rows'][0]['current_amount'], 0.001);
    }
}
