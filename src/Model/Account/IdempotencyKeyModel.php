<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;

/**
 * Idempotency-Key support for booking endpoints: a client that lost the
 * response (flaky warehouse wifi) sends the same request again with the same
 * key, and gets the first response back instead of a second booking.
 *
 * claim() and complete() must run inside the caller's transaction, together
 * with the booking itself. A second request with the same key then blocks on
 * the unique index until the first one commits, and reads its stored response;
 * if the first one rolled back, the key was never stored and the retry books.
 */
class IdempotencyKeyModel
{
    /**
     * Stores the key, or returns the row already stored under it
     * (status_code + response) when this request is a retry.
     */
    public function claim(string $ownerKey, string $key, string $requestHash): ?array
    {
        $pdo = DatabaseConnection::get();

        try {
            $pdo->prepare(
                'INSERT INTO api_idempotency_keys (owner_key, idempotency_key, request_hash, created_at)
                 VALUES (:owner, :key, :hash, NOW())'
            )->execute(['owner' => $ownerKey, 'key' => $key, 'hash' => $requestHash]);

            return null;
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }

        $stmt = $pdo->prepare(
            'SELECT request_hash, status_code, response FROM api_idempotency_keys
             WHERE owner_key = :owner AND idempotency_key = :key FOR UPDATE'
        );
        $stmt->execute(['owner' => $ownerKey, 'key' => $key]);

        return $stmt->fetch() ?: null;
    }

    public function complete(string $ownerKey, string $key, int $statusCode, string $response): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE api_idempotency_keys SET status_code = :status, response = :response
             WHERE owner_key = :owner AND idempotency_key = :key'
        )->execute(['status' => $statusCode, 'response' => $response, 'owner' => $ownerKey, 'key' => $key]);
    }

    public function purgeOlderThan(int $days): void
    {
        $stmt = DatabaseConnection::get()->prepare(
            'DELETE FROM api_idempotency_keys WHERE created_at < NOW() - INTERVAL :days DAY'
        );
        $stmt->bindValue('days', $days, \PDO::PARAM_INT);
        $stmt->execute();
    }
}
