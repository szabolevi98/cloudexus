<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Sort;

class LanguageModel
{
    public function all(): array
    {
        return DatabaseConnection::get()
            ->query('SELECT * FROM languages ORDER BY sort_order ASC, name ASC')
            ->fetchAll();
    }

    /** Csak az aktív nyelvek — a nyelvváltó és a fordítás-feloldás ezt használja. */
    public function activeList(): array
    {
        return DatabaseConnection::get()
            ->query('SELECT * FROM languages WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')
            ->fetchAll();
    }

    /**
     * Sortable columns of the list (see Sort): key => SQL expression. The default
     * language lives in settings, not on the row, so that column is not sortable.
     */
    public const SORTS = [
        'code' => 'code',
        'name' => 'name',
        'sort_order' => 'sort_order',
        'status' => 'is_active',
    ];

    /** Filters: q (code/name). */
    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(code LIKE :q1 OR name LIKE :q2)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'updated_at >= :updated_since';
            $params['updated_since'] = $filters['updated_since'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM languages $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT * FROM languages $whereSql ORDER BY " . Sort::orderBy(self::SORTS, 'sort_order ASC, name ASC') . " LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM languages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM languages WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => strtolower($code)]);
        return $stmt->fetch() ?: null;
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM languages WHERE code = :code';
        $params = ['code' => strtolower($code)];
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
            'INSERT INTO languages (name, code, sort_order, is_active) VALUES (:name, :code, :sort, :active)'
        )->execute([
            'name' => $data['name'],
            'code' => strtolower($data['code']),
            'sort' => $data['sort_order'] ?? 0,
            'active' => $data['is_active'] ?? 1,
        ]);

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE languages SET name = :name, code = :code, sort_order = :sort, is_active = :active WHERE id = :id'
        )->execute([
            'id' => $id,
            'name' => $data['name'],
            'code' => strtolower($data['code']),
            'sort' => $data['sort_order'] ?? 0,
            'active' => $data['is_active'] ?? 1,
        ]);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM languages WHERE id = :id')->execute(['id' => $id]);
    }

    /** Hány fordítás-sor tartozik a nyelvhez — a törlés előtti figyelmeztetéshez. */
    public function translationCount(int $id): int
    {
        $total = 0;
        foreach (['product_description', 'category_description', 'unit_description', 'parameter_description', 'product_parameters'] as $table) {
            $stmt = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM `$table` WHERE language_id = :id");
            $stmt->execute(['id' => $id]);
            $total += (int) $stmt->fetchColumn();
        }

        return $total;
    }
}
