<?php

namespace Cloudexus\Core;

use PDO;

/**
 * Értesítés más programoknak arról, ami történt — lásd a 16_webhooks.sql
 * migrációt.
 *
 * Egy esemény (dispatch) minden webhooknak, ami kéri, egy üzenetet ír a
 * sorba, ugyanabban a tranzakcióban, mint a változás. A bin/webhooks.php küldi
 * ki percenként; amit a fogadó nem vesz át (nem válaszol, lassú, vagy nem
 * 2xx-szel felel), azt 1, 5, 15, 60 és 240 perc múlva újra próbálja, utána
 * hibás marad.
 *
 * Minden üzenet alá van írva: az X-Cloudexus-Signature fejléc "sha256=" és a
 * törzs HMAC-SHA256-ja a webhook titkos kulcsával, így a fogadó meggyőződhet
 * róla, hogy innen jött, és útközben nem változott.
 */
final class Webhooks
{
    public const EVENTS = [
        'order.created', 'order.cancelled',
        'invoice.issued', 'invoice.paid', 'invoice.stornoed',
        'stock.changed', 'product.changed', 'partner.changed',
    ];

    public const BACKOFF = [1, 5, 15, 60, 240];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * Egy esemény a sorba minden aktív webhooknak, ami kéri. Sosem dob: egy
     * értesítés nem akadályozhatja meg a változást, amiről szól.
     *
     * @param array<string, mixed> $data
     */
    public static function dispatch(string $event, array $data): void
    {
        try {
            (new self())->queueEvent($event, $data);
        } catch (\Throwable $e) {
            Logger::error('Webhook could not be queued: ' . $e->getMessage(), ['event' => $event]);
        }
    }

    /** @param array<string, mixed> $data @return list<int> a sorba írt üzenetek */
    public function queueEvent(string $event, array $data): array
    {
        $hooks = $this->db->query('SELECT id, events FROM webhooks WHERE is_active = 1');
        $ids = [];
        foreach ($hooks === false ? [] : $hooks->fetchAll() as $hook) {
            if (!self::wants((string) $hook['events'], $event)) {
                continue;
            }
            $ids[] = $this->queue((int) $hook['id'], $event, $data);
        }

        return $ids;
    }

    /** @param array<string, mixed> $data */
    public function queue(int $webhookId, string $event, array $data): int
    {
        $payload = json_encode(['event' => $event, 'sent_at' => date(DATE_ATOM), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->prepare('INSERT INTO webhook_deliveries (webhook_id, event, payload) VALUES (:hook, :event, :payload)')
            ->execute(['hook' => $webhookId, 'event' => $event, 'payload' => (string) $payload]);

        return (int) $this->db->lastInsertId();
    }

    public static function wants(string $events, string $event): bool
    {
        return trim($events) === '*' || in_array($event, array_map('trim', explode(',', $events)), true);
    }

    public static function signature(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * A készletváltozások a legutóbbi futás óta, termékenként és raktáranként
     * egy "stock.changed" üzenet, a mostani készlettel. Nem a mozgások
     * helyén szól, hanem egy mutató (settings: webhooks.stock_cursor) óta új
     * mozgásokból, így minden út — web, API, számla, leltár, bejövő számla —
     * egyformán értesít, és egy sokszoros szállítólevél sem ezer üzenet.
     * Az első futás csak beállítja a mutatót: a múltról nem szól.
     *
     * @return int hány üzenet került a sorba
     */
    public function collectStockChanges(): int
    {
        $settings = new \Cloudexus\Model\Core\SettingModel();
        $max = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) FROM stock_movements')->fetchColumn();
        $cursor = $settings->get('webhooks.stock_cursor');
        $settings->set('webhooks.stock_cursor', (string) $max);

        if ($cursor === null || (int) $cursor >= $max) {
            return 0;
        }

        $stmt = $this->db->prepare(
            "SELECT m.product_id, m.warehouse_id, p.sku, w.name AS warehouse_name,
                    SUM(CASE WHEN m.type = 'in' THEN m.quantity ELSE -m.quantity END) AS delta
             FROM stock_movements m JOIN products p ON p.id = m.product_id JOIN warehouses w ON w.id = m.warehouse_id
             WHERE m.id > :from AND m.id <= :to
             GROUP BY m.product_id, m.warehouse_id, p.sku, w.name"
        );
        $stmt->execute(['from' => (int) $cursor, 'to' => $max]);
        $stock = new \Cloudexus\Model\Core\StockMovementModel();
        $queued = 0;

        foreach ($stmt->fetchAll() as $row) {
            $total = (float) $this->db->query(
                "SELECT COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END), 0) FROM stock_movements WHERE product_id = " . (int) $row['product_id']
            )->fetchColumn();
            $queued += count($this->queueEvent('stock.changed', [
                'product' => ['id' => (int) $row['product_id'], 'sku' => (string) $row['sku']],
                'warehouse' => ['id' => (int) $row['warehouse_id'], 'name' => (string) $row['warehouse_name']],
                'change' => round((float) $row['delta'], 3),
                'stock_in_warehouse' => round($stock->availableQuantity((int) $row['product_id'], (int) $row['warehouse_id']), 3),
                'stock_total' => round($total, 3),
            ]));
        }

        return $queued;
    }

    /** Amit a cron futtat: minden esedékes üzenet. Visszaadja, hány ment át. */
    public function sendDue(int $limit = 100): int
    {
        $due = $this->db->query(
            "SELECT id FROM webhook_deliveries WHERE state = 'pending' AND next_attempt_at <= NOW() ORDER BY next_attempt_at, id LIMIT " . max(1, $limit)
        );

        return $this->send($due === false ? [] : array_map('intval', $due->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param list<int> $ids */
    public function send(array $ids): int
    {
        $sent = 0;
        foreach ($ids as $id) {
            $stmt = $this->db->prepare(
                "SELECT d.*, w.url, w.secret FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id WHERE d.id = :id AND d.state = 'pending'"
            );
            $stmt->execute(['id' => $id]);
            $delivery = $stmt->fetch();
            if (!$delivery) {
                continue;
            }

            $result = self::post($delivery);
            $ok = $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300;
            $this->record((int) $delivery['id'], (int) $delivery['attempts'], $ok, $result);
            $sent += $ok ? 1 : 0;
        }

        return $sent;
    }

    /** Egy hibás üzenet kézzel újra: most esedékes, a próbái elölről. */
    public function retry(int $deliveryId): bool
    {
        $stmt = $this->db->prepare("UPDATE webhook_deliveries SET state = 'pending', attempts = 0, next_attempt_at = NOW() WHERE id = :id AND state = 'failed'");
        $stmt->execute(['id' => $deliveryId]);

        return $stmt->rowCount() > 0;
    }

    /** A 30 napnál régebbi, átment üzenetek törlése. */
    public function prune(): int
    {
        return (int) $this->db->exec("DELETE FROM webhook_deliveries WHERE state = 'delivered' AND delivered_at < NOW() - INTERVAL 30 DAY");
    }

    /** @param array{status: ?int, body: ?string, error: ?string, ms: int} $result */
    private function record(int $id, int $attemptsBefore, bool $ok, array $result): void
    {
        $attempts = $attemptsBefore + 1;
        $wait = self::BACKOFF[$attempts - 1] ?? null;
        $state = $ok ? 'delivered' : ($wait === null ? 'failed' : 'pending');

        $this->db->prepare(
            'UPDATE webhook_deliveries SET state = :state, attempts = :attempts, response_status = :status, response_body = :body,
                    error = :error, duration_ms = :ms, next_attempt_at = NOW() + INTERVAL :wait MINUTE,
                    delivered_at = IF(:ok = 1, NOW(), NULL) WHERE id = :id'
        )->execute([
            'state' => $state,
            'attempts' => $attempts,
            'status' => $result['status'],
            'body' => $result['body'] === null ? null : mb_substr($result['body'], 0, 1000),
            'error' => $result['error'] === null ? null : mb_substr($result['error'], 0, 255),
            'ms' => $result['ms'],
            'wait' => $ok ? 0 : ($wait ?? 0),
            'ok' => $ok ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * @param array<string, mixed> $delivery
     * @return array{status: ?int, body: ?string, error: ?string, ms: int}
     */
    private static function post(array $delivery): array
    {
        $started = microtime(true);
        $body = (string) $delivery['payload'];

        try {
            $target = OutboundUrl::check((string) $delivery['url']);
        } catch (\InvalidArgumentException $e) {
            return ['status' => null, 'body' => null, 'error' => Lang::get($e->getMessage()), 'ms' => 0];
        }

        $handle = curl_init((string) $delivery['url']);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            // Az ellenőrzött címre kapcsolódik, nem arra, amire a név egy pillanattal később mutat.
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . (str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'])],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Cloudexus-Webhook/1',
                'X-Cloudexus-Event: ' . $delivery['event'],
                'X-Cloudexus-Delivery: ' . $delivery['id'],
                'X-Cloudexus-Signature: ' . self::signature($body, (string) $delivery['secret']),
            ],
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = $response === false ? curl_error($handle) : null;
        curl_close($handle);

        return [
            'status' => $status > 0 ? $status : null,
            'body' => is_string($response) ? $response : null,
            'error' => $error,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
