<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Sort;

class CurrencyModel
{
    public function all(): array
    {
        return DatabaseConnection::get()
            ->query('SELECT * FROM currencies ORDER BY code ASC')
            ->fetchAll();
    }

    /**
     * Sortable columns of the list (see Sort): key => SQL expression. Which one is
     * primary lives in settings, not on the row, so that column is not sortable.
     */
    public const SORTS = [
        'code' => 'code',
        'title' => 'title',
        'symbol' => 'symbol',
        'value' => 'value',
    ];

    /** Filters: q (code/title). */
    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(code LIKE :q1 OR title LIKE :q2)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'updated_at >= :updated_since';
            $params['updated_since'] = $filters['updated_since'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM currencies $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT * FROM currencies $whereSql ORDER BY " . Sort::orderBy(self::SORTS, 'code ASC') . " LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM currencies WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM currencies WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => strtoupper($code)]);
        return $stmt->fetch() ?: null;
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM currencies WHERE code = :code';
        $params = ['code' => strtoupper($code)];
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
            'INSERT INTO currencies (title, code, symbol, value) VALUES (:title, :code, :symbol, :value)'
        )->execute([
            'title' => $data['title'],
            'code' => strtoupper($data['code']),
            'symbol' => ($data['symbol'] ?? '') ?: null,
            'value' => $data['value'],
        ]);

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE currencies SET title = :title, code = :code, symbol = :symbol, value = :value WHERE id = :id'
        )->execute([
            'id' => $id,
            'title' => $data['title'],
            'code' => strtoupper($data['code']),
            'symbol' => ($data['symbol'] ?? '') ?: null,
            'value' => $data['value'],
        ]);
    }

    /** Sets a single rate by currency code; used by the MNB sync. */
    public function updateValueByCode(string $code, float $value): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE currencies SET value = :value WHERE code = :code'
        )->execute(['value' => $value, 'code' => strtoupper($code)]);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM currencies WHERE id = :id')->execute(['id' => $id]);
    }
}
