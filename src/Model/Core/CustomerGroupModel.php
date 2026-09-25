<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Sort;

class CustomerGroupModel
{
    public function all(): array
    {
        return DatabaseConnection::get()->query(
            "SELECT g.*, (SELECT COUNT(*) FROM partners p WHERE p.customer_group_id = g.id) AS partner_count
             FROM customer_groups g ORDER BY g.name ASC"
        )->fetchAll();
    }

    /** Sortable columns of the list (see Sort): key => SQL expression. */
    public const SORTS = [
        'name' => 'g.name',
        'description' => 'g.description',
        'partners' => 'partner_count',
    ];

    /** Filters: q (name/description). */
    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(g.name LIKE :q1 OR g.description LIKE :q2)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'g.updated_at >= :updated_since';
            $params['updated_since'] = $filters['updated_since'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM customer_groups g $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT g.*, (SELECT COUNT(*) FROM partners p WHERE p.customer_group_id = g.id) AS partner_count
             FROM customer_groups g $whereSql
             ORDER BY " . Sort::orderBy(self::SORTS, 'g.name ASC') . "
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM customer_groups WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function exists(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM customer_groups WHERE name = :name';
        $params = ['name' => $name];
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
        DatabaseConnection::get()->prepare(
            'INSERT INTO customer_groups (name, description, created_at) VALUES (:name, :description, NOW())'
        )->execute(['name' => $data['name'], 'description' => $data['description'] ?: null]);

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE customer_groups SET name = :name, description = :description WHERE id = :id'
        )->execute(['id' => $id, 'name' => $data['name'], 'description' => $data['description'] ?: null]);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM customer_groups WHERE id = :id')->execute(['id' => $id]);
    }
}
