<?php

namespace Cloudexus\Tests\Unit;

use Cloudexus\Core\Quantity;
use Cloudexus\Model\Core\StockShortage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    /** @return iterable<array{mixed, string}> */
    public static function quantities(): iterable
    {
        yield 'whole' => [12, '12'];
        yield 'one decimal' => [2.5, '2,5'];
        yield 'three decimals' => [0.125, '0,125'];
        yield 'trailing zeros go' => ['3.500', '3,5'];
        yield 'thousands' => [12345.75, '12 345,75'];
        yield 'rounded to three' => [1.23456, '1,235'];
        yield 'zero' => [0, '0'];
        yield 'null' => [null, '0'];
        yield 'negative' => [-4.2, '-4,2'];
        yield 'tiny negative is zero' => [-0.0001, '0'];
    }

    #[DataProvider('quantities')]
    public function testFormat(mixed $in, string $out): void
    {
        self::assertSame($out, Quantity::format($in));
    }

    public function testShortageKeepsEveryProductAndNamesTheFirst(): void
    {
        $e = new StockShortage([7 => ['available' => 1.0, 'requested' => 3.0], 9 => ['available' => 0.0, 'requested' => 2.0]]);

        self::assertSame(['available' => 1.0, 'requested' => 3.0], $e->first());
        self::assertSame([7, 9], array_keys($e->shortages));
        self::assertInstanceOf(\DomainException::class, $e);
    }
}
