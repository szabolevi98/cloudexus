<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Controller\ReportController;
use Cloudexus\Model\Crm\CrmReportModel;
use Cloudexus\Model\Crm\DealModel;
use Cloudexus\Model\Crm\TodoModel;

final class CrmReportTest extends DatabaseTestCase
{
    /** @param array<string, mixed> $extra */
    private function deal(int $partner, array $extra): int
    {
        return (new DealModel())->create($extra + [
            'title' => 'Üzlet', 'partner_id' => $partner, 'stage' => 'lead', 'amount' => 100000.0,
            'probability' => null, 'expected_close' => null, 'owner_id' => null, 'note' => '',
        ]);
    }

    public function testThePeriodPresets(): void
    {
        self::assertSame(['month', '2026-09-01', '2026-09-26'], ReportController::period('month', '', '', '2026-09-26'));
        self::assertSame(['last30', '2026-08-28', '2026-09-26'], ReportController::period('last30', '', '', '2026-09-26'));
        self::assertSame(['quarter', '2026-07-01', '2026-09-26'], ReportController::period('quarter', '', '', '2026-09-26'));
        self::assertSame(['quarter', '2026-10-01', '2026-11-02'], ReportController::period('quarter', '', '', '2026-11-02'));
        self::assertSame(['year', '2026-01-01', '2026-09-26'], ReportController::period('year', '', '', '2026-09-26'));
        self::assertSame(['custom', '2026-03-01', '2026-03-31'], ReportController::period('custom', '2026-03-01', '2026-03-31', '2026-09-26'));
        self::assertSame('month', ReportController::period('custom', '2026-03-31', '2026-03-01', '2026-09-26')[0], 'backwards');
        self::assertSame('month', ReportController::period('custom', 'nope', '2026-03-01', '2026-09-26')[0]);
    }

    public function testThePipelineForecastAndClosedDeals(): void
    {
        $partner = $this->partner();
        $nextMonth = date('Y-m-15', strtotime('first day of next month'));
        $this->deal($partner, ['stage' => 'proposal', 'amount' => 200000.0, 'expected_close' => $nextMonth]);
        $this->deal($partner, ['stage' => 'negotiation', 'amount' => 100000.0, 'probability' => 90, 'expected_close' => date('Y-m-d', strtotime('-3 days'))]);
        $this->deal($partner, ['stage' => 'lead', 'amount' => 50000.0]);
        $won = $this->deal($partner, ['stage' => 'negotiation', 'amount' => 300000.0]);
        (new DealModel())->move($won, 'won');
        $lost = $this->deal($partner, ['stage' => 'proposal', 'amount' => 80000.0]);
        (new DealModel())->move($lost, 'lost', [], 'Drága');
        $old = $this->deal($partner, ['stage' => 'proposal']);
        (new DealModel())->move($old, 'lost', [], 'Régi ok');
        $this->pdo()->exec("UPDATE deals SET closed_at = NOW() - INTERVAL 400 DAY WHERE id = $old");

        $report = new CrmReportModel();
        $pipeline = $report->pipeline();
        self::assertSame(3, $pipeline['count']);
        self::assertSame(350000.0, $pipeline['amount']);
        self::assertSame(195000.0, $pipeline['weighted'], '50% of 200 000 + 90% of 100 000 + 10% of 50 000');

        $forecast = array_column($report->forecast(), null, 'key');
        self::assertSame(1, $forecast['overdue']['count']);
        self::assertSame(1, $forecast[substr($nextMonth, 0, 7)]['count']);
        self::assertSame(100000.0, $forecast[substr($nextMonth, 0, 7)]['weighted']);
        self::assertSame(1, $forecast['none']['count']);
        self::assertCount(9, $forecast, 'overdue, six months, later, none');

        $closed = $report->closed(date('Y-m-01'), date('Y-m-d'));
        self::assertSame(1, $closed['won']);
        self::assertSame(300000.0, $closed['won_amount']);
        self::assertSame(1, $closed['lost'], 'not the one lost a year ago');
        self::assertSame(50.0, $closed['win_rate']);
        self::assertSame([['reason' => 'Drága', 'count' => 1, 'amount' => 80000.0]], $report->lostReasons(date('Y-m-01'), date('Y-m-d')));
    }

    public function testQuotesActivitiesAndEachSalesperson(): void
    {
        $anna = $this->user('viewer', 'anna');
        $bela = $this->user('viewer', 'bela');
        $partner = $this->partner();
        $today = date('Y-m-d');
        $this->pdo()->exec("INSERT INTO quotes (quote_number, partner_id, status, quote_date, valid_until, net_total, created_by, created_at) VALUES
            ('AJ-1', $partner, 'ordered', CURDATE(), CURDATE(), 1000, $anna, NOW()),
            ('AJ-2', $partner, 'rejected', CURDATE(), CURDATE(), 2000, $anna, NOW()),
            ('AJ-3', $partner, 'sent', CURDATE(), CURDATE(), 3000, $bela, NOW()),
            ('AJ-4', $partner, 'ordered', CURDATE() - INTERVAL 400 DAY, CURDATE(), 4000, $bela, NOW())");
        $this->pdo()->exec("INSERT INTO partner_activities (partner_id, type, subject, activity_date, created_by, created_at) VALUES
            ($partner, 'call', 'Hívás', NOW(), $anna, NOW()), ($partner, 'call', 'Hívás', NOW(), $anna, NOW()), ($partner, 'meeting', 'Találkozó', NOW(), $bela, NOW())");
        $todos = new TodoModel();
        $done = $todos->create(['title' => 'Kész', 'type' => 'call', 'assigned_to' => $anna, 'due_date' => $today]);
        $todos->toggle($done);
        $todos->create(['title' => 'Lejárt', 'assigned_to' => $bela, 'due_date' => date('Y-m-d', strtotime('-2 days'))]);
        $won = $this->deal($partner, ['stage' => 'negotiation', 'amount' => 500000.0, 'owner_id' => $anna]);
        (new DealModel())->move($won, 'won');
        $this->deal($partner, ['stage' => 'proposal', 'amount' => 100000.0, 'owner_id' => $bela]);
        $this->deal($partner, ['stage' => 'lead', 'amount' => 100000.0]);

        $report = new CrmReportModel();
        $quotes = $report->quotes(date('Y-m-01'), $today);
        self::assertSame(3, $quotes['count']);
        self::assertSame(1, $quotes['ordered']);
        self::assertSame(1, $quotes['open']);
        self::assertSame(33.3, $quotes['rate']);

        $activities = $report->activities(date('Y-m-01'), $today);
        self::assertSame(['call' => 2, 'meeting' => 1], $activities['activities']);
        self::assertSame(['call' => 1], $activities['todos']);

        $people = $report->bySalesperson(date('Y-m-01'), $today);
        self::assertSame(['Anna', 'Bela', null], array_column($people, 'name'), 'the most won first, no owner last');
        [$a, $b, $none] = $people;
        self::assertSame(1, $a['won']);
        self::assertSame(500000.0, $a['won_amount']);
        self::assertSame(2, $a['quotes']);
        self::assertSame(50.0, $a['quote_rate']);
        self::assertSame(2, $a['activities']);
        self::assertSame(1, $a['todos_done']);
        self::assertSame(1, $b['open_deals']);
        self::assertSame(50000.0, $b['open_weighted']);
        self::assertSame(1, $b['todos_late']);
        self::assertSame(1, $none['open_deals']);
    }
}
