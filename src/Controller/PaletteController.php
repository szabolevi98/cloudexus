<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Acl;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Config;
use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Permissions;
use Cloudexus\Core\Translation;
use Cloudexus\Model\Account\RecentViewModel;

/**
 * A Ctrl+K kereső válaszai: egy termék, partner vagy bizonylat néhány betűje
 * alapján, egy oldal vagy egy teendő a neve szerint, üres kereséssel pedig a
 * legutóbb megnyitottak. Csak abból, amit a felhasználó szerepköre láthat.
 */
class PaletteController extends BaseController
{
    /** Fajta => a jog, ami a megnézéséhez kell. */
    private const KINDS = [
        'product' => Permissions::PRODUCTS_VIEW,
        'partner' => Permissions::PARTNERS_VIEW,
        'invoice' => Permissions::INVOICES_VIEW,
        'order' => Permissions::ORDERS_VIEW,
        'purchase_order' => Permissions::PURCHASING_VIEW,
        'incoming_invoice' => Permissions::PURCHASING_VIEW,
    ];

    /** Oldalak és teendők: a felirat kulcsa, a cím és a jog, ami kell hozzá. */
    private const COMMANDS = [
        ['palette.new_invoice', '/invoices/create', Permissions::INVOICES_ISSUE],
        ['palette.new_order', '/orders/create', Permissions::ORDERS_MANAGE],
        ['palette.new_product', '/products/create', Permissions::PRODUCTS_MANAGE],
        ['palette.new_partner', '/partners/create', Permissions::PARTNERS_MANAGE],
        ['palette.new_purchase_order', '/purchase-orders/create', Permissions::PURCHASING_MANAGE],
        ['nav.stock_in', '/stock/in', Permissions::STOCK_MOVE],
        ['nav.stock_out', '/stock/out', Permissions::STOCK_MOVE],
        ['nav.stock_transfer', '/stock/transfer', Permissions::STOCK_MOVE],
        ['nav.barcode', '/stock/barcode', Permissions::STOCK_MOVE],
        ['nav.dashboard', '/dashboard', Permissions::DASHBOARD_VIEW],
        ['nav.products', '/products', Permissions::PRODUCTS_VIEW],
        ['nav.categories', '/categories', Permissions::PRODUCTS_VIEW],
        ['nav.partners', '/partners', Permissions::PARTNERS_VIEW],
        ['nav.price_rules', '/price-rules', Permissions::PRODUCTS_VIEW],
        ['nav.stock_overview', '/stock', Permissions::STOCK_VIEW],
        ['nav.stocktaking', '/stocktaking', Permissions::STOCK_VIEW],
        ['nav.orders', '/orders', Permissions::ORDERS_VIEW],
        ['nav.invoices', '/invoices', Permissions::INVOICES_VIEW],
        ['nav.purchase_orders', '/purchase-orders', Permissions::PURCHASING_VIEW],
        ['nav.incoming_invoices', '/incoming-invoices', Permissions::PURCHASING_VIEW],
        ['nav.cash', '/cash', Permissions::CASH_VIEW],
        ['nav.aging', '/reports/aging', Permissions::CASH_VIEW],
        ['nav.todos', '/todos', Permissions::CRM_VIEW],
        ['nav.users', '/users', Permissions::USERS_MANAGE],
        ['nav.audit', '/audit', Permissions::AUDIT_VIEW],
        ['nav.settings_company', '/settings/company', Permissions::SETTINGS_MANAGE],
        ['nav.settings_email', '/settings/email', Permissions::SETTINGS_MANAGE],
        ['nav.profile', '/profile', null],
    ];

    private const PER_KIND = 5;

    public function search(): void
    {
        $this->requireAuth();

        $q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 80));
        $base = rtrim((string) Config::get('app.base_url'), '/');
        $items = [];

        if ($q === '') {
            $kinds = array_keys(array_filter(self::KINDS, static fn(string $permission): bool => Acl::can($permission)));
            foreach ((new RecentViewModel())->latest((int) Auth::id(), $kinds, 8) as $recent) {
                $items[] = ['group' => $this->t('palette.recent'), 'label' => $recent['label'], 'hint' => $recent['hint'], 'url' => $base . $this->urlFor($recent['kind'], $recent['id'], $recent['hint'])];
            }
        } else {
            foreach ($this->found($q) as $item) {
                $items[] = ['group' => $item['group'], 'label' => $item['label'], 'hint' => $item['hint'], 'url' => $base . $item['url']];
            }
        }

        $words = preg_split('/\s+/', mb_strtolower($q)) ?: [];
        foreach (self::COMMANDS as [$key, $path, $permission]) {
            if ($permission !== null && !Acl::can($permission)) {
                continue;
            }
            $label = $this->t($key);
            $haystack = mb_strtolower($label . ' ' . $path);
            if ($q !== '' && array_filter($words, static fn(string $word): bool => $word !== '' && !str_contains($haystack, $word)) !== []) {
                continue;
            }
            $items[] = ['group' => $this->t('palette.go_to'), 'label' => $label, 'hint' => '', 'url' => $base . $path];
            if ($q === '' && count($items) >= 16) {
                break;
            }
        }

        $this->json(['items' => array_slice($items, 0, 24)]);
    }

    /** @return list<array{group: string, label: string, hint: string, url: string}> */
    private function found(string $q): array
    {
        $pdo = DatabaseConnection::get();
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $limit = ' LIMIT ' . self::PER_KIND;
        $found = [];
        $add = function (string $group, string $sql, callable $row) use ($pdo, $like, &$found): void {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);
            foreach ($stmt->fetchAll() as $r) {
                $found[] = ['group' => $group] + $row($r);
            }
        };

        if (Acl::can(Permissions::PRODUCTS_VIEW)) {
            $add(
                $this->t('palette.products'),
                'SELECT p.id, p.sku, ' . Translation::select('pd', 'name') . ' FROM products p '
                . Translation::join('product_description', 'product_id', 'p.id', 'pd')
                . ' WHERE p.sku LIKE :q1 OR p.barcode LIKE :q2 OR ' . Translation::pick('pd', 'name') . ' LIKE :q3 ORDER BY p.is_active DESC, p.sku' . $limit,
                fn(array $r): array => ['label' => (string) $r['name'], 'hint' => (string) $r['sku'], 'url' => $this->urlFor('product', (int) $r['id'], (string) $r['sku'])]
            );
        }
        if (Acl::can(Permissions::PARTNERS_VIEW)) {
            $add(
                $this->t('palette.partners'),
                'SELECT id, name, tax_number FROM partners WHERE name LIKE :q1 OR tax_number LIKE :q2 OR email LIKE :q3 ORDER BY is_active DESC, name' . $limit,
                fn(array $r): array => ['label' => (string) $r['name'], 'hint' => (string) ($r['tax_number'] ?? ''), 'url' => '/partners/' . $r['id']]
            );
        }
        if (Acl::can(Permissions::INVOICES_VIEW)) {
            $add(
                $this->t('palette.invoices'),
                'SELECT i.id, i.invoice_number, COALESCE(i.buyer_name, p.name) AS partner FROM invoices i JOIN partners p ON p.id = i.partner_id
                 WHERE i.invoice_number LIKE :q1 OR i.buyer_name LIKE :q2 OR p.name LIKE :q3 ORDER BY i.id DESC' . $limit,
                fn(array $r): array => ['label' => (string) $r['invoice_number'], 'hint' => (string) $r['partner'], 'url' => '/invoices/' . $r['id']]
            );
        }
        if (Acl::can(Permissions::ORDERS_VIEW)) {
            $add(
                $this->t('palette.orders'),
                'SELECT o.id, o.order_number, p.name AS partner FROM orders o JOIN partners p ON p.id = o.partner_id
                 WHERE o.order_number LIKE :q1 OR p.name LIKE :q2 OR p.tax_number LIKE :q3 ORDER BY o.id DESC' . $limit,
                fn(array $r): array => ['label' => (string) $r['order_number'], 'hint' => (string) $r['partner'], 'url' => '/orders/' . $r['id']]
            );
        }
        if (Acl::can(Permissions::PURCHASING_VIEW)) {
            $add(
                $this->t('palette.purchase_orders'),
                'SELECT po.id, po.po_number, p.name AS partner FROM purchase_orders po JOIN partners p ON p.id = po.partner_id
                 WHERE po.po_number LIKE :q1 OR p.name LIKE :q2 OR p.tax_number LIKE :q3 ORDER BY po.id DESC' . $limit,
                fn(array $r): array => ['label' => (string) $r['po_number'], 'hint' => (string) $r['partner'], 'url' => '/purchase-orders/' . $r['id']]
            );
            $add(
                $this->t('palette.incoming_invoices'),
                'SELECT ii.id, ii.invoice_number, p.name AS partner FROM incoming_invoices ii JOIN partners p ON p.id = ii.partner_id
                 WHERE ii.invoice_number LIKE :q1 OR p.name LIKE :q2 OR p.tax_number LIKE :q3 ORDER BY ii.id DESC' . $limit,
                fn(array $r): array => ['label' => (string) $r['invoice_number'], 'hint' => (string) $r['partner'], 'url' => '/incoming-invoices/' . $r['id']]
            );
        }

        return $found;
    }

    /** Hová visz egy tétel: a termék a szerkesztőjére, ha szerkesztheti, különben a listára, a cikkszámára szűrve. */
    private function urlFor(string $kind, int $id, string $hint): string
    {
        return match ($kind) {
            'product' => Acl::can(Permissions::PRODUCTS_MANAGE) ? '/products/' . $id . '/edit' : '/products?q=' . rawurlencode($hint),
            'partner' => '/partners/' . $id,
            'invoice' => '/invoices/' . $id,
            'order' => '/orders/' . $id,
            'purchase_order' => '/purchase-orders/' . $id,
            'incoming_invoice' => '/incoming-invoices/' . $id,
            default => '/dashboard',
        };
    }
}
