<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Core\Digest;
use Cloudexus\Model\Account\UserModel;

/**
 * The morning digest says only what the user's role can see, and nothing at
 * all on a quiet day.
 */
final class DigestTest extends DatabaseTestCase
{
    private function digestFor(int $userId): ?array
    {
        return (new Digest())->forUser((array) (new UserModel())->findById($userId));
    }

    private function overdueInvoice(): void
    {
        $this->pdo()->prepare(
            "INSERT INTO invoices (invoice_number, partner_id, status, issue_date, due_date, total_amount, paid_amount, created_at)
             VALUES ('SZLA-TEST-1', :partner, 'unpaid', CURDATE() - INTERVAL 20 DAY, CURDATE() - INTERVAL 12 DAY, 50000, 10000, NOW())"
        )->execute(['partner' => $this->partner('Késő Kft.')]);
    }

    private function lowStockProduct(): void
    {
        $id = $this->product(1000, ['name' => 'Fogyó termék']);
        $this->pdo()->exec('UPDATE products SET min_stock = 10 WHERE id = ' . $id);
        $this->stockIn($this->warehouse(), $id, 3);
    }

    public function testAQuietDayHasNoEmail(): void
    {
        self::assertNull($this->digestFor($this->user('super_admin', 'root')));
    }

    public function testTheSuperAdminHearsOfEverything(): void
    {
        $this->overdueInvoice();
        $this->lowStockProduct();

        $digest = $this->digestFor($this->user('super_admin', 'root'));

        self::assertNotNull($digest);
        self::assertSame(['receivables', 'low_stock'], $digest['sections']);
        self::assertStringContainsString('SZLA-TEST-1', $digest['body']);
        self::assertStringContainsString('Késő Kft.', $digest['body']);
        self::assertStringContainsString('Fogyó termék', $digest['body']);
    }

    public function testTheWarehouseHearsOfStockButNotOfMoney(): void
    {
        $this->overdueInvoice();
        $this->lowStockProduct();

        $digest = $this->digestFor($this->user('warehouse', 'raktar'));

        self::assertNotNull($digest);
        self::assertSame(['low_stock'], $digest['sections']);
        self::assertStringNotContainsString('SZLA-TEST-1', $digest['body']);
    }

    public function testOnlyOnesOwnDueToDosAreListed(): void
    {
        $me = $this->user('super_admin', 'root');
        $other = $this->user('manager', 'mas');
        $insert = $this->pdo()->prepare('INSERT INTO todos (title, due_date, assigned_to, created_at) VALUES (:title, :due, :user, NOW())');
        $insert->execute(['title' => 'Felhívni a könyvelőt', 'due' => date('Y-m-d'), 'user' => $me]);
        $insert->execute(['title' => 'Holnapi dolog', 'due' => date('Y-m-d', strtotime('+1 day')), 'user' => $me]);
        $insert->execute(['title' => 'Másé', 'due' => date('Y-m-d'), 'user' => $other]);

        $digest = $this->digestFor($me);

        self::assertNotNull($digest);
        self::assertSame(['todos'], $digest['sections']);
        self::assertStringContainsString('Felhívni a könyvelőt', $digest['body']);
        self::assertStringNotContainsString('Holnapi dolog', $digest['body']);
        self::assertStringNotContainsString('Másé', $digest['body']);
    }
}
