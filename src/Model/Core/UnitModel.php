<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Language;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Sort;
use Cloudexus\Core\Translation;

class UnitModel
{
    /** A mértékegység neve a unit_description táblából (a kód nem fordítható). */
    private static function descJoin(): string
    {
        return Translation::join('unit_description', 'unit_id', 'u.id', 'ud');
    }

    private static function descSelect(): string
    {
        return Translation::select('ud', 'name');
    }

    public function all(): array
    {
        return DatabaseConnection::get()
            ->query('SELECT u.*, ' . self::descSelect() . '
                     FROM units u
                     ' . self::descJoin() . '
                     ORDER BY u.sort_order ASC, name ASC')
            ->fetchAll();
    }

    /** Sortable columns of the list (see Sort): key => SQL expression. */
    public const SORTS = [
        'code' => 'u.code',
        'name' => 'name',
        'sort_order' => 'u.sort_order',
    ];

    /** Filters: q (code/name). */
    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(u.code LIKE :q1 OR ' . Translation::pick('ud', 'name') . ' LIKE :q2)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'u.updated_at >= :updated_since';
            $params['updated_since'] = $filters['updated_since'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // A COUNT is megkapja a fordítás-joinokat, különben a q szűrő eltörik.
        $count = DatabaseConnection::get()->prepare(
            'SELECT COUNT(*) FROM units u
             ' . self::descJoin() . "
             $whereSql"
        );
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            'SELECT u.*, ' . self::descSelect() . '
             FROM units u
             ' . self::descJoin() . "
             $whereSql
             ORDER BY " . Sort::orderBy(self::SORTS, 'u.sort_order ASC, name ASC') . "
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT u.*, ' . self::descSelect() . '
             FROM units u
             ' . self::descJoin() . '
             WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM units WHERE code = :code';
        $params = ['code' => $code];
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
            'INSERT INTO units (code, sort_order) VALUES (:code, :sort)'
        )->execute(['code' => $data['code'], 'sort' => $data['sort_order'] ?? 0]);

        $id = (int) DatabaseConnection::get()->lastInsertId();
        $this->saveDescriptions($id, $data);

        return $id;
    }

    public function update(int $id, array $data): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE units SET code = :code, sort_order = :sort WHERE id = :id'
        )->execute(['id' => $id, 'code' => $data['code'], 'sort' => $data['sort_order'] ?? 0]);

        $this->saveDescriptions($id, $data);
    }

    /**
     * A mértékegység neve nyelvenként. A $data['name'] nyelv szerint kulcsolt
     * tömb (nyelv id => név); egyetlen string is elfogadott, az az alapnyelv
     * sorába kerül.
     */
    public function saveDescriptions(int $unitId, array $data): void
    {
        $names = self::perLanguage($data['name'] ?? null);
        if (!$names) {
            return;
        }

        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO unit_description (unit_id, language_id, name)
             VALUES (:unit_id, :language_id, :name)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );

        foreach ($names as $languageId => $name) {
            $stmt->execute(['unit_id' => $unitId, 'language_id' => $languageId, 'name' => $name]);
        }
    }

    /**
     * Fordítható szöveg nyelv szerint kulcsolt tömbje. Sima stringet is elfogad
     * (az alapnyelvhez rendeli), így a még nem nyelvesített hívók sem törnek el.
     *
     * @return array<int, string>
     */
    private static function perLanguage(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            return [Language::defaultId() => (string) $value];
        }

        $out = [];
        foreach ($value as $languageId => $text) {
            $languageId = (int) $languageId;
            if ($languageId > 0) {
                $out[$languageId] = (string) $text;
            }
        }

        return $out;
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM units WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * A megnevezés minden nyelven, szerkesztéshez.
     *
     * @return array<int, string> nyelv id => név
     */
    public function descriptions(int $unitId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT language_id, name FROM unit_description WHERE unit_id = :id'
        );
        $stmt->execute(['id' => $unitId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['language_id']] = (string) $row['name'];
        }

        return $out;
    }
}
