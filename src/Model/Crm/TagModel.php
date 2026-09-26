<?php

namespace Cloudexus\Model\Crm;

use Cloudexus\Core\DatabaseConnection;

/**
 * A partnercímkék — lásd a 22_partner_tags.sql migrációt.
 */
class TagModel
{
    /** A címkék színei: a név ellenőrzőösszegéből választva, hogy ugyanaz a címke mindenhol ugyanolyan legyen. */
    private const COLOURS = [
        'bg-primary-subtle text-primary-emphasis',
        'bg-success-subtle text-success-emphasis',
        'bg-warning-subtle text-warning-emphasis',
        'bg-info-subtle text-info-emphasis',
        'bg-danger-subtle text-danger-emphasis',
        'bg-secondary-subtle text-secondary-emphasis',
    ];

    public static function colour(string $name): string
    {
        return self::COLOURS[crc32(mb_strtolower($name)) % count(self::COLOURS)];
    }

    /**
     * A beírt címkék rendbe téve: levágva, üresek és ismétlődők nélkül, legfeljebb 60 karakter.
     *
     * @param array<mixed> $names
     * @return list<string>
     */
    public static function clean(array $names): array
    {
        $clean = [];
        foreach ($names as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
            $name = mb_substr($name, 0, 60);
            if ($name !== '' && !in_array(mb_strtolower($name), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $name;
            }
        }

        return $clean;
    }

    /** @return list<array{id: int, name: string, partners: int}> Az összes címke ábécérendben, a partnereik számával. */
    public function all(): array
    {
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'partners' => (int) $r['partners']], DatabaseConnection::get()->query(
            'SELECT t.id, t.name, COUNT(pt.partner_id) AS partners FROM tags t LEFT JOIN partner_tags pt ON pt.tag_id = t.id GROUP BY t.id, t.name ORDER BY t.name'
        )->fetchAll());
    }

    /** @return list<string> Egy partner címkéi. */
    public function forPartner(int $partnerId): array
    {
        return $this->forPartners([$partnerId])[$partnerId] ?? [];
    }

    /**
     * @param list<int> $partnerIds
     * @return array<int, list<string>> partner => a címkéi
     */
    public function forPartners(array $partnerIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $partnerIds)));
        if ($ids === []) {
            return [];
        }
        $rows = DatabaseConnection::get()->query(
            'SELECT pt.partner_id, t.name FROM partner_tags pt JOIN tags t ON t.id = pt.tag_id
             WHERE pt.partner_id IN (' . implode(',', $ids) . ') ORDER BY t.name'
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['partner_id']][] = (string) $row['name'];
        }

        return $map;
    }

    /**
     * A partner címkéi pontosan ezek legyenek; a hiányzók létrejönnek, a már
     * senkin nem lévők törlődnek.
     *
     * @param array<mixed> $names
     */
    public function setForPartner(int $partnerId, array $names): void
    {
        $pdo = DatabaseConnection::get();
        $pdo->prepare('DELETE FROM partner_tags WHERE partner_id = :p')->execute(['p' => $partnerId]);
        $this->link([$partnerId], $this->ids(self::clean($names)));
        $this->dropUnused();
    }

    /**
     * Egy címke sok partnerre — a lista tömeges műveletéhez. Visszaadja, hány
     * partner kapta meg újonnan.
     *
     * @param list<int> $partnerIds
     */
    public function addToPartners(array $partnerIds, string $name): int
    {
        $names = self::clean([$name]);
        if ($names === [] || $partnerIds === []) {
            return 0;
        }

        return $this->link($partnerIds, $this->ids($names));
    }

    /**
     * Egy címke le sok partnerről.
     *
     * @param list<int> $partnerIds
     */
    public function removeFromPartners(array $partnerIds, string $name): int
    {
        $ids = array_values(array_filter(array_map('intval', $partnerIds)));
        if ($ids === []) {
            return 0;
        }
        $stmt = DatabaseConnection::get()->prepare(
            'DELETE pt FROM partner_tags pt JOIN tags t ON t.id = pt.tag_id WHERE t.name = :name AND pt.partner_id IN (' . implode(',', $ids) . ')'
        );
        $stmt->execute(['name' => trim($name)]);
        $this->dropUnused();

        return $stmt->rowCount();
    }

    /**
     * @param list<string> $names
     * @return list<int> a címkék azonosítói, a hiányzók létrehozva
     */
    private function ids(array $names): array
    {
        $pdo = DatabaseConnection::get();
        $insert = $pdo->prepare('INSERT IGNORE INTO tags (name, created_at) VALUES (:name, NOW())');
        $select = $pdo->prepare('SELECT id FROM tags WHERE name = :name');
        $ids = [];
        foreach ($names as $name) {
            $insert->execute(['name' => $name]);
            $select->execute(['name' => $name]);
            $ids[] = (int) $select->fetchColumn();
        }

        return $ids;
    }

    /**
     * @param list<int> $partnerIds
     * @param list<int> $tagIds
     */
    private function link(array $partnerIds, array $tagIds): int
    {
        $stmt = DatabaseConnection::get()->prepare('INSERT IGNORE INTO partner_tags (partner_id, tag_id) VALUES (:p, :t)');
        $added = 0;
        foreach ($partnerIds as $partnerId) {
            foreach ($tagIds as $tagId) {
                $stmt->execute(['p' => (int) $partnerId, 't' => $tagId]);
                $added += $stmt->rowCount();
            }
        }

        return $added;
    }

    private function dropUnused(): void
    {
        DatabaseConnection::get()->exec('DELETE t FROM tags t LEFT JOIN partner_tags pt ON pt.tag_id = t.id WHERE pt.tag_id IS NULL');
    }
}
