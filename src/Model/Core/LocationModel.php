<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;

class LocationModel
{
    /** Filters: q (code/name), code (exact, e.g. a scanned shelf label), warehouse_id, status. */
    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if (($filters['code'] ?? '') !== '') {
            $where[] = 'l.code = :code';
            $params['code'] = $filters['code'];
        }
        if ($filters['q'] !== '') {
            $where[] = '(l.code LIKE :q1 OR l.name LIKE :q2)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['warehouse_id'])) {
            $where[] = 'l.warehouse_id = :wid';
            $params['wid'] = (int) $filters['warehouse_id'];
        }
        if ($filters['status'] !== '') {
            $where[] = 'l.is_active = :active';
            $params['active'] = $filters['status'] === 'active' ? 1 : 0;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM warehouse_locations l $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT l.*, w.name AS warehouse_name,
                    COALESCE(s.qty, 0) AS stock_qty,
                    COALESCE(s.product_count, 0) AS product_count
             FROM warehouse_locations l
             JOIN warehouses w ON w.id = l.warehouse_id
             LEFT JOIN (
                 SELECT location_id,
                        SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END) AS qty,
                        COUNT(DISTINCT product_id) AS product_count
                 FROM stock_movements WHERE location_id IS NOT NULL GROUP BY location_id
             ) s ON s.location_id = l.id
             $whereSql
             ORDER BY w.name ASC, l.code ASC
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM warehouse_locations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** All active locations with their warehouse, for warehouse-filtered pickers. */
    public function activeWithWarehouse(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT l.id, l.code, l.warehouse_id, w.name AS warehouse_name
             FROM warehouse_locations l
             JOIN warehouses w ON w.id = l.warehouse_id
             WHERE l.is_active = 1
             ORDER BY w.name ASC, l.code ASC'
        )->fetchAll();
    }

    public function codeExists(int $warehouseId, string $code, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM warehouse_locations WHERE warehouse_id = :wid AND code = :code';
        $params = ['wid' => $warehouseId, 'code' => $code];
        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeId;
        }
        $stmt = DatabaseConnection::get()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(array $data): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO warehouse_locations (warehouse_id, code, name, is_active, created_at)
             VALUES (:wid, :code, :name, :active, NOW())'
        );
        $stmt->execute([
            'wid' => $data['warehouse_id'],
            'code' => $data['code'],
            'name' => $data['name'] ?: null,
            'active' => $data['is_active'],
        ]);
        return (int) DatabaseConnection::get()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE warehouse_locations SET warehouse_id = :wid, code = :code, name = :name, is_active = :active WHERE id = :id'
        )->execute([
            'id' => $id,
            'wid' => $data['warehouse_id'],
            'code' => $data['code'],
            'name' => $data['name'] ?: null,
            'active' => $data['is_active'],
        ]);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM warehouse_locations WHERE id = :id')->execute(['id' => $id]);
    }
}
