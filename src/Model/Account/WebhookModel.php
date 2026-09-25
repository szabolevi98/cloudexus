<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;

/** A webhookok és a kézbesítéseik — lásd a 16_webhooks.sql migrációt. */
class WebhookModel
{
    /** @return list<array<string, mixed>> a webhookok, a kézbesítéseik számával */
    public function all(): array
    {
        $rows = DatabaseConnection::get()->query(
            "SELECT w.*,
                    (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.webhook_id = w.id AND d.state = 'failed') AS failed,
                    (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.webhook_id = w.id AND d.state = 'pending') AS pending,
                    (SELECT MAX(delivered_at) FROM webhook_deliveries d WHERE d.webhook_id = w.id) AS last_delivered_at
             FROM webhooks w ORDER BY w.name"
        );

        return $rows === false ? [] : array_values($rows->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM webhooks WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function create(string $name, string $url, string $events, ?int $userId): int
    {
        DatabaseConnection::get()->prepare(
            'INSERT INTO webhooks (name, url, secret, events, created_by) VALUES (:name, :url, :secret, :events, :user)'
        )->execute(['name' => $name, 'url' => $url, 'secret' => bin2hex(random_bytes(32)), 'events' => $events, 'user' => $userId]);

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    public function update(int $id, string $name, string $url, string $events): void
    {
        DatabaseConnection::get()->prepare('UPDATE webhooks SET name = :name, url = :url, events = :events WHERE id = :id')
            ->execute(['id' => $id, 'name' => $name, 'url' => $url, 'events' => $events]);
    }

    public function toggle(int $id): void
    {
        DatabaseConnection::get()->prepare('UPDATE webhooks SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $id]);
    }

    public function newSecret(int $id): void
    {
        DatabaseConnection::get()->prepare('UPDATE webhooks SET secret = :secret WHERE id = :id')
            ->execute(['id' => $id, 'secret' => bin2hex(random_bytes(32))]);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM webhooks WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return list<array<string, mixed>> egy webhook legutóbbi kézbesítései */
    public function deliveries(int $webhookId, int $limit = 50): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT * FROM webhook_deliveries WHERE webhook_id = :id ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['id' => $webhookId]);

        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function delivery(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM webhook_deliveries WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }
}
