<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;

/**
 * Per-user API tokens, issued by POST /api/auth/login (the mobile / PDA app).
 * Only the SHA-256 hash is stored; the raw token is shown once, in the login
 * response. Expiry slides: every authenticated request pushes it forward.
 */
class UserTokenModel
{
    /** Raw tokens carry this prefix so ApiController can tell them from integration tokens. */
    public const PREFIX = 'cxu_';

    /**
     * Issues a new token for the user and returns the raw value with its expiry.
     *
     * @return array{token: string, expires_at: string}
     */
    public function issue(int $userId, ?string $deviceName, int $lifetimeDays): array
    {
        $token = self::PREFIX . bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $lifetimeDays * 86400);

        DatabaseConnection::get()->prepare(
            'INSERT INTO user_tokens (user_id, token_hash, device_name, last_used_at, expires_at, created_at)
             VALUES (:user_id, :hash, :device, NOW(), :expires_at, NOW())'
        )->execute([
            'user_id' => $userId,
            'hash' => self::hash($token),
            'device' => $deviceName !== null && $deviceName !== '' ? mb_substr($deviceName, 0, 120) : null,
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Resolves an unexpired token of an active user, or null. The row carries
     * the token's own id as token_id next to the user's columns.
     */
    public function findActiveUser(string $token): ?array
    {
        if (!str_starts_with($token, self::PREFIX)) {
            return null;
        }
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT t.id AS token_id, t.expires_at, u.id, u.username, u.email, u.full_name, u.role
             FROM user_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash AND t.expires_at > NOW() AND u.is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['hash' => self::hash($token)]);

        return $stmt->fetch() ?: null;
    }

    /** Marks the token used and slides its expiry forward. */
    public function touch(int $tokenId, int $lifetimeDays): void
    {
        $stmt = DatabaseConnection::get()->prepare(
            'UPDATE user_tokens SET last_used_at = NOW(), expires_at = NOW() + INTERVAL :days DAY WHERE id = :id'
        );
        $stmt->bindValue('days', $lifetimeDays, \PDO::PARAM_INT);
        $stmt->bindValue('id', $tokenId, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function revoke(int $tokenId): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM user_tokens WHERE id = :id')->execute(['id' => $tokenId]);
    }

    /** Signs the user out of every device, e.g. after a password change. */
    public function revokeAllForUser(int $userId): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM user_tokens WHERE user_id = :id')->execute(['id' => $userId]);
    }

    public function purgeExpired(): void
    {
        DatabaseConnection::get()->exec('DELETE FROM user_tokens WHERE expires_at <= NOW()');
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
