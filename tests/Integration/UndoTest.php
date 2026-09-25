<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Core\Undo;

final class UndoTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->signIn($this->user('super_admin', 'root'));
    }

    private function captureProduct(int $id): void
    {
        Undo::capture('the product', '/products/' . $id . '/edit', [
            ['products', 'id', $id],
            ['product_description', 'product_id', $id],
            ['product_categories', 'product_id', $id],
            ['product_links', 'product_id', $id],
            ['product_links', 'linked_product_id', $id],
        ]);
    }

    public function testADeletedProductComesBackWithItsIdAndEverythingThatWentWithIt(): void
    {
        $id = $this->product(1000, ['sku' => 'P-1', 'name' => 'Kerékpár']);
        $other = $this->product(10, ['sku' => 'P-2']);
        $category = $this->category('Kerékpárok');
        $this->pdo()->exec("INSERT INTO product_categories (product_id, category_id) VALUES ($id, $category)");
        $this->pdo()->exec("INSERT INTO product_links (product_id, linked_product_id, link_type) VALUES ($other, $id, 'related')");

        $this->captureProduct($id);
        $this->pdo()->exec('DELETE FROM products WHERE id = ' . $id);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM product_links'));

        $offer = Undo::offer();
        self::assertNotNull($offer);
        self::assertNull(Undo::offer(), 'offered on the next page only');

        self::assertSame('/products/' . $id . '/edit', Undo::restore($offer['token']));
        self::assertSame('P-1', $this->scalar('SELECT sku FROM products WHERE id = :id', ['id' => $id]));
        self::assertSame('Kerékpár', $this->scalar('SELECT name FROM product_description WHERE product_id = :id', ['id' => $id]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM product_categories WHERE product_id = :id', ['id' => $id]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM product_links WHERE linked_product_id = :id', ['id' => $id]));

        self::assertNull(Undo::restore($offer['token']), 'once only');
    }

    public function testAPartnersToDosAreLinkedBackToIt(): void
    {
        $partner = $this->partner('Vevő Kft.');
        $this->pdo()->exec("INSERT INTO todos (title, partner_id, created_at) VALUES ('Felhívni', $partner, NOW())");

        Undo::capture('Vevő Kft.', '/partners/' . $partner, [['partners', 'id', $partner]], [['todos', 'partner_id', $partner]]);
        $this->pdo()->exec('DELETE FROM partners WHERE id = ' . $partner);
        self::assertNull($this->scalar("SELECT partner_id FROM todos WHERE title = 'Felhívni'"));

        Undo::restore((string) Undo::offer()['token']);

        self::assertSame($partner, (int) $this->scalar("SELECT partner_id FROM todos WHERE title = 'Felhívni'"));
    }

    public function testSomethingInItsPlaceOrTheTimeRunningOutRefusesIt(): void
    {
        $id = $this->product(1000, ['sku' => 'P-1']);
        $this->captureProduct($id);
        $this->pdo()->exec('DELETE FROM products WHERE id = ' . $id);
        $token = (string) Undo::offer()['token'];
        $this->product(500, ['sku' => 'P-1']);

        self::assertNull(Undo::restore($token), 'the SKU is taken again');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM products WHERE sku = 'P-1'"), 'and nothing half-restored');

        $second = $this->product(1000, ['sku' => 'P-3']);
        $this->captureProduct($second);
        $_SESSION['undo']['expires'] = time() - 1;
        self::assertNull(Undo::offer());
        self::assertNull(Undo::restore((string) $_SESSION['undo']['token']));
    }
}
