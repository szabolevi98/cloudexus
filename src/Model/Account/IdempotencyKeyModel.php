<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;

/**
 * Idempotency-Key support: a client that lost the response (flaky warehouse
 * wifi, a webshop's timed-out call) sends the same request again with the same
 * key, and gets the first response back instead of a second booking, partner
 * or order.
 *
 * Two ways of using it. The stock bookings run claim() and complete() inside
 * their own transaction, together with the booking itself: a second request
 * with the same key then blocks on the unique index until the first one
 * commits, and reads its stored response; if the first one rolled back, the
 * key was never stored and the retry books. Every other change (see
 * ApiController) claims the key before it starts, completes it with a
 * successful answer, and releases it when the request fails — a claim still
 * without an answer is a request still being worked on, unless it is older
 * than a minute, when its request is taken to have died.
 */
class IdempotencyKeyModel
{
    /** How long an unanswered claim is taken to be a request still being worked on. */
    public const ABANDONED_SECONDS = 60;

    /**
     * Stores the key, or returns the row already stored under it
     * (request_hash, status_code + response, and whether an unanswered one
     * is abandoned) when this request is a retry.
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
            'SELECT request_hash, status_code, response,
                    created_at < NOW() - INTERVAL ' . self::ABANDONED_SECONDS . ' SECOND AS abandoned
             FROM api_idempotency_keys
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

    /**
     * Takes over an abandoned claim for this request: false when another
     * request got there first.
     */
    public function takeOver(string $ownerKey, string $key): bool
    {
        $stmt = DatabaseConnection::get()->prepare(
            'UPDATE api_idempotency_keys SET created_at = NOW()
             WHERE owner_key = :owner AND idempotency_key = :key AND status_code IS NULL
               AND created_at < NOW() - INTERVAL ' . self::ABANDONED_SECONDS . ' SECOND'
        );
        $stmt->execute(['owner' => $ownerKey, 'key' => $key]);

        return $stmt->rowCount() === 1;
    }

    /** Lets an unanswered key go: its request failed, and did nothing. */
    public function release(string $ownerKey, string $key): void
    {
        DatabaseConnection::get()->prepare(
            'DELETE FROM api_idempotency_keys WHERE owner_key = :owner AND idempotency_key = :key AND status_code IS NULL'
        )->execute(['owner' => $ownerKey, 'key' => $key]);
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
