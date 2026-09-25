<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Core\Acl;
use Cloudexus\Core\Auth;
use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Language;
use Cloudexus\Core\PermissionSeeder;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Core\StockMovementModel;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the test database: migrated once per run, and
 * emptied of business data before every test. The base data the migrations
 * bring (languages, units, currencies, settings, roles and the permission
 * matrix) stays.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $migrated = false;

    /** Business tables, children before parents (the order only matters for readability: FK checks are off). */
    private const TABLES = [
        'payments', 'cash_vouchers', 'price_rules', 'audit_log', 'user_tokens', 'api_request_logs', 'api_idempotency_keys',
        'partner_activities', 'partner_addresses', 'todos', 'stocktaking_items', 'stocktakings', 'document_sequences',
        'incoming_invoice_items', 'incoming_invoices', 'purchase_order_items', 'purchase_orders',
        'invoice_items', 'invoices', 'order_items', 'orders', 'stock_movements', 'warehouse_locations', 'warehouses',
        'product_group_prices', 'product_parameters', 'product_categories', 'product_images', 'product_links',
        'product_description', 'products', 'category_description', 'categories',
        'partners', 'customer_groups', 'api_users', 'users',
    ];

    public static function setUpBeforeClass(): void
    {
        if (!defined('CLOUDEXUS_TEST_CONFIG')) {
            self::markTestSkipped('No test database: create config/test.ini (a database named *_test).');
        }

        if (!self::$migrated) {
            $pdo = DatabaseConnection::get();
            $files = glob(dirname(__DIR__, 2) . '/database/core/*.sql');
            sort($files);
            foreach ($files as $file) {
                $pdo->exec((string) file_get_contents($file));
            }
            PermissionSeeder::run();
            self::$migrated = true;
        }

        Language::init(null);
    }

    protected function setUp(): void
    {
        $pdo = DatabaseConnection::get();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $pdo->exec("TRUNCATE TABLE $table");
        }
        // The permission matrix is base data, but some tests change it on purpose. Back to the
        // defaults before every test: otherwise a right taken away stays gone for every later
        // test and run, because the seeder never gives back a key it has already seen.
        $pdo->exec('DELETE FROM role_permissions');
        $pdo->exec('UPDATE roles SET permissions_seeded_at = NULL');
        $pdo->exec("DELETE FROM settings WHERE setting_key = 'permissions.known'");
        PermissionSeeder::run();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $_SESSION = [];
        Auth::forget();
        Acl::flush();
    }

    protected function pdo(): \PDO
    {
        return DatabaseConnection::get();
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    // --- Fixtures --------------------------------------------------------

    protected function warehouse(string $name = 'Központi raktár'): int
    {
        $this->pdo()->prepare('INSERT INTO warehouses (name, is_active, created_at) VALUES (:name, 1, NOW())')->execute(['name' => $name]);

        return (int) $this->pdo()->lastInsertId();
    }

    protected function category(string $name, ?int $parentId = null): int
    {
        $this->pdo()->prepare('INSERT INTO categories (parent_id, is_active, created_at) VALUES (:parent, 1, NOW())')->execute(['parent' => $parentId]);
        $id = (int) $this->pdo()->lastInsertId();
        $this->pdo()->prepare('INSERT INTO category_description (category_id, language_id, name) VALUES (:id, :lang, :name)')
            ->execute(['id' => $id, 'lang' => Language::defaultId(), 'name' => $name]);

        return $id;
    }

    protected function product(float $price, array $extra = []): int
    {
        static $n = 0;
        $n++;
        $this->pdo()->prepare(
            'INSERT INTO products (sku, category_id, price, sale_price, vat_rate, min_stock, is_active, is_webshop, created_at)
             VALUES (:sku, :category_id, :price, :sale_price, :vat_rate, 0, 1, 1, NOW())'
        )->execute([
            'sku' => $extra['sku'] ?? sprintf('T-%04d', $n),
            'category_id' => $extra['category_id'] ?? null,
            'price' => $price,
            'sale_price' => $extra['sale_price'] ?? null,
            'vat_rate' => $extra['vat_rate'] ?? 27,
        ]);
        $id = (int) $this->pdo()->lastInsertId();
        $this->pdo()->prepare('INSERT INTO product_description (product_id, language_id, name) VALUES (:id, :lang, :name)')
            ->execute(['id' => $id, 'lang' => Language::defaultId(), 'name' => $extra['name'] ?? 'Termék ' . $n]);

        return $id;
    }

    protected function customerGroup(string $name): int
    {
        $this->pdo()->prepare('INSERT INTO customer_groups (name, created_at) VALUES (:name, NOW())')->execute(['name' => $name]);

        return (int) $this->pdo()->lastInsertId();
    }

    protected function partner(string $name = 'Teszt Kft.', ?int $groupId = null, string $type = 'customer'): int
    {
        return (new PartnerModel())->create([
            'type' => $type, 'customer_group_id' => $groupId, 'name' => $name, 'tax_number' => '12345678-1-42',
            'email' => '', 'phone' => '', 'address' => '', 'is_active' => 1,
        ]);
    }

    /** A user with the given role; returns the user id. */
    protected function user(string $roleCode, string $username = 'tester'): int
    {
        $roleId = (int) $this->scalar('SELECT id FROM roles WHERE code = :c', ['c' => $roleCode]);
        $this->pdo()->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, role, role_id, is_active, created_at)
             VALUES (:u, :e, :p, :n, IF(:code = 'super_admin', 'admin', 'user'), :role, 1, NOW())"
        )->execute(['u' => $username, 'e' => $username . '@example.test', 'p' => password_hash('x', PASSWORD_DEFAULT),
                    'n' => ucfirst($username), 'code' => $roleCode, 'role' => $roleId]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** Signs a user in for this request (what the session would hold). */
    protected function signIn(int $userId): void
    {
        $_SESSION['user_id'] = $userId;
        Auth::forget();
    }

    protected function stockIn(int $warehouseId, int $productId, float $quantity): void
    {
        (new StockMovementModel())->create([
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'type' => 'in',
            'quantity' => $quantity, 'note' => 'teszt', 'created_by' => null,
        ]);
    }

    protected function stockOf(int $warehouseId, int $productId): float
    {
        return (new StockMovementModel())->availableQuantity($productId, $warehouseId);
    }
}
