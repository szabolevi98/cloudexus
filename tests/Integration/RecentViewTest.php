<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Account\RecentViewModel;

final class RecentViewTest extends DatabaseTestCase
{
    public function testTheLatestComeFirstWithTheirNames(): void
    {
        $me = $this->user('super_admin', 'root');
        $product = $this->product(100, ['sku' => 'P-1', 'name' => 'Kerékpár']);
        $partner = $this->partner('Vevő Kft.');
        $recent = new RecentViewModel();

        $recent->viewed($me, 'product', $product);
        $recent->viewed($me, 'partner', $partner);
        $this->pdo()->exec("UPDATE recent_views SET viewed_at = NOW() - INTERVAL 1 MINUTE WHERE kind = 'product'");

        $latest = $recent->latest($me, ['product', 'partner']);

        self::assertSame(['partner', 'product'], array_column($latest, 'kind'));
        self::assertSame('Vevő Kft.', $latest[0]['label']);
        self::assertSame('Kerékpár', $latest[1]['label']);
        self::assertSame('P-1', $latest[1]['hint']);
    }

    public function testOnlyTheKindsTheRoleMaySeeAndOnlyWhatStillExists(): void
    {
        $me = $this->user('warehouse', 'raktar');
        $recent = new RecentViewModel();
        $recent->viewed($me, 'product', $this->product(100, ['sku' => 'P-1']));
        $recent->viewed($me, 'partner', $this->partner('Titkos Kft.'));
        $gone = $this->product(100, ['sku' => 'P-2']);
        $recent->viewed($me, 'product', $gone);
        $this->pdo()->exec('DELETE FROM product_description WHERE product_id = ' . $gone);
        $this->pdo()->exec('DELETE FROM products WHERE id = ' . $gone);

        self::assertSame(['P-1'], array_column($recent->latest($me, ['product']), 'hint'));
        self::assertSame([], $recent->latest($me, []));
    }

    public function testOpeningAgainMovesItUpAndOnlyThirtyAreKept(): void
    {
        $me = $this->user('super_admin', 'root');
        $recent = new RecentViewModel();
        $partners = [];
        for ($i = 1; $i <= RecentViewModel::KEEP + 5; $i++) {
            $partners[$i] = $this->partner('Partner ' . $i);
            $recent->viewed($me, 'partner', $partners[$i]);
            $this->pdo()->exec('UPDATE recent_views SET viewed_at = viewed_at - INTERVAL 1 SECOND');
        }

        self::assertSame(RecentViewModel::KEEP, (int) $this->scalar('SELECT COUNT(*) FROM recent_views WHERE user_id = :u', ['u' => $me]));

        $recent->viewed($me, 'partner', $partners[20]);
        self::assertSame('Partner 20', $recent->latest($me, ['partner'], 1)[0]['label']);
    }
}
