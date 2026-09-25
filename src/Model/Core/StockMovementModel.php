<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Sort;

class StockMovementModel
{
    /**
     * A terméknév a product_description táblából, alapnyelvi visszaeséssel.
     * Metódusként, mert az itteni lekérdezések dupla idézőjelű sztringek, ahol
     * a statikus hívás csak {$this->...} alakban interpolálható.
     */
    private function descJoin(): string
    {
        return \Cloudexus\Core\Translation::join('product_description', 'product_id', 'p.id', 'pd');
    }

    private function nameSelect(): string
    {
        return \Cloudexus\Core\Translation::select('pd', 'name', 'product_name');
    }

    /** Sortable columns of the stock-in and stock-out lists (see Sort): key => SQL expression. */
    public const MOVEMENT_SORTS = [
        'date' => 'm.created_at',
        'warehouse' => 'warehouse_name',
        'location' => 'location_code',
        'product' => 'p.sku',
        'quantity' => 'm.quantity',
        'created_by' => 'created_by_name',
    ];

    /**
     * Filtered, paginated movement list for one movement type ('in'|'out').
     * Filters: warehouse_id, q (product sku/name), date_from, date_to.
     */
    public function paginateByType(string $type, array $filters, Paginator $pager): array
    {
        $where = ['m.type = :type'];
        $params = ['type' => $type];

        if (!empty($filters['warehouse_id'])) {
            $where[] = 'm.warehouse_id = :warehouse_id';
            $params['warehouse_id'] = (int) $filters['warehouse_id'];
        }
        if ($filters['q'] !== '') {
            $where[] = '(p.sku LIKE :q1 OR ' . \Cloudexus\Core\Translation::pick('pd', 'name') . ' LIKE :q2)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'm.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'm.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $count = DatabaseConnection::get()->prepare(
            "SELECT COUNT(*) FROM stock_movements m JOIN products p ON p.id = m.product_id
             {$this->descJoin()} $whereSql"
        );
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT m.*, p.sku, {$this->nameSelect()}, un.code AS unit, w.name AS warehouse_name,
                    l.code AS location_code, u.full_name AS created_by_name
             FROM stock_movements m
             JOIN products p ON p.id = m.product_id
             {$this->descJoin()}
             LEFT JOIN units un ON un.id = p.unit_id
             JOIN warehouses w ON w.id = m.warehouse_id
             LEFT JOIN warehouse_locations l ON l.id = m.location_id
             LEFT JOIN users u ON u.id = m.created_by
             $whereSql
             ORDER BY " . Sort::orderBy(self::MOVEMENT_SORTS, 'm.created_at DESC, m.id DESC') . "
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param array $data Accepts an optional 'location_id' (warehouse shelf) and
     *                    'created_at' (Y-m-d H:i:s) to backdate the movement.
     */
    public function create(array $data): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO stock_movements (warehouse_id, location_id, product_id, type, quantity, note, created_by, created_at)
             VALUES (:warehouse_id, :location_id, :product_id, :type, :quantity, :note, :created_by, :created_at)'
        );
        $stmt->execute([
            'warehouse_id' => $data['warehouse_id'],
            'location_id' => !empty($data['location_id']) ? (int) $data['location_id'] : null,
            'product_id' => $data['product_id'],
            'type' => $data['type'],
            'quantity' => $data['quantity'],
            'note' => $data['note'] ?: null,
            'created_by' => $data['created_by'] ?: null,
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    /**
     * Runs $work in a transaction that holds the given warehouses' row locks:
     * a stock check and the booking after it see the same stock, so two
     * parallel stock-outs cannot both pass the check and oversell. The same
     * lock the mobile API takes, so web and PDA bookings queue up too.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function locked(array $warehouseIds, callable $work): mixed
    {
        $pdo = DatabaseConnection::get();
        // Every read sees the latest committed stock, not a snapshot from before the lock.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();

        try {
            $this->lockWarehouses($warehouseIds);
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Throws StockShortage when a warehouse cannot cover the quantities. Call
     * it inside locked(), before booking.
     *
     * @param array<int, float> $needed product id => quantity
     */
    public function assertAvailable(int $warehouseId, array $needed): void
    {
        $shortages = [];
        foreach ($needed as $productId => $quantity) {
            $available = $this->availableQuantity((int) $productId, $warehouseId);
            // Quantities have at most 3 decimals; the margin absorbs float noise.
            if ($quantity > $available + 0.0005) {
                $shortages[(int) $productId] = ['available' => $available, 'requested' => (float) $quantity];
            }
        }

        if ($shortages) {
            throw new StockShortage($shortages);
        }
    }

    /**
     * Books a warehouse-to-warehouse transfer as an out + in movement pair,
     * atomically; inside locked() it joins that transaction.
     */
    public function transfer(int $fromWarehouseId, int $toWarehouseId, int $productId, float $quantity, string $note, ?int $userId, ?int $fromLocationId = null, ?int $toLocationId = null): void
    {
        $pdo = DatabaseConnection::get();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO stock_movements (warehouse_id, location_id, product_id, type, quantity, note, created_by, created_at)
                 VALUES (:warehouse_id, :location_id, :product_id, :type, :quantity, :note, :created_by, NOW())'
            );

            foreach ([['out', $fromWarehouseId, $fromLocationId], ['in', $toWarehouseId, $toLocationId]] as [$type, $warehouseId, $locationId]) {
                $stmt->execute([
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId ?: null,
                    'product_id' => $productId,
                    'type' => $type,
                    'quantity' => $quantity,
                    'note' => $note,
                    'created_by' => $userId,
                ]);
            }

            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Sortable columns of the stock transfer list (see Sort): key => SQL expression. */
    public const TRANSFER_SORTS = [
        'date' => 'm.created_at',
        'direction' => 'm.type',
        'warehouse' => 'warehouse_name',
        'location' => 'location_code',
        'product' => 'p.sku',
        'quantity' => 'm.quantity',
    ];

    /**
     * Filtered, paginated transfer movement legs (identified by their note prefix).
     * Filters: warehouse_id, q (product sku/name), date_from, date_to.
     */
    public function paginateTransfers(array $filters, Paginator $pager): array
    {
        $where = ["m.note LIKE 'Raktárközi átadás%'"];
        $params = [];

        if (!empty($filters['warehouse_id'])) {
            $where[] = 'm.warehouse_id = :warehouse_id';
            $params['warehouse_id'] = (int) $filters['warehouse_id'];
        }
        if ($filters['q'] !== '') {
            $where[] = '(p.sku LIKE :q1 OR ' . \Cloudexus\Core\Translation::pick('pd', 'name') . ' LIKE :q2)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'm.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'm.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $count = DatabaseConnection::get()->prepare(
            "SELECT COUNT(*) FROM stock_movements m JOIN products p ON p.id = m.product_id
             {$this->descJoin()} $whereSql"
        );
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT m.*, p.sku, {$this->nameSelect()}, un.code AS unit, w.name AS warehouse_name,
                    l.code AS location_code, u.full_name AS created_by_name
             FROM stock_movements m
             JOIN products p ON p.id = m.product_id
             {$this->descJoin()}
             LEFT JOIN units un ON un.id = p.unit_id
             JOIN warehouses w ON w.id = m.warehouse_id
             LEFT JOIN warehouse_locations l ON l.id = m.location_id
             LEFT JOIN users u ON u.id = m.created_by
             $whereSql
             ORDER BY " . Sort::orderBy(self::TRANSFER_SORTS, 'm.created_at DESC, m.id DESC') . "
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Row-locks the warehouses until the caller's transaction ends, so two
     * bookings against the same warehouse check stock one after the other
     * instead of both passing the check and overselling. Locked in id order,
     * so two transfers in opposite directions cannot deadlock.
     */
    public function lockWarehouses(array $warehouseIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $warehouseIds)));
        if (!$ids) {
            return;
        }
        sort($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = DatabaseConnection::get()
            ->prepare("SELECT id FROM warehouses WHERE id IN ($placeholders) ORDER BY id FOR UPDATE");
        $stmt->execute($ids);
        $stmt->fetchAll();
    }

    public function availableQuantity(int $productId, int $warehouseId): float
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END)
             FROM stock_movements WHERE product_id = :product_id AND warehouse_id = :warehouse_id"
        );
        $stmt->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);

        return (float) ($stmt->fetchColumn() ?: 0);
    }

    /** Sortable columns of the stock overview list (see Sort): key => SQL expression. */
    public const OVERVIEW_SORTS = [
        'warehouse' => 'warehouse_name',
        'location' => 'location_code',
        'sku' => 'p.sku',
        'product' => 'product_name',
        'quantity' => 'quantity',
    ];

    /**
     * Current stock per warehouse/product, computed as SUM(in) - SUM(out).
     * Filters: warehouse_id, q (product sku/name).
     */
    public function overview(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['warehouse_id'])) {
            $where[] = 'm.warehouse_id = :warehouse_id';
            $params['warehouse_id'] = (int) $filters['warehouse_id'];
        }
        if ($filters['q'] !== '') {
            $where[] = '(p.sku LIKE :q1 OR ' . \Cloudexus\Core\Translation::pick('pd', 'name') . ' LIKE :q2)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }

        if (!empty($filters['location_id'])) {
            $where[] = 'm.location_id = :location_id';
            $params['location_id'] = (int) $filters['location_id'];
        }
        if (!empty($filters['product_id'])) {
            $where[] = 'm.product_id = :product_id';
            $params['product_id'] = (int) $filters['product_id'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Raktár + tárhely + termék bontásban mutatja a készletet, hogy látszódjon,
        // melyik polcon mennyi van (a tárhely nélküli mozgások "—" alatt gyűlnek).
        $baseSql = "SELECT w.id AS warehouse_id, w.name AS warehouse_name,
                           l.id AS location_id, l.code AS location_code,
                           p.id AS product_id, p.sku, {$this->nameSelect()}, un.code AS unit,
                           SUM(CASE WHEN m.type = 'in' THEN m.quantity ELSE -m.quantity END) AS quantity
                    FROM stock_movements m
                    JOIN warehouses w ON w.id = m.warehouse_id
                    JOIN products p ON p.id = m.product_id
             {$this->descJoin()}
                    LEFT JOIN units un ON un.id = p.unit_id
                    LEFT JOIN warehouse_locations l ON l.id = m.location_id
                    $whereSql
                    GROUP BY w.id, l.id, p.id
                    HAVING quantity != 0";

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM ($baseSql) t");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "$baseSql ORDER BY " . Sort::orderBy(self::OVERVIEW_SORTS, 'warehouse_name ASC, location_code ASC, product_name ASC') . " LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Book stock of every active product in one warehouse (products with no
     * movement show 0), for the stocktaking sheet.
     */
    public function stockSheet(int $warehouseId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT p.id AS product_id, p.sku, {$this->nameSelect()}, un.code AS unit,
                    COALESCE(m.qty, 0) AS book_quantity
             FROM products p
             {$this->descJoin()}
             LEFT JOIN units un ON un.id = p.unit_id
             LEFT JOIN (
                 SELECT product_id, SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END) AS qty
                 FROM stock_movements WHERE warehouse_id = :wid GROUP BY product_id
             ) m ON m.product_id = p.id
             WHERE p.is_active = 1
             ORDER BY product_name ASC"
        );
        $stmt->execute(['wid' => $warehouseId]);

        return $stmt->fetchAll();
    }

    /**
     * One product's current stock per warehouse and location (non-zero rows only),
     * for the mobile app's scan result. Movements without a location sum up under
     * location_id = null.
     */
    public function stockForProduct(int $productId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT w.id AS warehouse_id, w.name AS warehouse_name,
                    l.id AS location_id, l.code AS location_code,
                    SUM(CASE WHEN m.type = 'in' THEN m.quantity ELSE -m.quantity END) AS quantity
             FROM stock_movements m
             JOIN warehouses w ON w.id = m.warehouse_id
             LEFT JOIN warehouse_locations l ON l.id = m.location_id
             WHERE m.product_id = :product_id
             GROUP BY w.id, l.id
             HAVING quantity != 0
             ORDER BY w.name ASC, l.code IS NULL ASC, l.code ASC"
        );
        $stmt->execute(['product_id' => $productId]);

        return $stmt->fetchAll();
    }

    public function totalQuantityForProduct(int $productId): float
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END)
             FROM stock_movements WHERE product_id = :product_id"
        );
        $stmt->execute(['product_id' => $productId]);

        return (float) ($stmt->fetchColumn() ?: 0);
    }
}
