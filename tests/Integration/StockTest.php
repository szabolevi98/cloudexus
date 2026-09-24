<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Core\StockMovementModel;
use Cloudexus\Model\Core\StockShortage;
use Cloudexus\Model\Core\StocktakingModel;
use Cloudexus\Model\Purchasing\IncomingInvoiceModel;
use Cloudexus\Model\Sales\OrderModel;

final class StockTest extends DatabaseTestCase
{
    public function testAShortageInsideTheLockRollsTheWholeBookingBack(): void
    {
        $warehouse = $this->warehouse();
        $a = $this->product(100);
        $b = $this->product(100);
        $this->stockIn($warehouse, $a, 5);
        $movements = new StockMovementModel();

        try {
            $movements->locked([$warehouse], function () use ($movements, $warehouse, $a, $b): void {
                $movements->create(['warehouse_id' => $warehouse, 'product_id' => $a, 'type' => 'out', 'quantity' => 2, 'note' => '', 'created_by' => null]);
                $movements->assertAvailable($warehouse, [$b => 1]);
            });
            self::fail('the shortage must throw');
        } catch (StockShortage $e) {
            self::assertSame([$b], array_keys($e->shortages));
        }

        self::assertEqualsWithDelta(5, $this->stockOf($warehouse, $a), 0.0001, 'the first line was rolled back too');
    }

    public function testATransferJoinsTheLockedTransaction(): void
    {
        $from = $this->warehouse('A');
        $to = $this->warehouse('B');
        $product = $this->product(100);
        $this->stockIn($from, $product, 3);
        $movements = new StockMovementModel();

        $movements->locked([$from, $to], function () use ($movements, $from, $to, $product): void {
            $movements->assertAvailable($from, [$product => 2.5]);
            $movements->transfer($from, $to, $product, 2.5, 'teszt', null);
        });

        self::assertEqualsWithDelta(0.5, $this->stockOf($from, $product), 0.0001);
        self::assertEqualsWithDelta(2.5, $this->stockOf($to, $product), 0.0001);
    }

    public function testStocktakingUsesTheServersBookQuantity(): void
    {
        $warehouse = $this->warehouse();
        $product = $this->product(100);
        $this->stockIn($warehouse, $product, 4);

        // The form claimed a book quantity of 100; the model does not ask for it.
        $id = (new StocktakingModel())->book($warehouse, '', [['product_id' => $product, 'book_quantity' => 100, 'counted_quantity' => 6]], null);

        self::assertEqualsWithDelta(6, $this->stockOf($warehouse, $product), 0.0001);
        $item = $this->pdo()->query("SELECT book_quantity, diff FROM stocktaking_items WHERE stocktaking_id = $id")->fetch();
        self::assertEqualsWithDelta(4, (float) $item['book_quantity'], 0.0001);
        self::assertEqualsWithDelta(2, (float) $item['diff'], 0.0001);
    }

    public function testCancellingAnIncomingInvoiceBooksItsGoodsBackOutUnlessTheyAreGone(): void
    {
        $warehouse = $this->warehouse();
        $product = $this->product(100);
        $model = new IncomingInvoiceModel();
        $create = fn(): int => $model->create([
            'purchase_order_id' => null, 'partner_id' => $this->partner('Szállító', null, 'supplier'), 'warehouse_id' => $warehouse,
            'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+8 days')), 'created_by' => null,
        ], [['product_id' => $product, 'quantity' => 4, 'unit_price' => 50]]);

        $first = $create();
        self::assertEqualsWithDelta(4, $this->stockOf($warehouse, $product), 0.0001);
        $model->cancel($first, null);
        self::assertEqualsWithDelta(0, $this->stockOf($warehouse, $product), 0.0001);
        self::assertSame('cancelled', $model->findById($first)['status']);

        $second = $create();
        (new StockMovementModel())->create(['warehouse_id' => $warehouse, 'product_id' => $product, 'type' => 'out',
            'quantity' => 3, 'note' => 'eladva', 'created_by' => null]);
        $this->expectException(StockShortage::class);
        try {
            $model->cancel($second, null);
        } finally {
            self::assertSame('unpaid', $model->findById($second)['status'], 'the cancel was rolled back');
        }
    }

    public function testOrdersCancelAndDeleteOnlyInTheirStates(): void
    {
        $orders = new OrderModel();
        $partner = $this->partner();
        $product = $this->product(100);
        $id = $orders->create(['partner_id' => $partner, 'shipping_address_id' => 0, 'billing_address_id' => 0, 'status' => 'confirmed',
            'order_date' => date('Y-m-d'), 'shipping_cost' => 0, 'payment_cost' => 0, 'created_by' => null],
            [['product_id' => $product, 'quantity' => 1, 'unit_price' => 100]]);

        self::assertFalse($orders->delete($id), 'a confirmed order is cancelled, not deleted');
        self::assertTrue($orders->cancel($id));
        self::assertFalse($orders->cancel($id), 'twice is not possible');
        self::assertTrue($orders->delete($id));

        $invoiced = $orders->create(['partner_id' => $partner, 'shipping_address_id' => 0, 'billing_address_id' => 0, 'status' => 'confirmed',
            'order_date' => date('Y-m-d'), 'shipping_cost' => 0, 'payment_cost' => 0, 'created_by' => null],
            [['product_id' => $product, 'quantity' => 1, 'unit_price' => 100]]);
        $this->pdo()->prepare("UPDATE orders SET status = 'invoiced' WHERE id = :id")->execute(['id' => $invoiced]);
        self::assertFalse($orders->cancel($invoiced));
        self::assertTrue($orders->isLocked($invoiced));
    }
}
