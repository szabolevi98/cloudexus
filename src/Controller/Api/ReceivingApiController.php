<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Purchasing\PurchaseOrderModel;

/**
 * Receiving a purchase order on the PDA: the confirmed purchase orders with
 * something still to come, one order's lines with what was ordered and what
 * has arrived so far, and a receipt — which books the goods into the
 * warehouse, onto the shelves they were put on.
 *
 * A delivery may come in parts, so an order can be received more than once,
 * and a supplier may send more or less than was ordered: what was scanned is
 * what arrived. A received order's incoming invoice books no stock (see
 * IncomingInvoiceModel::create()): the goods came in at the receipt. See
 * 25_picking_and_receiving.sql.
 */
class ReceivingApiController extends StockApiController
{
    /** Confirmed purchase orders not yet invoiced, with lines still to come, oldest first. */
    public function index(): void
    {
        $this->requireUserPermission(Permissions::STOCK_MOVE);

        $rows = DatabaseConnection::get()->query(
            "SELECT po.id, po.po_number, po.order_date, po.partner_id, p.name AS partner_name, po.received_at,
                    COUNT(poi.id) AS line_count,
                    COALESCE(SUM(poi.quantity), 0) AS ordered,
                    COALESCE(SUM(poi.received_quantity), 0) AS received
             FROM purchase_orders po
             JOIN partners p ON p.id = po.partner_id
             LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
             WHERE po.status = 'confirmed'
             GROUP BY po.id
             HAVING SUM(poi.received_quantity < poi.quantity) > 0
             ORDER BY po.order_date ASC, po.id ASC
             LIMIT 200"
        )->fetchAll();

        $this->json(['data' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'po_number' => $row['po_number'],
            'order_date' => $row['order_date'],
            'partner_id' => (int) $row['partner_id'],
            'partner_name' => $row['partner_name'],
            'line_count' => (int) $row['line_count'],
            'ordered' => number_format((float) $row['ordered'], 3, '.', ''),
            'received' => number_format((float) $row['received'], 3, '.', ''),
            'partly_received' => $row['received_at'] !== null,
        ], $rows)]);
    }

    /** One purchase order to receive: per product, what was ordered and what has arrived. */
    public function show(int $id): void
    {
        $this->requireUserPermission(Permissions::STOCK_MOVE);

        $order = $this->receivableOrder($id);

        $this->json(['data' => [
            'id' => (int) $order['id'],
            'po_number' => $order['po_number'],
            'order_date' => $order['order_date'],
            'partner_name' => $order['partner_name'],
            'received_at' => $order['received_at'],
            'lines' => array_values($this->lines($order)),
        ]]);
    }

    /**
     * {warehouse_id, location_id, note, items: [{product_id, quantity,
     * location_id}]}: books the arrived goods in, and adds them to what the
     * order has received.
     */
    public function receive(int $id): void
    {
        $this->requireUserPermission(Permissions::STOCK_MOVE);

        $body = $this->body();
        $order = $this->receivableOrder($id);
        $warehouse = $this->activeWarehouse($body['warehouse_id'] ?? null, 'warehouse_id');
        $defaultLocation = $this->location($warehouse, $body['location_id'] ?? null, 'location_id');
        $ordered = $this->lines($order);
        $note = $this->note($body);

        $lines = [];
        $arrived = [];
        foreach ($this->items($body) as $i => $item) {
            $productId = (int) $item['product']['id'];
            if (!isset($ordered[$productId])) {
                $this->error('Some items are invalid.', 422, [['index' => $i, 'message' => 'This product is not on the purchase order.']]);
            }
            $location = array_key_exists('location_id', $item['raw'])
                ? $this->location($warehouse, $item['raw']['location_id'], "items[$i].location_id")
                : $defaultLocation;

            $key = $productId . ':' . ($location['id'] ?? '');
            $lines[$key] ??= ['product' => $item['product'], 'location' => $location, 'quantity' => 0.0];
            $lines[$key]['quantity'] += $item['quantity'];
            $arrived[$productId] = ($arrived[$productId] ?? 0.0) + $item['quantity'];
        }

        $fullNote = 'Átvétel: ' . $order['po_number'] . ($note !== null ? ' — ' . $note : '');

        $this->inTransaction(function () use ($id, $order, $warehouse, $lines, $arrived, $fullNote): array {
            $pdo = DatabaseConnection::get();
            $this->movements->lockWarehouses([$warehouse['id']]);

            // Invoiced or cancelled while this one was unloading.
            $lock = $pdo->prepare('SELECT status FROM purchase_orders WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            if ($lock->fetchColumn() !== 'confirmed') {
                return [409, ['error' => ['status' => 409, 'message' => 'This purchase order was invoiced or cancelled in the meantime.']]];
            }

            $movements = [];
            foreach ($lines as $line) {
                $movementId = $this->movements->create([
                    'warehouse_id' => (int) $warehouse['id'],
                    'location_id' => $line['location']['id'] ?? null,
                    'product_id' => (int) $line['product']['id'],
                    'type' => 'in',
                    'quantity' => $line['quantity'],
                    'note' => $fullNote,
                    'created_by' => (int) $this->user['id'],
                ]);
                $movements[] = ['id' => $movementId] + $this->productFields($line['product']) + [
                    'location_id' => $line['location']['id'] ?? null,
                    'location_code' => $line['location']['code'] ?? null,
                    'quantity' => self::qty($line['quantity']),
                ];
            }

            // What arrived fills the order's lines of the product in turn; what
            // is over the order goes onto the last of them.
            $items = $pdo->prepare('SELECT id, product_id, quantity, received_quantity FROM purchase_order_items WHERE purchase_order_id = :id ORDER BY id');
            $items->execute(['id' => $id]);
            $byProduct = [];
            foreach ($items->fetchAll() as $row) {
                $byProduct[(int) $row['product_id']][] = $row;
            }
            $update = $pdo->prepare('UPDATE purchase_order_items SET received_quantity = received_quantity + :q WHERE id = :id');
            foreach ($arrived as $productId => $quantity) {
                $rows = $byProduct[$productId];
                foreach ($rows as $n => $row) {
                    $room = max(0.0, (float) $row['quantity'] - (float) $row['received_quantity']);
                    $take = $n === array_key_last($rows) ? $quantity : min($room, $quantity);
                    if ($take > 0) {
                        $update->execute(['q' => round($take, 3), 'id' => $row['id']]);
                        $quantity -= $take;
                    }
                }
            }

            $pdo->prepare(
                'UPDATE purchase_orders SET received_at = COALESCE(received_at, NOW()), received_by = :user, received_warehouse_id = :warehouse WHERE id = :id'
            )->execute(['user' => (int) $this->user['id'], 'warehouse' => (int) $warehouse['id'], 'id' => $id]);

            return [201, ['data' => [
                'purchase_order' => ['id' => (int) $order['id'], 'po_number' => $order['po_number']],
                'warehouse' => $this->warehouseFields($warehouse),
                'note' => $fullNote,
                'created_by' => $this->createdBy(),
                'movements' => $movements,
                'lines' => array_values($this->lines((new PurchaseOrderModel())->findById($id) ?? $order)),
            ]]];
        });
    }

    /** A confirmed purchase order; 404 or 409 otherwise. */
    private function receivableOrder(int $id): array
    {
        $order = (new PurchaseOrderModel())->findById($id);
        if (!$order) {
            $this->error('Purchase order not found.', 404);
        }
        if ($order['status'] !== 'confirmed') {
            $this->error('Only a confirmed purchase order that has not been invoiced can be received.', 409);
        }

        return $order;
    }

    /**
     * The order's lines added up per product: ordered, received so far and
     * still to come, with what a scanner matches.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(array $order): array
    {
        $barcodes = [];
        $ids = array_map(static fn(array $item): int => (int) $item['product_id'], $order['items']);
        if ($ids) {
            $stmt = DatabaseConnection::get()->prepare('SELECT id, barcode FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            $barcodes = array_column($stmt->fetchAll(), 'barcode', 'id');
        }

        $lines = [];
        foreach ($order['items'] as $item) {
            $productId = (int) $item['product_id'];
            $lines[$productId] ??= [
                'product_id' => $productId,
                'sku' => (string) $item['sku'],
                'barcode' => $barcodes[$productId] ?? null,
                'product_name' => (string) $item['product_name'],
                'unit' => $item['unit'],
                'ordered' => 0.0,
                'received' => 0.0,
            ];
            $lines[$productId]['ordered'] += (float) $item['quantity'];
            $lines[$productId]['received'] += (float) ($item['received_quantity'] ?? 0);
        }

        foreach ($lines as &$line) {
            $line['remaining'] = self::qty(max(0.0, $line['ordered'] - $line['received']));
            $line['ordered'] = self::qty($line['ordered']);
            $line['received'] = self::qty($line['received']);
        }

        return $lines;
    }
}
