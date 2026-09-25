<?php

namespace Cloudexus\Model\Sales;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\DocumentNumber;
use Cloudexus\Core\Lang;
use Cloudexus\Core\Sort;

class OrderModel
{
    /** A kategórianév a category_description táblából, alapnyelvi visszaeséssel. */
    private function categoryJoin(): string
    {
        return \Cloudexus\Core\Translation::join('category_description', 'category_id', 'c.id', 'cd');
    }

    private function categoryName(): string
    {
        return \Cloudexus\Core\Translation::pick('cd', 'name');
    }
    public function all(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT o.*, p.name AS partner_name
             FROM orders o
             JOIN partners p ON p.id = o.partner_id
             ORDER BY o.order_date DESC, o.id DESC'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT o.*, p.name AS partner_name,
                    sa.country AS shipping_country, sa.city AS shipping_city, sa.postal_code AS shipping_postal_code,
                    sa.street AS shipping_street, sa.note AS shipping_note,
                    ba.country AS billing_country, ba.city AS billing_city, ba.postal_code AS billing_postal_code,
                    ba.street AS billing_street, ba.note AS billing_note
             FROM orders o
             JOIN partners p ON p.id = o.partner_id
             LEFT JOIN partner_addresses sa ON sa.id = o.shipping_address_id
             LEFT JOIN partner_addresses ba ON ba.id = o.billing_address_id
             WHERE o.id = :id LIMIT 1'
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
            'SELECT oi.*,
                    COALESCE(oi.product_sku, pr.sku) AS sku,
                    COALESCE(oi.product_name, ' . \Cloudexus\Core\Translation::pick('pd', 'name') . ') AS product_name,
                    COALESCE(oi.unit_code, un.code) AS unit
             FROM order_items oi
             JOIN products pr ON pr.id = oi.product_id
             LEFT JOIN units un ON un.id = pr.unit_id
             ' . \Cloudexus\Core\Translation::join('product_description', 'product_id', 'pr.id', 'pd') . '
             WHERE oi.order_id = :order_id'
        );
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetchAll();
    }

    /**
     * Daily order count/value for the last $days days (including today), oldest first.
     * Cancelled orders are excluded.
     */
    public function dailyTotals(int $days = 10): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT order_date, COUNT(*) AS order_count, COALESCE(SUM(total_amount), 0) AS total_value
             FROM orders
             WHERE status != 'cancelled' AND order_date >= :from
             GROUP BY order_date"
        );
        $stmt->execute(['from' => date('Y-m-d', strtotime("-$days days"))]);
        $rows = array_column($stmt->fetchAll(), null, 'order_date');

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $result[] = [
                'date' => $date,
                'order_count' => (int) ($rows[$date]['order_count'] ?? 0),
                'total_value' => (float) ($rows[$date]['total_value'] ?? 0),
            ];
        }

        return $result;
    }

    /** Sortable columns of the list (see Sort): key => SQL expression. */
    public const SORTS = [
        'number' => 'o.order_number',
        'partner' => 'partner_name',
        'date' => 'o.order_date',
        'status' => 'o.status',
        'total' => 'o.total_amount',
    ];

    /** Filters: q (order_number), partner_id, status, date_from, date_to. */
    public function paginate(array $filters, \Cloudexus\Core\Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = 'o.order_number LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['partner_id'])) {
            $where[] = 'o.partner_id = :partner_id';
            $params['partner_id'] = (int) $filters['partner_id'];
        }
        if ($filters['status'] !== '') {
            $where[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'o.order_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'o.order_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'o.updated_at >= :updated_since';
            $params['updated_since'] = $filters['updated_since'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM orders o $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT o.*, p.name AS partner_name
             FROM orders o
             JOIN partners p ON p.id = o.partner_id
             $whereSql
             ORDER BY " . Sort::orderBy(self::SORTS, 'o.order_date DESC, o.id DESC') . "
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Top product categories by ordered value in the last $days days. */
    public function topCategories(int $days = 30, int $limit = 6): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT c.id AS category_id,
                    COALESCE({$this->categoryName()}, :uncategorized) AS name,
                    SUM(oi.line_total) AS value
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id AND o.status != 'cancelled'
             JOIN products p ON p.id = oi.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             {$this->categoryJoin()}
             WHERE o.order_date >= :from
             GROUP BY c.id, {$this->categoryName()}
             ORDER BY value DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute([
            'from' => date('Y-m-d', strtotime("-$days days")),
            'uncategorized' => Lang::get('dashboard.uncategorized'),
        ]);

        return $stmt->fetchAll();
    }

    /** A várható következő sorszám, az űrlapon tájékoztatásnak — a valódit a mentés kapja. */
    public function nextOrderNumber(): string
    {
        return DocumentNumber::preview('order');
    }

    /**
     * @param array $items List of ['product_id' => int, 'quantity' => float, 'unit_price' => float]
     */
    public function create(array $data, array $items): int
    {
        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();

        try {
            $number = DocumentNumber::take('order', (string) ($data['order_date'] ?? ''));
            $shippingCost = (float) ($data['shipping_cost'] ?? 0);
            $paymentCost = (float) ($data['payment_cost'] ?? 0);
            $total = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items)) + $shippingCost + $paymentCost;

            $stmt = $pdo->prepare(
                'INSERT INTO orders (order_number, partner_id, shipping_address_id, billing_address_id, status, order_date, total_amount, shipping_cost, payment_cost, created_by, created_at)
                 VALUES (:order_number, :partner_id, :shipping_address_id, :billing_address_id, :status, :order_date, :total_amount, :shipping_cost, :payment_cost, :created_by, NOW())'
            );
            $stmt->execute([
                'order_number' => $number,
                'partner_id' => $data['partner_id'],
                'shipping_address_id' => $data['shipping_address_id'] ?: null,
                'billing_address_id' => $data['billing_address_id'] ?: null,
                'status' => $data['status'],
                'order_date' => $data['order_date'],
                'total_amount' => $total,
                'shipping_cost' => $shippingCost,
                'payment_cost' => $paymentCost,
                'created_by' => $data['created_by'] ?: null,
            ]);

            $orderId = (int) $pdo->lastInsertId();
            $this->insertItems($orderId, $items);

            $pdo->commit();
            \Cloudexus\Core\Webhooks::dispatch('order.created', ['id' => $orderId, 'number' => $number, 'partner_id' => (int) $data['partner_id'], 'status' => $data['status'] ?? 'confirmed', 'total' => round($total, 2)]);

            return $orderId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Full update of an order's fields, and if $items is not null, replaces all
     * line items and recomputes total_amount (items + shipping + payment cost).
     *
     * @param array|null $items Same shape as create(); null leaves items untouched.
     */
    public function update(int $id, array $data, ?array $items = null): void
    {
        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();

        try {
            $shippingCost = (float) ($data['shipping_cost'] ?? 0);
            $paymentCost = (float) ($data['payment_cost'] ?? 0);

            if ($items !== null) {
                $pdo->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $id]);
                $this->insertItems($id, $items);
                $itemsTotal = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
            } else {
                $itemsTotal = (float) $pdo->query('SELECT COALESCE(SUM(line_total), 0) FROM order_items WHERE order_id = ' . (int) $id)->fetchColumn();
            }

            $stmt = $pdo->prepare(
                'UPDATE orders SET partner_id = :partner_id, shipping_address_id = :shipping_address_id,
                    billing_address_id = :billing_address_id, status = :status, order_date = :order_date,
                    total_amount = :total_amount, shipping_cost = :shipping_cost, payment_cost = :payment_cost
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'partner_id' => $data['partner_id'],
                'shipping_address_id' => $data['shipping_address_id'] ?: null,
                'billing_address_id' => $data['billing_address_id'] ?: null,
                'status' => $data['status'],
                'order_date' => $data['order_date'],
                'total_amount' => $itemsTotal + $shippingCost + $paymentCost,
                'shipping_cost' => $shippingCost,
                'payment_cost' => $paymentCost,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Lemondás: csak piszkozat vagy visszaigazolt rendelés mondható le. Egy
     * kiszámlázottat előbb a számla sztornójával kell visszanyitni.
     */
    public function cancel(int $id): bool
    {
        $stmt = DatabaseConnection::get()->prepare(
            "UPDATE orders SET status = 'cancelled' WHERE id = :id AND status IN ('draft', 'confirmed')"
        );
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            return false;
        }
        \Cloudexus\Core\Webhooks::dispatch('order.cancelled', ['id' => $id, 'number' => $this->findById($id)['order_number'] ?? null]);

        return true;
    }

    /** Kiszámlázott, vagy számlához (akár sztornózotthoz) kötött rendelés nem módosul. */
    public function isLocked(int $id): bool
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT status = 'invoiced' OR EXISTS (SELECT 1 FROM invoices WHERE order_id = o.id) FROM orders o WHERE o.id = :id"
        );
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    /** Törlés: csak piszkozat vagy lemondott, és soha nem számlázott rendelés. */
    public function delete(int $id): bool
    {
        $stmt = DatabaseConnection::get()->prepare(
            "DELETE FROM orders WHERE id = :id AND status IN ('draft', 'cancelled')
             AND NOT EXISTS (SELECT 1 FROM invoices WHERE order_id = :id2)"
        );
        $stmt->execute(['id' => $id, 'id2' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function insertItems(int $orderId, array $items): void
    {
        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO order_items
                 (order_id, product_id, product_name, product_sku, unit_code, quantity, unit_price, line_total)
             SELECT :order_id, p.id, COALESCE(d.name, p.sku), p.sku, u.code,
                    :quantity, :unit_price, :line_total
             FROM products p
             LEFT JOIN product_description d ON d.product_id = p.id
                   AND d.language_id = ' . \Cloudexus\Core\Language::defaultId() . '
             LEFT JOIN units u ON u.id = p.unit_id
             WHERE p.id = :product_id'
        );

        foreach ($items as $item) {
            $stmt->execute([
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['quantity'] * $item['unit_price'],
            ]);
        }
    }
}
