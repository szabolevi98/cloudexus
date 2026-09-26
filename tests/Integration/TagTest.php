<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Crm\DormantCustomerModel;
use Cloudexus\Model\Crm\TagModel;
use Cloudexus\Model\Crm\TodoModel;

final class TagTest extends DatabaseTestCase
{
    public function testTagsAreSetAddedAndDroppedWhenNobodyHasThem(): void
    {
        $tags = new TagModel();
        $a = $this->partner('Alfa Kft.');
        $b = $this->partner('Béta Bt.');

        $tags->setForPartner($a, [' VIP ', 'étterem', 'vip', '', str_repeat('x', 80)]);
        self::assertSame(['étterem', 'VIP', str_repeat('x', 60)], $tags->forPartner($a), 'trimmed, once, cut to 60');

        self::assertSame(2, $tags->addToPartners([$a, $b], 'Nagyker'));
        self::assertSame(0, $tags->addToPartners([$a], 'Nagyker'), 'already has it');
        self::assertContains('Nagyker', $tags->forPartner($b));

        $tags->setForPartner($a, ['VIP']);
        $names = array_column($tags->all(), 'name');
        self::assertNotContains('étterem', $names, 'nobody has it any more');
        self::assertContains('Nagyker', $names, 'Béta still has it');

        self::assertSame(1, $tags->removeFromPartners([$b], 'Nagyker'));
        self::assertNotContains('Nagyker', array_column($tags->all(), 'name'));
    }

    public function testThePartnerListFiltersByTag(): void
    {
        $tags = new TagModel();
        $a = $this->partner('Alfa Kft.');
        $this->partner('Béta Bt.');
        $tags->setForPartner($a, ['VIP']);
        $vip = (int) $this->scalar("SELECT id FROM tags WHERE name = 'VIP'");

        $pager = new \Cloudexus\Core\Paginator(25);
        $rows = (new PartnerModel())->paginate(['q' => '', 'type' => '', 'status' => '', 'tag_id' => $vip], $pager);

        self::assertSame(['Alfa Kft.'], array_column($rows, 'name'));
    }

    public function testDormantCustomersAreTheOnesWhoStoppedBuying(): void
    {
        $asleep = $this->partner('Régi Vevő Kft.');
        $recent = $this->partner('Friss Vevő Kft.');
        $never = $this->partner('Soha Nem Vett Kft.');
        $supplier = $this->partner('Beszállító Kft.', null, 'supplier');
        $order = fn(int $partner, string $date) => $this->pdo()->exec(
            "INSERT INTO orders (order_number, partner_id, status, order_date, total_amount, created_at)
             VALUES ('R-" . $partner . '-' . $date . "', $partner, 'confirmed', '$date', 1000, NOW())"
        );
        $order($asleep, date('Y-m-d', strtotime('-200 days')));
        $order($asleep, date('Y-m-d', strtotime('-120 days')));
        $order($recent, date('Y-m-d', strtotime('-10 days')));
        $order($supplier, date('Y-m-d', strtotime('-300 days')));
        (new TagModel())->setForPartner($asleep, ['Étterem']);

        $rows = (new DormantCustomerModel())->find(90);
        self::assertSame(['Régi Vevő Kft.'], array_column($rows, 'name'), 'not the recent one, not one who never bought, not a supplier');
        self::assertSame(2, $rows[0]['purchases']);
        self::assertSame(120, $rows[0]['days_since']);
        self::assertNull($rows[0]['todo_id']);
        self::assertSame([], (new DormantCustomerModel())->find(180));

        $tag = (int) $this->scalar("SELECT id FROM tags WHERE name = 'Étterem'");
        self::assertCount(1, (new DormantCustomerModel())->find(90, $tag));
        (new TagModel())->setForPartner($never, ['Más']);
        self::assertSame([], (new DormantCustomerModel())->find(90, (int) $this->scalar("SELECT id FROM tags WHERE name = 'Más'")));

        $todo = (new TodoModel())->create(['title' => 'Megkeresni', 'partner_id' => $asleep, 'due_date' => date('Y-m-d')]);
        $rows = (new DormantCustomerModel())->find(90);
        self::assertSame($todo, $rows[0]['todo_id']);
        self::assertSame(date('Y-m-d'), $rows[0]['todo_due']);
    }
}
