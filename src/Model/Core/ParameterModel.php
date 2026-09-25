<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Language;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Sort;
use Cloudexus\Core\Translation;

class ParameterModel
{
    /** A paraméter neve a parameter_description táblából. */
    private static function descJoin(): string
    {
        return Translation::join('parameter_description', 'parameter_id', 'pa.id', 'pad');
    }

    private static function descSelect(): string
    {
        return Translation::select('pad', 'name');
    }

    public function all(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT pa.*, ' . self::descSelect() . '
             FROM parameters pa
             ' . self::descJoin() . '
             ORDER BY name ASC'
        )->fetchAll();
    }

    /** Filters: q (name). */
    /** Sortable columns of the list (see Sort): key => SQL expression. */
    public const SORTS = [
        'name' => 'name',
    ];

    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = Translation::pick('pad', 'name') . ' LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'pa.updated_at >= :updated_since';
            $params['updated_since'] = $filters['updated_since'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // A COUNT is megkapja a fordítás-joinokat, különben a q szűrő eltörik.
        $count = DatabaseConnection::get()->prepare(
            'SELECT COUNT(*) FROM parameters pa
             ' . self::descJoin() . "
             $whereSql"
        );
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            'SELECT pa.*, ' . self::descSelect() . '
             FROM parameters pa
             ' . self::descJoin() . "
             $whereSql
             ORDER BY " . Sort::orderBy(self::SORTS, 'name ASC') . "
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT pa.*, ' . self::descSelect() . '
             FROM parameters pa
             ' . self::descJoin() . '
             WHERE pa.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Foglalt-e már a név? A név nyelven belül egyedi, ezért a vizsgálat az
     * aktuális nyelv sorait nézi (uniq_parameter_name_per_language).
     */
    public function exists(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM parameter_description
                WHERE language_id = ' . Language::id() . ' AND name = :name';
        $params = ['name' => $name];
        if ($excludeId !== null) {
            $sql .= ' AND parameter_id != :id';
            $params['id'] = $excludeId;
        }
        $stmt = DatabaseConnection::get()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * A paraméter maga csak egy id — a nevek a parameter_description táblába
     * kerülnek. A $name nyelv szerint kulcsolt tömb (nyelv id => név); egyetlen
     * string is elfogadott, az az alapnyelv sorába kerül.
     *
     * @param string|array<int, string> $name
     */
    public function create(string|array $name): int
    {
        DatabaseConnection::get()
            ->prepare('INSERT INTO parameters (created_at) VALUES (NOW())')
            ->execute();
        $id = (int) DatabaseConnection::get()->lastInsertId();

        $this->saveDescriptions($id, $name);

        return $id;
    }

    /** @param string|array<int, string> $name */
    public function update(int $id, string|array $name): void
    {
        $this->saveDescriptions($id, $name);

        DatabaseConnection::get()
            ->prepare('UPDATE parameters SET updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM parameters WHERE id = :id')->execute(['id' => $id]);
    }

    /** Select2 AJAX search over parameter names; results use the name as both id and text. */
    public function search(string $q, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT pa.id, ' . self::descSelect() . '
             FROM parameters pa
             ' . self::descJoin() . '
             WHERE ' . Translation::pick('pad', 'name') . ' LIKE :q
             ORDER BY name ASC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue('q', '%' . $q . '%');
        $stmt->bindValue('lim', $perPage + 1, \PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        // A termékparaméterek id-t tárolnak, ezért a Select2 is id-t kap vissza.
        return [
            'results' => array_map(fn(array $r) => ['id' => (int) $r['id'], 'text' => $r['name']], $rows),
            'more' => $more,
        ];
    }

    /** @param string|array<int, string> $name */
    public function saveDescriptions(int $parameterId, string|array $name): void
    {
        $names = self::perLanguage($name);
        if (!$names) {
            return;
        }

        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO parameter_description (parameter_id, language_id, name)
             VALUES (:parameter_id, :language_id, :name)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );

        foreach ($names as $languageId => $text) {
            $stmt->execute(['parameter_id' => $parameterId, 'language_id' => $languageId, 'name' => $text]);
        }
    }

    /**
     * Fordítható szöveg nyelv szerint kulcsolt tömbje. Sima stringet is elfogad
     * (az alapnyelvhez rendeli), így a még nem nyelvesített hívók sem törnek el.
     *
     * @return array<int, string>
     */
    private static function perLanguage(string|array $value): array
    {
        if (!is_array($value)) {
            return $value !== '' ? [Language::defaultId() => $value] : [];
        }

        $out = [];
        foreach ($value as $languageId => $text) {
            $languageId = (int) $languageId;
            if ($languageId > 0 && trim((string) $text) !== '') {
                $out[$languageId] = (string) $text;
            }
        }

        return $out;
    }

    /**
     * A paraméternév minden nyelven, szerkesztéshez.
     *
     * @return array<int, string> nyelv id => név
     */
    public function descriptions(int $parameterId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT language_id, name FROM parameter_description WHERE parameter_id = :id'
        );
        $stmt->execute(['id' => $parameterId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['language_id']] = (string) $row['name'];
        }

        return $out;
    }
}
