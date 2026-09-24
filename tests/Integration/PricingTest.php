<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Core\PriceRuleModel;
use Cloudexus\Model\Core\ProductModel;

final class PricingTest extends DatabaseTestCase
{
    private const BASE = ['customer_group_id' => 0, 'product_id' => 0, 'category_id' => 0, 'min_quantity' => 0,
                          'discount_percent' => null, 'fixed_price' => null, 'valid_from' => null, 'valid_to' => null, 'is_active' => 1];

    private function rule(array $rule): int
    {
        return (new PriceRuleModel())->create($rule + ['name' => 'Szabály'] + self::BASE);
    }

    private function price(int $product, ?int $partner, float $qty = 1, ?string $date = null, bool $rules = true): array
    {
        return (new ProductModel())->effectivePrice($product, $partner, $qty, $date, $rules);
    }

    public function testWithoutRulesTheListPriceOrTheCheaperSalePriceApplies(): void
    {
        self::assertSame(10000.0, $this->price($this->product(10000), null)['price']);

        $sale = $this->price($this->product(10000, ['sale_price' => 8000]), null);
        self::assertSame([8000.0, true, null], [$sale['price'], $sale['is_sale'], $sale['rule']]);
    }

    public function testAGroupDiscountOnlyReachesTheGroup(): void
    {
        $group = $this->customerGroup('Viszonteladó');
        $product = $this->product(10000);
        $rule = $this->rule(['customer_group_id' => $group, 'discount_percent' => 10]);

        self::assertNull($this->price($product, $this->partner('Kívülálló'))['rule']);
        $inside = $this->price($product, $this->partner('Tag', $group));
        self::assertSame([9000.0, $rule], [$inside['price'], $inside['rule']['id']]);
    }

    public function testAQuantityBreakOnAParentCategoryCoversItsSubcategories(): void
    {
        $bikes = $this->category('Kerékpár');
        $city = $this->category('Városi', $bikes);
        $product = $this->product(10000, ['category_id' => $city]);
        $rule = $this->rule(['category_id' => $bikes, 'min_quantity' => 10, 'discount_percent' => 15]);

        self::assertNull($this->price($product, null, 9)['rule']);
        self::assertSame($rule, $this->price($product, null, 10)['rule']['id']);
        self::assertSame(8500.0, $this->price($product, null, 10)['price']);
    }

    public function testTheLowestPriceWinsAndRulesDoNotAddUp(): void
    {
        $group = $this->customerGroup('VIP');
        $product = $this->product(10000);
        $this->rule(['customer_group_id' => $group, 'discount_percent' => 10]);
        $best = $this->rule(['min_quantity' => 5, 'discount_percent' => 15]);

        $result = $this->price($product, $this->partner('VIP Kft.', $group), 5);
        self::assertSame([8500.0, $best], [$result['price'], $result['rule']['id']], '-15 %, not -25 %');
    }

    public function testAPromotionIsLiveOnlyInsideItsDates(): void
    {
        $product = $this->product(10000);
        $rule = $this->rule(['product_id' => $product, 'fixed_price' => 7000, 'valid_from' => '2026-10-01', 'valid_to' => '2026-10-31']);

        self::assertNull($this->price($product, null, 1, '2026-09-30')['rule']);
        self::assertSame($rule, $this->price($product, null, 1, '2026-10-01')['rule']['id']);
        self::assertSame($rule, $this->price($product, null, 1, '2026-10-31')['rule']['id'], 'the last day counts');
        self::assertNull($this->price($product, null, 1, '2026-11-01')['rule']);
    }

    public function testASwitchedOffRuleAndBasePricingIgnoreRules(): void
    {
        $product = $this->product(10000);
        $this->rule(['product_id' => $product, 'discount_percent' => 50, 'is_active' => 0]);
        self::assertNull($this->price($product, null)['rule']);

        $this->rule(['product_id' => $product, 'discount_percent' => 50]);
        self::assertSame(10000.0, $this->price($product, null, 1, null, false)['price'], 'purchase forms take the plain price');
    }

    public function testTheGroupPriceIsTheListPriceThePercentageComesOff(): void
    {
        $group = $this->customerGroup('Nagyker');
        $product = $this->product(10000);
        $this->pdo()->prepare('INSERT INTO product_group_prices (product_id, customer_group_id, price) VALUES (:p, :g, 9000)')
            ->execute(['p' => $product, 'g' => $group]);
        $this->rule(['customer_group_id' => $group, 'discount_percent' => 10]);

        $result = $this->price($product, $this->partner('Nagyker Kft.', $group));
        self::assertSame([9000.0, 8100.0], [$result['list_price'], $result['price']]);
    }
}
