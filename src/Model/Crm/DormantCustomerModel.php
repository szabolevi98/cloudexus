<?php

namespace Cloudexus\Model\Crm;

use Cloudexus\Core\DatabaseConnection;

/**
 * Az alvó ügyfelek: akik vettek már tőlünk (rendelés vagy számla), de
 * régóta nem. Az aktív vevők közül, a legtöbbet hozók elöl — őket éri meg
 * először felhívni. Minden sorhoz a nyitott teendője is, ha van, hogy ne
 * kerüljön kétszer a listára ugyanaz a megkeresés.
 */
class DormantCustomerModel
{
    public const DAYS = [60, 90, 180, 365];

    /**
     * @return list<array{id: int, name: string, email: ?string, phone: ?string, last_purchase: string, days_since: int, purchases: int, revenue: float, todo_id: ?int, todo_due: ?string}>
     */
    public function find(int $days, ?int $tagId = null): array
    {
        $tag = $tagId ? ' AND EXISTS (SELECT 1 FROM partner_tags pt WHERE pt.partner_id = p.id AND pt.tag_id = :tag)' : '';
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT p.id, p.name, p.email, p.phone, a.last_purchase, a.purchases,
                    DATEDIFF(CURDATE(), a.last_purchase) AS days_since,
                    COALESCE(r.revenue, 0) AS revenue,
                    (SELECT t.id FROM todos t WHERE t.partner_id = p.id AND t.is_done = 0
                     ORDER BY t.due_date IS NULL, t.due_date LIMIT 1) AS todo_id
             FROM partners p
             JOIN (SELECT partner_id, MAX(d) AS last_purchase, COUNT(*) AS purchases FROM (
                       SELECT partner_id, order_date AS d FROM orders WHERE status <> 'cancelled'
                       UNION ALL
                       SELECT partner_id, issue_date FROM invoices WHERE invoice_type = 'normal' AND status IN ('unpaid', 'paid') AND order_id IS NULL
                   ) x GROUP BY partner_id) a ON a.partner_id = p.id
             LEFT JOIN (SELECT partner_id, SUM(total_amount) AS revenue FROM invoices
                        WHERE invoice_type = 'normal' AND status IN ('unpaid', 'paid') GROUP BY partner_id) r ON r.partner_id = p.id
             WHERE p.is_active = 1 AND p.type IN ('customer', 'both') AND a.last_purchase <= CURDATE() - INTERVAL :days DAY $tag
             ORDER BY revenue DESC, a.last_purchase ASC
             LIMIT 200"
        );
        $stmt->execute(['days' => $days] + ($tagId ? ['tag' => $tagId] : []));
        $rows = $stmt->fetchAll();

        // A nyitott teendők határideje egy lekérdezéssel.
        $todoIds = array_values(array_filter(array_map(static fn(array $r): int => (int) $r['todo_id'], $rows)));
        $due = [];
        if ($todoIds !== []) {
            foreach (DatabaseConnection::get()->query('SELECT id, due_date FROM todos WHERE id IN (' . implode(',', $todoIds) . ')')->fetchAll() as $todo) {
                $due[(int) $todo['id']] = $todo['due_date'];
            }
        }

        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'email' => $r['email'],
            'phone' => $r['phone'],
            'last_purchase' => (string) $r['last_purchase'],
            'days_since' => (int) $r['days_since'],
            'purchases' => (int) $r['purchases'],
            'revenue' => (float) $r['revenue'],
            'todo_id' => $r['todo_id'] !== null ? (int) $r['todo_id'] : null,
            'todo_due' => $r['todo_id'] !== null ? ($due[(int) $r['todo_id']] ?? null) : null,
        ], $rows);
    }
}
