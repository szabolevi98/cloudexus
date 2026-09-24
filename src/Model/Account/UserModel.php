<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;
use PDO;

class UserModel
{
    public function findByUsernameOrEmail(string $usernameOrEmail): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1'
        );
        $stmt->execute(['username' => $usernameOrEmail, 'email' => $usernameOrEmail]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /** Egy aktív felhasználó a szerepkörével — minden kérés ezzel azonosít (Auth::user). */
    public function findActiveWithRole(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT u.id, u.username, u.email, u.full_name, u.role_id, r.code AS role_code, r.name AS role_name
             FROM users u LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.is_active = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function all(): array
    {
        return DatabaseConnection::get()
            ->query('SELECT u.id, u.username, u.email, u.full_name, u.role_id, r.name AS role_name, r.code AS role_code, u.is_active, u.last_login_at, u.created_at FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.id ASC')
            ->fetchAll();
    }

    /** Filters: q (username/full_name/email). */
    public function paginate(array $filters, \Cloudexus\Core\Paginator $pager): array
    {
        $where = '';
        $params = [];

        if ($filters['q'] !== '') {
            $where = 'WHERE username LIKE :q1 OR full_name LIKE :q2 OR email LIKE :q3';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM users $where");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT u.id, u.username, u.email, u.full_name, u.role_id, r.name AS role_name, r.code AS role_code, u.is_active, u.last_login_at, u.created_at
             FROM users u LEFT JOIN roles r ON r.id = u.role_id $where ORDER BY u.id ASC LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, role, role_id, is_active, created_at)
             VALUES (:username, :email, :password_hash, :full_name,
                     IF((SELECT code FROM roles WHERE id = :role_id2) = 'super_admin', 'admin', 'user'), :role_id, :is_active, NOW())"
        );
        $stmt->execute([
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'full_name' => $data['full_name'],
            'role_id' => $data['role_id'],
            'role_id2' => $data['role_id'],
            'is_active' => $data['is_active'] ?? 1,
        ]);

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [
            'username = :username',
            'email = :email',
            'full_name = :full_name',
            "role = IF((SELECT code FROM roles WHERE id = :role_id2) = 'super_admin', 'admin', 'user')",
            'role_id = :role_id',
            'is_active = :is_active',
        ];
        $params = [
            'id' => $id,
            'username' => $data['username'],
            'email' => $data['email'],
            'full_name' => $data['full_name'],
            'role_id' => $data['role_id'],
            'role_id2' => $data['role_id'],
            'is_active' => $data['is_active'],
        ];

        if (!empty($data['password'])) {
            $fields[] = 'password_hash = :password_hash';
            $params['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        DatabaseConnection::get()->prepare($sql)->execute($params);

        // A new password signs the user out of the mobile app on every device.
        if (!empty($data['password'])) {
            (new UserTokenModel())->revokeAllForUser($id);
        }
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    }

    public function touchLastLogin(int $id): void
    {
        DatabaseConnection::get()
            ->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public function usernameOrEmailExists(string $username, string $email, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE (username = :username OR email = :email)';
        $params = ['username' => $username, 'email' => $email];

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeId;
        }

        $stmt = DatabaseConnection::get()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }
}
