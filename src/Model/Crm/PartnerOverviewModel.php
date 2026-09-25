<?php

namespace Cloudexus\Model\Crm;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Translation;
use PDO;

/**
 * Egy partner egy helyen: a számai (forgalom, nyitott és lejárt tartozás,
 * utolsó rendelés, átlagos fizetési késés, ajánlatok sikere), a hitelkerete,
 * az idővonala (rendelések, ajánlatok, számlák, befizetések, tevékenységek,
 * teendők egy listában) és a legtöbbet vett termékei.
 */
class PartnerOverviewModel
{
    /** A számla alapból ennyi napra szól, ha a partnernek nincs sajátja. */
    public const DEFAULT_TERMS_DAYS = 8;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** @return array<string, mixed> */
    public function kpis(int $partnerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN YEAR(issue_date) = YEAR(CURDATE()) THEN total_amount END), 0) AS revenue_year,
                COALESCE(SUM(CASE WHEN YEAR(issue_date) = YEAR(CURDATE()) - 1 THEN total_amount END), 0) AS revenue_prev_year,
                COUNT(*) AS invoice_count
             FROM invoices WHERE partner_id = :id AND invoice_type = 'normal' AND status IN ('unpaid', 'paid')"
        );
        $stmt->execute(['id' => $partnerId]);
        $kpis = (array) $stmt->fetch();

        $kpis += $this->credit($partnerId);

        $stmt = $this->db->prepare("SELECT MAX(order_date) FROM orders WHERE partner_id = :id AND status <> 'cancelled'");
        $stmt->execute(['id' => $partnerId]);
        $kpis['last_order_date'] = $stmt->fetchColumn() ?: null;

        // Átlagos késés a kifizetett számláknál: az utolsó befizetés napja a határidőhöz képest.
        $stmt = $this->db->prepare(
            "SELECT AVG(DATEDIFF(p.paid_on, i.due_date)) FROM invoices i
             JOIN (SELECT invoice_id, MAX(paid_on) AS paid_on FROM payments WHERE invoice_id IS NOT NULL GROUP BY invoice_id) p ON p.invoice_id = i.id
             WHERE i.partner_id = :id AND i.status = 'paid' AND i.invoice_type = 'normal'"
        );
        $stmt->execute(['id' => $partnerId]);
        $delay = $stmt->fetchColumn();
        $kpis['avg_delay_days'] = $delay === null || $delay === false ? null : round((float) $delay, 1);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status IN ('accepted', 'ordered')), 0) AS won, COALESCE(SUM(status = 'rejected'), 0) AS lost,
                    COALESCE(SUM(status IN ('draft', 'sent') AND valid_until >= CURDATE()), 0) AS open
             FROM quotes WHERE partner_id = :id"
        );
        $stmt->execute(['id' => $partnerId]);
        $quotes = (array) $stmt->fetch();
        $decided = (int) $quotes['won'] + (int) $quotes['lost'];
        $kpis['quotes_open'] = (int) $quotes['open'];
        $kpis['quote_win_rate'] = $decided > 0 ? round((int) $quotes['won'] / $decided * 100) : null;

        return $kpis;
    }

    /**
     * A hitelkeret és a tartozás: a nyitott és a lejárt összeg, a keret, és
     * hogy mennyi fér még bele (null, ha nincs keret).
     *
     * @return array{credit_limit: ?float, payment_terms_days: int, open_balance: float, overdue: float, credit_left: ?float}
     */
    public function credit(int $partnerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.credit_limit, p.payment_terms_days,
                    COALESCE(SUM(i.total_amount - i.paid_amount), 0) AS open_balance,
                    COALESCE(SUM(CASE WHEN i.due_date < CURDATE() THEN i.total_amount - i.paid_amount END), 0) AS overdue
             FROM partners p
             LEFT JOIN invoices i ON i.partner_id = p.id AND i.status = 'unpaid' AND i.invoice_type = 'normal'
             WHERE p.id = :id GROUP BY p.id, p.credit_limit, p.payment_terms_days"
        );
        $stmt->execute(['id' => $partnerId]);
        $row = (array) $stmt->fetch();
        $limit = isset($row['credit_limit']) ? (float) $row['credit_limit'] : null;
        $open = round((float) ($row['open_balance'] ?? 0), 2);

        return [
            'credit_limit' => $limit,
            'payment_terms_days' => isset($row['payment_terms_days']) ? (int) $row['payment_terms_days'] : self::DEFAULT_TERMS_DAYS,
            'open_balance' => $open,
            'overdue' => round((float) ($row['overdue'] ?? 0), 2),
            'credit_left' => $limit === null ? null : round($limit - $open, 2),
        ];
    }

    /**
     * Minden, ami a partnerrel történt, a legújabb elöl.
     *
     * @return list<array{kind: string, id: int, at: string, title: string, detail: ?string, amount: ?float, status: ?string}>
     */
    public function timeline(int $partnerId, int $limit = 60): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM (
                SELECT 'order' AS kind, id, created_at AS at, order_number AS title, NULL AS detail, total_amount AS amount, status FROM orders WHERE partner_id = :p1
                UNION ALL
                SELECT 'quote', id, created_at, quote_number, NULL, total_amount, status FROM quotes WHERE partner_id = :p2
                UNION ALL
                SELECT 'invoice', id, created_at, invoice_number, invoice_type, total_amount, status FROM invoices WHERE partner_id = :p3
                UNION ALL
                SELECT 'payment', pay.id, pay.created_at, i.invoice_number, pay.method, pay.amount, NULL FROM payments pay JOIN invoices i ON i.id = pay.invoice_id WHERE i.partner_id = :p4
                UNION ALL
                SELECT 'activity', a.id, a.activity_date, a.subject, a.type, NULL, NULL FROM partner_activities a WHERE a.partner_id = :p5
                UNION ALL
                SELECT 'todo', t.id, t.created_at, t.title, IF(t.is_done = 1, 'done', NULL), NULL, t.due_date FROM todos t WHERE t.partner_id = :p6
             ) AS events ORDER BY at DESC, id DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute(['p1' => $partnerId, 'p2' => $partnerId, 'p3' => $partnerId, 'p4' => $partnerId, 'p5' => $partnerId, 'p6' => $partnerId]);

        return array_map(static fn(array $r): array => [
            'kind' => (string) $r['kind'],
            'id' => (int) $r['id'],
            'at' => (string) $r['at'],
            'title' => (string) $r['title'],
            'detail' => $r['detail'] === null ? null : (string) $r['detail'],
            'amount' => $r['amount'] === null ? null : (float) $r['amount'],
            'status' => $r['status'] === null ? null : (string) $r['status'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> a legtöbbet vett termékek az elmúlt egy évben, nettó érték szerint */
    public function topProducts(int $partnerId, int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            'SELECT ii.product_id, COALESCE(MAX(ii.product_sku), pr.sku) AS sku,
                    COALESCE(MAX(ii.product_name), ' . Translation::pick('pd', 'name') . ') AS name,
                    SUM(ii.quantity) AS quantity, SUM(COALESCE(ii.net_amount, ii.line_total)) AS net
             FROM invoice_items ii
             JOIN invoices i ON i.id = ii.invoice_id AND i.partner_id = :id AND i.invoice_type = \'normal\' AND i.status IN (\'unpaid\', \'paid\')
             JOIN products pr ON pr.id = ii.product_id
             ' . Translation::join('product_description', 'product_id', 'pr.id', 'pd') . '
             WHERE i.issue_date >= CURDATE() - INTERVAL 1 YEAR
             GROUP BY ii.product_id, pr.sku, ' . Translation::pick('pd', 'name') . '
             ORDER BY net DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['id' => $partnerId]);

        return array_values($stmt->fetchAll());
    }
}
