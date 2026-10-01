<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Purchasing\IncomingInvoiceModel;
use Cloudexus\Model\Sales\InvoiceModel;

/**
 * An order picked, or a purchase order received, in the mobile app has
 * already moved its stock (25_picking_and_receiving.sql): the invoice made of
 * it afterwards must not move it a second time, nor its storno back.
 */
final class PickingReceivingTest extends DatabaseTestCase
{
    private function order(int $partner, int $product, float $quantity): int
    {
        $this->pdo()->prepare(
            "INSERT INTO orders (order_number, partner_id, status, order_date, created_at) VALUES ('REND-T-1', :partner, 'confirmed', CURDATE(), NOW())"
        )->execute(['partner' => $partner]);
        $id = (int) $this->pdo()->lastInsertId();
        $this->pdo()->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total) VALUES (:o, :p, :q, 100, :t)')
            ->execute(['o' => $id, 'p' => $product, 'q' => $quantity, 't' => $quantity * 100]);

        return $id;
    }

    private function invoice(int $partner, int $order, int $product, float $quantity, int $warehouse): int
    {
        return (new InvoiceModel())->create([
            'order_id' => $order, 'partner_id' => $partner, 'warehouse_id' => $warehouse, 'status' => 'unpaid',
            'issue_date' => date('Y-m-d'), 'fulfilment_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+8 days')),
            'payment_method' => 'transfer', 'shipping_cost' => 0, 'payment_cost' => 0, 'created_by' => null,
        ], [['product_id' => $product, 'quantity' => $quantity, 'unit_price' => 100]]);
    }

    public function testAnUnpickedOrdersInvoiceStillBooksTheStockOut(): void
    {
        $partner = $this->partner();
        $warehouse = $this->warehouse();
        $product = $this->product(100);
        $this->stockIn($warehouse, $product, 10);
        $order = $this->order($partner, $product, 4);

        $this->invoice($partner, $order, $product, 4, $warehouse);

        self::assertEqualsWithDelta(6, $this->stockOf($warehouse, $product), 0.0001);
    }

    public function testAPickedOrdersInvoiceAndItsStornoLeaveTheStockAlone(): void
    {
        $partner = $this->partner();
        $warehouse = $this->warehouse();
        $product = $this->product(100);
        $this->stockIn($warehouse, $product, 10);
        $order = $this->order($partner, $product, 4);

        // The pick took the four out.
        $this->pdo()->prepare(
            "INSERT INTO stock_movements (warehouse_id, product_id, type, quantity, note, created_at) VALUES (:w, :p, 'out', 4, 'Kiszedés', NOW())"
        )->execute(['w' => $warehouse, 'p' => $product]);
        $this->pdo()->prepare('UPDATE orders SET picked_at = NOW(), picked_warehouse_id = :w WHERE id = :id')->execute(['w' => $warehouse, 'id' => $order]);

        $invoices = new InvoiceModel();
        $id = $this->invoice($partner, $order, $product, 4, $warehouse);
        self::assertEqualsWithDelta(6, $this->stockOf($warehouse, $product), 0.0001, 'The invoice took the picked goods out again.');
        self::assertNull($invoices->findById($id)['warehouse_id']);

        $invoices->storno($id, null);
        self::assertEqualsWithDelta(6, $this->stockOf($warehouse, $product), 0.0001, 'The storno brought goods back that left at the pick.');
    }

    public function testAReceivedPurchaseOrdersIncomingInvoiceLeavesTheStockAlone(): void
    {
        $supplier = $this->partner('Beszállító Kft.', null, 'supplier');
        $warehouse = $this->warehouse();
        $product = $this->product(100);

        $this->pdo()->prepare(
            "INSERT INTO purchase_orders (po_number, partner_id, status, order_date, created_at, received_at, received_warehouse_id)
             VALUES ('BESZ-T-1', :partner, 'confirmed', CURDATE(), NOW(), NOW(), :w)"
        )->execute(['partner' => $supplier, 'w' => $warehouse]);
        $po = (int) $this->pdo()->lastInsertId();
        // The receipt brought five in.
        $this->stockIn($warehouse, $product, 5);

        $invoices = new IncomingInvoiceModel();
        $id = $invoices->create([
            'purchase_order_id' => $po, 'partner_id' => $supplier, 'warehouse_id' => $warehouse,
            'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+8 days')), 'created_by' => null,
        ], [['product_id' => $product, 'quantity' => 5, 'unit_price' => 100]]);

        self::assertEqualsWithDelta(5, $this->stockOf($warehouse, $product), 0.0001, 'The incoming invoice booked the received goods in again.');

        $invoices->cancel($id, null);
        self::assertEqualsWithDelta(5, $this->stockOf($warehouse, $product), 0.0001, 'Its cancellation took received goods out.');
    }
}
