<?php

namespace Cloudexus\Tests\Unit;

use Cloudexus\Core\Sort;
use PHPUnit\Framework\TestCase;

final class SortTest extends TestCase
{
    private const COLUMNS = ['name' => 'product_name', 'price' => 'p.price'];

    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testWithoutASortTheListKeepsItsOwnOrder(): void
    {
        $_GET = [];

        self::assertSame('name ASC', Sort::orderBy(self::COLUMNS, 'name ASC'));
        self::assertSame([], Sort::params());
    }

    public function testAKnownKeyOrdersByItsExpressionWithTheDefaultAsTieBreaker(): void
    {
        $_GET = ['sort' => 'price', 'dir' => 'desc'];

        self::assertSame('p.price DESC, name ASC', Sort::orderBy(self::COLUMNS, 'name ASC'));
        self::assertSame(['sort' => 'price', 'dir' => 'desc'], Sort::params());
    }

    public function testAnythingButDescIsAscending(): void
    {
        $_GET = ['sort' => 'price', 'dir' => 'sideways'];

        self::assertSame('p.price ASC, name ASC', Sort::orderBy(self::COLUMNS, 'name ASC'));
    }

    public function testAnUnknownOrMalformedKeyNeverReachesTheSql(): void
    {
        foreach (['stock', 'price; DROP TABLE products', 'p.price', ['price']] as $key) {
            $_GET = ['sort' => $key, 'dir' => 'asc'];

            self::assertSame('name ASC', Sort::orderBy(self::COLUMNS, 'name ASC'));
        }
    }

    public function testAHeaderCyclesAscendingDescendingAndOff(): void
    {
        $_GET = ['q' => 'bike', 'page' => '3'];
        self::assertSame('?q=bike&sort=price&dir=asc', Sort::url('price'), 'first click: ascending, back to page 1');

        $_GET = ['q' => 'bike', 'sort' => 'price', 'dir' => 'asc', 'page' => '2'];
        self::assertSame('?q=bike&sort=price&dir=desc', Sort::url('price'), 'second click: descending');

        $_GET = ['q' => 'bike', 'sort' => 'price', 'dir' => 'desc'];
        self::assertSame('?q=bike', Sort::url('price'), 'third click: the list\'s own order again');

        $_GET = ['sort' => 'price', 'dir' => 'desc'];
        self::assertSame('?sort=name&dir=asc', Sort::url('name'), 'another column starts ascending');
    }

    public function testTheStateShowsOnlyOnTheSortedColumn(): void
    {
        $_GET = ['sort' => 'price', 'dir' => 'desc'];

        self::assertSame('desc', Sort::state('price'));
        self::assertNull(Sort::state('name'));
        self::assertSame('aria-sort="descending"', Sort::aria('price'));
        self::assertSame('', Sort::aria('name'));
        self::assertStringContainsString('is-sorted', Sort::link('price', 'Ár'));
        self::assertStringContainsString('bi-caret-down-fill', Sort::link('price', 'Ár'));
        self::assertStringNotContainsString('is-sorted', Sort::link('name', 'Név'));
    }

    public function testTheLinkAndTheInputsAreEscaped(): void
    {
        $_GET = ['q' => '"><script>', 'sort' => 'price', 'dir' => 'asc'];

        self::assertStringNotContainsString('<script>', Sort::link('price', '<b>Ár</b>'));
        self::assertStringContainsString('&lt;b&gt;Ár&lt;/b&gt;', Sort::link('price', '<b>Ár</b>'));
        self::assertSame(
            '<input type="hidden" name="sort" value="price"><input type="hidden" name="dir" value="asc">',
            Sort::inputs()
        );
    }
}
