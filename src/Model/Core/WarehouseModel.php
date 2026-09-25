<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Sort;

class WarehouseModel
{
    public function all(): array
    {
        return DatabaseConnection::get()->query('SELECT * FROM warehouses ORDER BY name ASC')->fetchAll();
    }

    /** Sortable columns of the list (see Sort): key => SQL expression. */
    public const SORTS = [
        'name' => 'name',
        'address' => 'address',
        'status' => 'is_active',
    ];

    /** Filters: q (name/address), status. */
    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(name LIKE :q1 OR address LIKE :q2)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }
        if ($filters['status'] !== '') {
            $where[] = 'is_active = :active';
            $params['active'] = $filters['status'] === 'active' ? 1 : 0;
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'updated_at >= :updated_since';
            $params['updated_since'] = $filters['updated_since'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM warehouses $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT * FROM warehouses $whereSql ORDER BY " . Sort::orderBy(self::SORTS, 'name ASC') . " LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function activeList(): array
    {
        return DatabaseConnection::get()
            ->query('SELECT * FROM warehouses WHERE is_active = 1 ORDER BY name ASC')
            ->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM warehouses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO warehouses (name, address, is_active, created_at) VALUES (:name, :address, :is_active, NOW())'
        );
        $stmt->execute([
            'name' => $data['name'],
            'address' => $data['address'] ?: null,
            'is_active' => $data['is_active'],
        ]);

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = DatabaseConnection::get()->prepare(
            'UPDATE warehouses SET name = :name, address = :address, is_active = :is_active WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'address' => $data['address'] ?: null,
            'is_active' => $data['is_active'],
        ]);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM warehouses WHERE id = :id')->execute(['id' => $id]);
    }
}
