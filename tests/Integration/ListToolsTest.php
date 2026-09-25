<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Account\SavedFilterModel;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Core\ProductModel;

/** The tools on top of the lists: saved filters, and bulk changes. */
final class ListToolsTest extends DatabaseTestCase
{
    public function testOnesOwnAndTheSharedFiltersAreSeenNotOthersPrivateOnes(): void
    {
        $me = $this->user('manager', 'anna');
        $other = $this->user('manager', 'bela');
        $filters = new SavedFilterModel();
        $filters->create($me, 'invoices', 'Mine', 'status=unpaid', false);
        $filters->create($other, 'invoices', 'Shared', 'status=paid', true);
        $filters->create($other, 'invoices', 'Private', 'q=x', false);
        $filters->create($me, 'products', 'Elsewhere', 'status=active', false);

        self::assertSame(['Mine', 'Shared'], array_column($filters->forPage('invoices', $me), 'name'));
        self::assertSame(['Private', 'Shared'], array_column($filters->forPage('invoices', $other), 'name'));
    }

    public function testBulkChangesTouchOnlyTheTickedRowsAndOnlyTheAllowedColumns(): void
    {
        $a = $this->product(100, ['sku' => 'A']);
        $b = $this->product(100, ['sku' => 'B']);
        $c = $this->product(100, ['sku' => 'C']);
        $category = $this->category('Kerékpár');
        $products = new ProductModel();

        self::assertSame(2, $products->bulkSet([$a, $b], 'is_active', 0));
        self::assertSame(0, (int) $this->scalar('SELECT is_active FROM products WHERE id = :id', ['id' => $a]));
        self::assertSame(1, (int) $this->scalar('SELECT is_active FROM products WHERE id = :id', ['id' => $c]));

        $products->bulkSet([$b, $c], 'category_id', $category);
        self::assertSame($category, (int) $this->scalar('SELECT category_id FROM products WHERE id = :id', ['id' => $b]));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM product_categories WHERE category_id = :c', ['c' => $category]));

        self::assertSame(0, $products->bulkSet([$a], 'price', 0), 'not a column bulk changes may write');
        self::assertSame(0, $products->bulkSet([], 'is_active', 1));
    }

    public function testPartnersGetACustomerGroupOrLoseIt(): void
    {
        $group = $this->customerGroup('Nagyker');
        $p1 = $this->partner('Egy Kft.');
        $p2 = $this->partner('Kettő Kft.');
        $partners = new PartnerModel();

        $partners->bulkSet([$p1, $p2], 'customer_group_id', $group);
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM partners WHERE customer_group_id = :g', ['g' => $group]));

        $partners->bulkSet([$p1], 'customer_group_id', null);
        self::assertNull($this->scalar('SELECT customer_group_id FROM partners WHERE id = :id', ['id' => $p1]));
    }
}
