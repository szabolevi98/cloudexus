<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;

/** Az elfelejtett jelszó linkjei — lásd a 11_password_resets.sql migrációt. */
class PasswordResetModel
{
    public const LIFETIME_MINUTES = 60;

    /** Egy új link tokenje (csak ez az egy példánya létezik, a levélben); a régebbi, fel nem használtak érvényüket vesztik. */
    public function create(int $userId): string
    {
        $pdo = DatabaseConnection::get();
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id AND used_at IS NULL')->execute(['id' => $userId]);

        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:user_id, :hash, NOW() + INTERVAL ' . self::LIFETIME_MINUTES . ' MINUTE)'
        )->execute(['user_id' => $userId, 'hash' => hash('sha256', $token)]);

        return $token;
    }

    /**
     * Az aktív felhasználó, akié a még érvényes, fel nem használt link —
     * vagy null.
     *
     * @return array<string, mixed>|null a users tábla sora, reset_id-vel
     */
    public function findUser(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $stmt = DatabaseConnection::get()->prepare(
            'SELECT u.*, pr.id AS reset_id FROM password_resets pr JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = :hash AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.is_active = 1 LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);

        return $stmt->fetch() ?: null;
    }

    /** Felhasználva — igaz, ha most, és nem egy párhuzamos kérés vitte el előbb. */
    public function use(int $resetId): bool
    {
        $stmt = DatabaseConnection::get()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
        $stmt->execute(['id' => $resetId]);

        return $stmt->rowCount() === 1;
    }
}
