<?php

namespace Cloudexus\Model\Purchasing;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\DocumentNumber;

class PurchaseOrderModel
{
    public function all(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT po.*, p.name AS partner_name
             FROM purchase_orders po
             JOIN partners p ON p.id = po.partner_id
             ORDER BY po.order_date DESC, po.id DESC'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT po.*, p.name AS partner_name
             FROM purchase_orders po
             JOIN partners p ON p.id = po.partner_id
             WHERE po.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $order = $stmt->fetch();

        if (!$order) {
            return null;
        }

        $order['items'] = $this->items($id);

        return $order;
    }

    public function items(int $orderId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT poi.*,
                    COALESCE(poi.product_sku, pr.sku) AS sku,
                    COALESCE(poi.product_name, ' . \Cloudexus\Core\Translation::pick('pd', 'name') . ') AS product_name,
                    COALESCE(poi.unit_code, un.code) AS unit
             FROM purchase_order_items poi
             JOIN products pr ON pr.id = poi.product_id
             LEFT JOIN units un ON un.id = pr.unit_id
             ' . \Cloudexus\Core\Translation::join('product_description', 'product_id', 'pr.id', 'pd') . '
             WHERE poi.purchase_order_id = :order_id'
        );
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetchAll();
    }

    /** Filters: q (po_number), partner_id, status, date_from, date_to. */
    public function paginate(array $filters, \Cloudexus\Core\Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = 'po.po_number LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['partner_id'])) {
            $where[] = 'po.partner_id = :partner_id';
            $params['partner_id'] = (int) $filters['partner_id'];
        }
        if ($filters['status'] !== '') {
            $where[] = 'po.status = :status';
            $params['status'] = $filters['status'];
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'po.order_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'po.order_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM purchase_orders po $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT po.*, p.name AS partner_name
             FROM purchase_orders po
             JOIN partners p ON p.id = po.partner_id
             $whereSql
             ORDER BY po.order_date DESC, po.id DESC
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** A várható következő sorszám, az űrlapon tájékoztatásnak — a valódit a mentés kapja. */
    public function nextPoNumber(): string
    {
        return DocumentNumber::preview('purchase_order');
    }

    public function create(array $data, array $items): int
    {
        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();

        try {
            $number = DocumentNumber::take('purchase_order', (string) ($data['order_date'] ?? ''));
            $total = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));

            $stmt = $pdo->prepare(
                'INSERT INTO purchase_orders (po_number, partner_id, status, order_date, total_amount, created_by, created_at)
                 VALUES (:po_number, :partner_id, :status, :order_date, :total_amount, :created_by, NOW())'
            );
            $stmt->execute([
                'po_number' => $number,
                'partner_id' => $data['partner_id'],
                'status' => $data['status'],
                'order_date' => $data['order_date'],
                'total_amount' => $total,
                'created_by' => $data['created_by'] ?: null,
            ]);

            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO purchase_order_items
                     (purchase_order_id, product_id, product_name, product_sku, unit_code, quantity, unit_price, line_total)
                 SELECT :order_id, p.id, COALESCE(d.name, p.sku), p.sku, u.code,
                        :quantity, :unit_price, :line_total
                 FROM products p
                 LEFT JOIN product_description d ON d.product_id = p.id
                       AND d.language_id = ' . \Cloudexus\Core\Language::defaultId() . '
                 LEFT JOIN units u ON u.id = p.unit_id
                 WHERE p.id = :product_id'
            );
            foreach ($items as $item) {
                $itemStmt->execute([
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['quantity'] * $item['unit_price'],
                ]);
            }

            $pdo->commit();

            return $orderId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Lemondás: csak piszkozat vagy visszaigazolt, még nem számlázott rendelés. */
    public function cancel(int $id): bool
    {
        $stmt = DatabaseConnection::get()->prepare(
            "UPDATE purchase_orders SET status = 'cancelled' WHERE id = :id AND status IN ('draft', 'confirmed')"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** Törlés: csak piszkozat vagy lemondott rendelés, amelyhez nem érkezett számla. */
    public function delete(int $id): bool
    {
        $stmt = DatabaseConnection::get()->prepare(
            "DELETE FROM purchase_orders WHERE id = :id AND status IN ('draft', 'cancelled')
             AND NOT EXISTS (SELECT 1 FROM incoming_invoices WHERE purchase_order_id = :id2)"
        );
        $stmt->execute(['id' => $id, 'id2' => $id]);

        return $stmt->rowCount() > 0;
    }
}
