<?php

namespace Cloudexus\Core;

use Cloudexus\Model\Account\RoleModel;
use Cloudexus\Model\Core\ProductModel;
use PDO;

/**
 * A reggeli összefoglaló egy felhasználónak: ami ma figyelmet kér, abból,
 * amit a szerepköre láthat — a lejárt vevői számlák (invoices.view), a
 * lejárt szállítói számlák (purchasing.view), a minimum alá esett termékek
 * (stock.view) és a neki kiosztott, ma vagy korábban esedékes teendők
 * (crm.view). Ha egyik sincs, nincs levél.
 */
final class Digest
{
    private const TOP = 5;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * A levél tárgya és szövege, vagy null, ha ma nincs miről írni.
     *
     * @param array<string, mixed> $user a users tábla sora
     * @return array{subject: string, body: string, sections: list<string>}|null
     */
    public function forUser(array $user): ?array
    {
        $can = $this->permissions((int) $user['id']);
        $sections = [];
        $parts = [];

        if (in_array(Permissions::INVOICES_VIEW, $can, true) && ($text = $this->overdue('invoices', 'digest.receivables')) !== null) {
            $sections[] = 'receivables';
            $parts[] = $text;
        }
        if (in_array(Permissions::PURCHASING_VIEW, $can, true) && ($text = $this->overdue('incoming_invoices', 'digest.payables')) !== null) {
            $sections[] = 'payables';
            $parts[] = $text;
        }
        if (in_array(Permissions::STOCK_VIEW, $can, true) && ($text = $this->lowStock()) !== null) {
            $sections[] = 'low_stock';
            $parts[] = $text;
        }
        if (in_array(Permissions::CRM_VIEW, $can, true) && ($text = $this->todos((int) $user['id'])) !== null) {
            $sections[] = 'todos';
            $parts[] = $text;
        }

        if ($parts === []) {
            return null;
        }

        $base = rtrim((string) Config::get('app.base_url'), '/');
        $body = Lang::get('digest.hello', ['name' => (string) $user['full_name']]) . "\n\n"
            . implode("\n\n", $parts) . "\n\n"
            . $base . "\n\n"
            . Lang::get('digest.unsubscribe', ['url' => $base . '/profile']) . "\n";

        return [
            'subject' => Lang::get('digest.subject', ['date' => date('Y-m-d')]),
            'body' => $body,
            'sections' => $sections,
        ];
    }

    /** @return list<string> a felhasználó jogai; a szuper adminé mind */
    private function permissions(int $userId): array
    {
        $roles = new RoleModel();
        $role = $roles->forUser($userId);
        if ($role === null) {
            return [];
        }

        return $role['code'] === RoleCode::SUPER_ADMIN ? Permissions::all() : $roles->permissions((int) $role['id']);
    }

    /** A lejárt, nyitott számlák egy táblából: hány, mennyi, és a legrégebbiek. */
    private function overdue(string $table, string $titleKey): ?string
    {
        $rows = $this->db->query(
            "SELECT d.invoice_number, p.name AS partner_name, d.total_amount - d.paid_amount AS open_amount,
                    DATEDIFF(CURDATE(), d.due_date) AS days
             FROM $table d JOIN partners p ON p.id = d.partner_id
             WHERE d.status = 'unpaid' AND d.due_date < CURDATE() AND d.total_amount - d.paid_amount > 0
             ORDER BY d.due_date ASC, d.id ASC"
        );
        $all = $rows === false ? [] : $rows->fetchAll();
        if ($all === []) {
            return null;
        }

        $lines = [Lang::get($titleKey, ['count' => (string) count($all), 'total' => Currency::format(array_sum(array_column($all, 'open_amount')))])];
        foreach (array_slice($all, 0, self::TOP) as $row) {
            $lines[] = '  - ' . $row['invoice_number'] . ' · ' . $row['partner_name'] . ' · '
                . Currency::format((float) $row['open_amount']) . ' · ' . Lang::get('digest.days_late', ['days' => (string) $row['days']]);
        }
        if (count($all) > self::TOP) {
            $lines[] = '  ' . Lang::get('digest.and_more', ['count' => (string) (count($all) - self::TOP)]);
        }

        return implode("\n", $lines);
    }

    private function lowStock(): ?string
    {
        $products = new ProductModel();
        $rows = $products->lowStock(self::TOP + 1);
        if ($rows === []) {
            return null;
        }

        $lines = [Lang::get('digest.low_stock')];
        foreach (array_slice($rows, 0, self::TOP) as $row) {
            $lines[] = '  - ' . $row['sku'] . ' · ' . $row['name'] . ' · '
                . Quantity::format((float) $row['stock_qty']) . ' / ' . Quantity::format((float) $row['min_stock']) . ' ' . ($row['unit'] ?? '');
        }
        if (count($rows) > self::TOP) {
            $lines[] = '  ' . Lang::get('digest.see_dashboard');
        }

        return implode("\n", $lines);
    }

    private function todos(int $userId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT t.title, t.due_date, p.name AS partner_name FROM todos t LEFT JOIN partners p ON p.id = t.partner_id
             WHERE t.is_done = 0 AND t.assigned_to = :user AND t.due_date IS NOT NULL AND t.due_date <= CURDATE()
             ORDER BY t.due_date ASC, t.id ASC'
        );
        $stmt->execute(['user' => $userId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return null;
        }

        $lines = [Lang::get('digest.todos', ['count' => (string) count($rows)])];
        foreach (array_slice($rows, 0, self::TOP * 2) as $row) {
            $lines[] = '  - ' . $row['due_date'] . ' · ' . $row['title'] . ($row['partner_name'] ? ' · ' . $row['partner_name'] : '');
        }

        return implode("\n", $lines);
    }
}
