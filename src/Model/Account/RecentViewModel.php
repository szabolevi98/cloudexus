<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Translation;

/** A legutóbb megnyitott termékek, partnerek és bizonylatok — lásd a 14_recent_views.sql migrációt. */
class RecentViewModel
{
    public const KEEP = 30;

    /** Megnyitotta; a legrégebbiek a 30. után kiesnek. */
    public function viewed(int $userId, string $kind, int $itemId): void
    {
        $pdo = DatabaseConnection::get();
        $pdo->prepare(
            'INSERT INTO recent_views (user_id, kind, item_id, viewed_at) VALUES (:user, :kind, :item, NOW())
             ON DUPLICATE KEY UPDATE viewed_at = NOW()'
        )->execute(['user' => $userId, 'kind' => $kind, 'item' => $itemId]);

        $pdo->prepare(
            'DELETE FROM recent_views WHERE user_id = :user AND viewed_at <= (
                SELECT viewed_at FROM (SELECT viewed_at FROM recent_views WHERE user_id = :user2 ORDER BY viewed_at DESC LIMIT 1 OFFSET ' . self::KEEP . ') AS cutoff
             )'
        )->execute(['user' => $userId, 'user2' => $userId]);
    }

    /**
     * A legutóbbiak a nevükkel, csak azokból a fajtákból, amiket a
     * felhasználó láthat, és csak ami még megvan.
     *
     * @param list<string> $kinds a látható fajták
     * @return list<array{kind: string, id: int, label: string, hint: string}>
     */
    public function latest(int $userId, array $kinds, int $limit = 8): array
    {
        if ($kinds === []) {
            return [];
        }

        $placeholders = implode(', ', array_map(static fn(int $i): string => ':k' . $i, array_keys($kinds)));
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT rv.kind, rv.item_id,
                    CASE rv.kind
                        WHEN 'product' THEN " . Translation::pick('pd', 'name') . "
                        WHEN 'partner' THEN pa.name
                        WHEN 'invoice' THEN i.invoice_number
                        WHEN 'order' THEN o.order_number
                        WHEN 'purchase_order' THEN po.po_number
                        WHEN 'incoming_invoice' THEN ii.invoice_number
                    END AS label,
                    CASE rv.kind
                        WHEN 'product' THEN pr.sku
                        WHEN 'partner' THEN COALESCE(pa.tax_number, '')
                        WHEN 'invoice' THEN ip.name
                        WHEN 'order' THEN op.name
                        WHEN 'purchase_order' THEN pop.name
                        WHEN 'incoming_invoice' THEN iip.name
                    END AS hint
             FROM recent_views rv
             LEFT JOIN products pr ON rv.kind = 'product' AND pr.id = rv.item_id
             " . Translation::join('product_description', 'product_id', 'pr.id', 'pd') . "
             LEFT JOIN partners pa ON rv.kind = 'partner' AND pa.id = rv.item_id
             LEFT JOIN invoices i ON rv.kind = 'invoice' AND i.id = rv.item_id
             LEFT JOIN partners ip ON ip.id = i.partner_id
             LEFT JOIN orders o ON rv.kind = 'order' AND o.id = rv.item_id
             LEFT JOIN partners op ON op.id = o.partner_id
             LEFT JOIN purchase_orders po ON rv.kind = 'purchase_order' AND po.id = rv.item_id
             LEFT JOIN partners pop ON pop.id = po.partner_id
             LEFT JOIN incoming_invoices ii ON rv.kind = 'incoming_invoice' AND ii.id = rv.item_id
             LEFT JOIN partners iip ON iip.id = ii.partner_id
             WHERE rv.user_id = :user AND rv.kind IN ($placeholders)
               AND COALESCE(pr.id, pa.id, i.id, o.id, po.id, ii.id) IS NOT NULL
             ORDER BY rv.viewed_at DESC LIMIT " . max(1, $limit)
        );
        $params = ['user' => $userId];
        foreach ($kinds as $i => $kind) {
            $params['k' . $i] = $kind;
        }
        $stmt->execute($params);

        return array_map(static fn(array $row): array => [
            'kind' => (string) $row['kind'],
            'id' => (int) $row['item_id'],
            'label' => (string) $row['label'],
            'hint' => (string) $row['hint'],
        ], $stmt->fetchAll());
    }
}
