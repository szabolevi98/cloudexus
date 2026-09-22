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
            ->query('SELECT id, username, email, full_name, role, is_active, last_login_at, created_at FROM users ORDER BY id ASC')
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
            "SELECT id, username, email, full_name, role, is_active, last_login_at, created_at
             FROM users $where ORDER BY id ASC LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at)
             VALUES (:username, :email, :password_hash, :full_name, :role, :is_active, NOW())'
        );
        $stmt->execute([
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'full_name' => $data['full_name'],
            'role' => $data['role'] ?? 'user',
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
            'role = :role',
            'is_active = :is_active',
        ];
        $params = [
            'id' => $id,
            'username' => $data['username'],
            'email' => $data['email'],
            'full_name' => $data['full_name'],
            'role' => $data['role'],
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
