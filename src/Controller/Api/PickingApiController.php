<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Sales\OrderModel;

/**
 * Picking a customer order on the PDA: the confirmed orders waiting to be
 * picked, one order's lines with the shelves of a warehouse that hold them,
 * and the pick itself — which books the goods out of the warehouse, from the
 * shelves they were taken from, and marks the order picked.
 *
 * An order is picked in full or not at all: the quantities sent have to add
 * up, per product, to what the order asks for. A picked order's invoice books
 * no stock (see InvoiceModel::create()): the goods left at the pick. See
 * 25_picking_and_receiving.sql.
 */
class PickingApiController extends StockApiController
{
    /** Confirmed orders not yet picked or invoiced, oldest first. */
    public function index(): void
    {
        $this->requireUserPermission(Permissions::STOCK_MOVE);

        $rows = DatabaseConnection::get()->query(
            "SELECT o.id, o.order_number, o.order_date, o.partner_id, p.name AS partner_name,
                    COUNT(oi.id) AS line_count, COALESCE(SUM(oi.quantity), 0) AS total_quantity
             FROM orders o
             JOIN partners p ON p.id = o.partner_id
             LEFT JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'confirmed' AND o.picked_at IS NULL
             GROUP BY o.id
             ORDER BY o.order_date ASC, o.id ASC
             LIMIT 200"
        )->fetchAll();

        $this->json(['data' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'order_number' => $row['order_number'],
            'order_date' => $row['order_date'],
            'partner_id' => (int) $row['partner_id'],
            'partner_name' => $row['partner_name'],
            'line_count' => (int) $row['line_count'],
            'total_quantity' => number_format((float) $row['total_quantity'], 3, '.', ''),
        ], $rows)]);
    }

    /**
     * One order to pick: its lines per product, each with the shelves of the
     * warehouse (?warehouse_id=) that hold it, the lines ordered by their
     * first shelf — the walk through the warehouse.
     */
    public function show(int $id): void
    {
        $this->requireUserPermission(Permissions::STOCK_MOVE);

        $order = $this->pickableOrder($id);
        $warehouse = $this->activeWarehouse($_GET['warehouse_id'] ?? null, 'warehouse_id');
        $lines = $this->lines($order);
        $shelves = $this->movements->shelvesFor((int) $warehouse['id'], array_keys($lines));

        $out = [];
        foreach ($lines as $productId => $line) {
            $out[] = $line + [
                'in_warehouse' => self::qty($this->movements->availableQuantity($productId, (int) $warehouse['id'])),
                'shelves' => $shelves[$productId] ?? [],
            ];
        }
        // Lines without a shelf (stock booked without one, or none at all) last.
        usort($out, static fn(array $a, array $b): int => [$a['shelves'] === [], $a['shelves'][0]['location_code'] ?? '', $a['sku']]
            <=> [$b['shelves'] === [], $b['shelves'][0]['location_code'] ?? '', $b['sku']]);

        $this->json(['data' => [
            'id' => (int) $order['id'],
            'order_number' => $order['order_number'],
            'order_date' => $order['order_date'],
            'partner_name' => $order['partner_name'],
            'warehouse' => $this->warehouseFields($warehouse),
            'lines' => $out,
        ]]);
    }

    /**
     * {warehouse_id, items: [{product_id, quantity, location_id}]}: books the
     * picked goods out, shelf by shelf, and marks the order picked.
     */
    public function pick(int $id): void
    {
        $this->requireUserPermission(Permissions::STOCK_MOVE);

        $body = $this->body();
        $order = $this->pickableOrder($id);
        $warehouse = $this->activeWarehouse($body['warehouse_id'] ?? null, 'warehouse_id');
        $ordered = $this->lines($order);

        $lines = [];
        $picked = [];
        foreach ($this->items($body) as $i => $item) {
            $productId = (int) $item['product']['id'];
            if (!isset($ordered[$productId])) {
                $this->error('Some items are invalid.', 422, [['index' => $i, 'message' => 'This product is not on the order.']]);
            }
            $location = $this->location($warehouse, $item['raw']['location_id'] ?? null, "items[$i].location_id");

            $key = $productId . ':' . ($location['id'] ?? '');
            $lines[$key] ??= ['product' => $item['product'], 'location' => $location, 'quantity' => 0.0];
            $lines[$key]['quantity'] += $item['quantity'];
            $picked[$productId] = ($picked[$productId] ?? 0.0) + $item['quantity'];
        }

        // All of it, or nothing: a part-picked order would leave its invoice
        // not knowing what had left the warehouse.
        $differences = [];
        foreach ($ordered as $productId => $line) {
            if (round($picked[$productId] ?? 0.0, 3) !== round((float) $line['quantity'], 3)) {
                $differences[] = ['product_id' => $productId, 'sku' => $line['sku'], 'product_name' => $line['product_name'],
                    'ordered' => $line['quantity'], 'picked' => self::qty($picked[$productId] ?? 0.0)];
            }
        }
        if ($differences) {
            $this->error('The picked quantities do not match the order. Pick every line in full, or change the order first.', 422, $differences);
        }

        $note = 'Kiszedés: ' . $order['order_number'];

        $this->inTransaction(function () use ($id, $order, $warehouse, $lines, $note): array {
            $this->movements->lockWarehouses([$warehouse['id']]);

            // Picked by somebody else, or invoiced, while this one walked.
            $lock = DatabaseConnection::get()->prepare('SELECT status, picked_at FROM orders WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            $now = $lock->fetch();
            if (!$now || $now['status'] !== 'confirmed' || $now['picked_at'] !== null) {
                return [409, ['error' => ['status' => 409, 'message' => 'This order was picked or invoiced in the meantime.']]];
            }

            $shortages = $this->shortages((int) $warehouse['id'], $lines);
            if ($shortages) {
                return $this->shortageError($warehouse, $shortages);
            }

            $movements = [];
            foreach ($lines as $line) {
                $movementId = $this->movements->create([
                    'warehouse_id' => (int) $warehouse['id'],
                    'location_id' => $line['location']['id'] ?? null,
                    'product_id' => (int) $line['product']['id'],
                    'type' => 'out',
                    'quantity' => $line['quantity'],
                    'note' => $note,
                    'created_by' => (int) $this->user['id'],
                ]);
                $movements[] = ['id' => $movementId] + $this->productFields($line['product']) + [
                    'location_id' => $line['location']['id'] ?? null,
                    'location_code' => $line['location']['code'] ?? null,
                    'quantity' => self::qty($line['quantity']),
                ];
            }

            DatabaseConnection::get()->prepare(
                'UPDATE orders SET picked_at = NOW(), picked_by = :user, picked_warehouse_id = :warehouse WHERE id = :id'
            )->execute(['user' => (int) $this->user['id'], 'warehouse' => (int) $warehouse['id'], 'id' => $id]);

            return [201, ['data' => [
                'order' => ['id' => (int) $order['id'], 'order_number' => $order['order_number']],
                'warehouse' => $this->warehouseFields($warehouse),
                'note' => $note,
                'created_by' => $this->createdBy(),
                'movements' => $movements,
            ]]];
        });
    }

    /** A confirmed order not yet picked; 404 or 409 otherwise. */
    private function pickableOrder(int $id): array
    {
        $order = (new OrderModel())->findById($id);
        if (!$order) {
            $this->error('Order not found.', 404);
        }
        if ($order['status'] !== 'confirmed' || $order['picked_at'] !== null) {
            $this->error('Only a confirmed order that has not been picked yet can be picked.', 409);
        }

        return $order;
    }

    /**
     * The order's lines added up per product, with what a scanner matches:
     * the barcode and the SKU.
     *
     * @return array<int, array{product_id: int, sku: string, barcode: ?string, product_name: string, unit: ?string, quantity: string}>
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
                'quantity' => '0.000',
            ];
            $lines[$productId]['quantity'] = self::qty((float) $lines[$productId]['quantity'] + (float) $item['quantity']);
        }

        return $lines;
    }
}
