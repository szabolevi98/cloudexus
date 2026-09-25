<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;

/** A mentett szűrők — lásd a 15_saved_filters.sql migrációt. */
class SavedFilterModel
{
    /**
     * Egy lista mentett szűrői, amiket a felhasználó lát: a sajátjai és a
     * megosztottak, név szerint.
     *
     * @return list<array<string, mixed>>
     */
    public function forPage(string $page, int $userId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT sf.*, u.full_name AS owner_name FROM saved_filters sf JOIN users u ON u.id = sf.user_id
             WHERE sf.page = :page AND (sf.user_id = :user OR sf.is_shared = 1)
             ORDER BY sf.name'
        );
        $stmt->execute(['page' => $page, 'user' => $userId]);

        return array_values($stmt->fetchAll());
    }

    public function create(int $userId, string $page, string $name, string $query, bool $shared): int
    {
        DatabaseConnection::get()->prepare(
            'INSERT INTO saved_filters (user_id, page, name, query, is_shared) VALUES (:user, :page, :name, :query, :shared)'
        )->execute(['user' => $userId, 'page' => $page, 'name' => $name, 'query' => $query, 'shared' => $shared ? 1 : 0]);

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM saved_filters WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM saved_filters WHERE id = :id')->execute(['id' => $id]);
    }
}
