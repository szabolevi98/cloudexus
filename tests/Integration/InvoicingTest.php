<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Core\DocumentNumber;
use Cloudexus\Model\Core\StockShortage;
use Cloudexus\Model\Finance\PaymentModel;
use Cloudexus\Model\Sales\InvoiceModel;

final class InvoicingTest extends DatabaseTestCase
{
    private function issue(int $partner, array $lines, ?int $warehouse = null, float $shipping = 0): int
    {
        return (new InvoiceModel())->create([
            'order_id' => null, 'partner_id' => $partner, 'warehouse_id' => $warehouse, 'status' => 'unpaid',
            'issue_date' => date('Y-m-d'), 'fulfilment_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+8 days')),
            'payment_method' => 'transfer', 'shipping_cost' => $shipping, 'payment_cost' => 0, 'created_by' => null,
        ], $lines);
    }

    public function testNumbersAreSequentialAndStartAfterTheHighestUsed(): void
    {
        $year = date('Y');
        $this->pdo()->exec("INSERT INTO document_sequences (doc_type, year, last_number) VALUES ('order', $year, 41)");

        self::assertSame("REND-$year-0042", DocumentNumber::take('order'));
        self::assertSame("REND-$year-0043", DocumentNumber::take('order'));
        self::assertSame("REND-$year-0044", DocumentNumber::preview('order'));
        self::assertSame("SZLA-$year-0001", DocumentNumber::take('invoice'));
        self::assertSame('SZLA-2031-0001', DocumentNumber::take('invoice', '2031-01-15'), 'every year starts again');
    }

    public function testVatIsPerLineAndTheTotalsAddUp(): void
    {
        $partner = $this->partner();
        $a = $this->product(1000, ['vat_rate' => 27]);
        $b = $this->product(500, ['vat_rate' => 5]);

        $id = $this->issue($partner, [
            ['product_id' => $a, 'quantity' => 3, 'unit_price' => 1000],
            ['product_id' => $b, 'quantity' => 2, 'unit_price' => 500],
        ], null, 1000);
        $invoice = (new InvoiceModel())->findById($id);

        // Lines: 3000 + 810 VAT, 1000 + 50 VAT; shipping 1000 + 270 at 27 %.
        self::assertEqualsWithDelta(5000, (float) $invoice['net_total'], 0.001);
        self::assertEqualsWithDelta(1130, (float) $invoice['vat_total'], 0.001);
        self::assertEqualsWithDelta(6130, (float) $invoice['total_amount'], 0.001);
        self::assertSame('Teszt Kft.', $invoice['buyer_name'], 'the buyer is snapshotted');
    }

    public function testStockOutIsBookedAndAShortageStopsTheInvoiceBeforeItGetsANumber(): void
    {
        $partner = $this->partner();
        $warehouse = $this->warehouse();
        $product = $this->product(100);
        $this->stockIn($warehouse, $product, 5);

        $this->issue($partner, [['product_id' => $product, 'quantity' => 4, 'unit_price' => 100]], $warehouse);
        self::assertEqualsWithDelta(1, $this->stockOf($warehouse, $product), 0.0001);

        try {
            $this->issue($partner, [['product_id' => $product, 'quantity' => 2, 'unit_price' => 100]], $warehouse);
            self::fail('a shortage must throw');
        } catch (StockShortage $e) {
            self::assertEqualsWithDelta(1, $e->first()['available'], 0.0001);
        }
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM invoices'));
        self::assertSame(
            1,
            (int) $this->scalar("SELECT last_number FROM document_sequences WHERE doc_type = 'invoice'"),
            'the refused invoice did not use up a number'
        );
    }

    public function testStornoMirrorsTheInvoiceAndBooksTheStockBack(): void
    {
        $partner = $this->partner();
        $warehouse = $this->warehouse();
        $product = $this->product(100);
        $this->stockIn($warehouse, $product, 10);
        $model = new InvoiceModel();
        $id = $this->issue($partner, [['product_id' => $product, 'quantity' => 4, 'unit_price' => 100]], $warehouse);

        $stornoId = $model->storno($id, null);
        $storno = $model->findById($stornoId);

        self::assertSame('storno', $storno['invoice_type']);
        self::assertEqualsWithDelta(-(float) $model->findById($id)['total_amount'], (float) $storno['total_amount'], 0.001);
        self::assertSame('cancelled', $model->findById($id)['status']);
        self::assertEqualsWithDelta(10, $this->stockOf($warehouse, $product), 0.0001);

        $this->expectException(\DomainException::class);
        $model->storno($id, null);
    }

    public function testAnInvoiceWithAPaymentCannotBeReversed(): void
    {
        $id = $this->issue($this->partner(), [['product_id' => $this->product(1000), 'quantity' => 1, 'unit_price' => 1000]]);
        (new PaymentModel())->record(PaymentModel::INVOICE, $id, 100, date('Y-m-d'), 'transfer', null, null);

        $this->expectException(\DomainException::class);
        (new InvoiceModel())->storno($id, null);
    }
}
