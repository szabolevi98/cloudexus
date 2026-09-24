<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Permissions;
use Cloudexus\Core\RoleCode;

/** Szerepkörök és a jogosultság-mátrix. */
class RoleModel
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values(DatabaseConnection::get()->query('SELECT * FROM roles ORDER BY sort_order, name')->fetchAll());
    }

    /** @return list<array<string, mixed>> a szerepkörök, mindegyik a felhasználói számával */
    public function allWithCounts(): array
    {
        return array_values(DatabaseConnection::get()->query(
            'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions p WHERE p.role_id = r.id) AS permission_count
             FROM roles r ORDER BY r.sort_order, r.name'
        )->fetchAll());
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM roles WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM roles WHERE code = :code');
        $stmt->execute(['code' => $code]);

        return $stmt->fetch() ?: null;
    }

    /** Egy felhasználó szerepköre (id, code, name), vagy null. */
    public function forUser(int $userId): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT r.id, r.code, r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND u.is_active = 1'
        );
        $stmt->execute(['id' => $userId]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $pdo = DatabaseConnection::get();
        $pdo->prepare(
            'INSERT INTO roles (code, name, description, is_system, sort_order, permissions_seeded_at)
             VALUES (:code, :name, :description, 0, :sort_order, NOW())'
        )->execute([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 100),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** A név, a leírás és a sorrend szerkeszthető; a kód csak a saját szerepköröknél. */
    public function update(int $id, array $data): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE roles SET name = :name, description = :description, sort_order = :sort_order,
                    code = CASE WHEN is_system = 1 THEN code ELSE :code END
             WHERE id = :id'
        )->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 100),
            'code' => $data['code'],
            'id' => $id,
        ]);
    }

    /** Csak saját, felhasználó nélküli szerepkör törölhető. */
    public function delete(int $id): bool
    {
        $stmt = DatabaseConnection::get()->prepare(
            'DELETE FROM roles WHERE id = :id AND is_system = 0 AND NOT EXISTS (SELECT 1 FROM users WHERE role_id = :id2)'
        );
        $stmt->execute(['id' => $id, 'id2' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT EXISTS (SELECT 1 FROM roles WHERE code = :code AND id <> :id)');
        $stmt->execute(['code' => $code, 'id' => $exceptId ?? 0]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return list<string> egy szerepkör jogai */
    public function permissions(int $roleId): array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT permission FROM role_permissions WHERE role_id = :id');
        $stmt->execute(['id' => $roleId]);

        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @return array<int, array<string, bool>> szerepkör-id => kulcs => megvan */
    public function matrix(): array
    {
        $matrix = [];
        foreach (DatabaseConnection::get()->query('SELECT role_id, permission FROM role_permissions')->fetchAll() as $row) {
            $matrix[(int) $row['role_id']][(string) $row['permission']] = true;
        }

        return $matrix;
    }

    /**
     * Egy szerepkör jogai egyben lecserélve — csak katalógusban lévő kulccsal,
     * és a szuper adminnál soha. Visszaadja, mi került hozzá és mi került le.
     *
     * @param list<string> $permissions
     * @return array{added: list<string>, removed: list<string>}
     */
    public function setPermissions(int $roleId, array $permissions): array
    {
        $role = $this->findById($roleId);
        if ($role === null || $role['code'] === RoleCode::SUPER_ADMIN) {
            return ['added' => [], 'removed' => []];
        }

        $wanted = array_values(array_unique(array_filter($permissions, [Permissions::class, 'exists'])));
        $current = $this->permissions($roleId);

        $pdo = DatabaseConnection::get();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }

        try {
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :id')->execute(['id' => $roleId]);
            $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (:id, :permission)');
            foreach ($wanted as $permission) {
                $insert->execute(['id' => $roleId, 'permission' => $permission]);
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

        return [
            'added' => array_values(array_diff($wanted, $current)),
            'removed' => array_values(array_diff($current, $wanted)),
        ];
    }

    /** Hány aktív szuper admin van, a megadott felhasználón kívül. */
    public function otherActiveSuperAdmins(int $exceptUserId): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.code = 'super_admin' AND u.is_active = 1 AND u.id <> :id"
        );
        $stmt->execute(['id' => $exceptUserId]);

        return (int) $stmt->fetchColumn();
    }
}
