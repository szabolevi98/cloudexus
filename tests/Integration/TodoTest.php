<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Crm\TodoModel;

final class TodoTest extends DatabaseTestCase
{
    public function testTheNextDateOfARepeatingToDo(): void
    {
        self::assertSame('2026-09-27', TodoModel::nextDate('2026-09-26', 'daily'));
        self::assertSame('2026-10-03', TodoModel::nextDate('2026-09-26', 'weekly'));
        self::assertSame('2026-10-26', TodoModel::nextDate('2026-09-26', 'monthly'));
        self::assertSame('2026-02-28', TodoModel::nextDate('2026-01-31', 'monthly'), 'the last day of a shorter month');
        self::assertSame('2026-03-28', TodoModel::nextDate('2026-02-28', 'monthly'), 'the day of the month is kept, not the month end');
    }

    public function testDoingARepeatingToDoMakesTheNextOneOnce(): void
    {
        $todos = new TodoModel();
        $partner = $this->partner();
        $id = $todos->create([
            'title' => 'Heti egyeztetés', 'type' => 'call', 'due_date' => '2026-09-21', 'due_time' => '09:30',
            'recurrence' => 'weekly', 'partner_id' => $partner, 'note' => 'Hétfőnként',
        ]);

        $next = $todos->toggle($id);
        self::assertNotNull($next);
        $made = $todos->find($next);
        self::assertSame('2026-09-28', $made['due_date']);
        self::assertSame('09:30:00', $made['due_time']);
        self::assertSame('call', $made['type']);
        self::assertSame('weekly', $made['recurrence']);
        self::assertSame($partner, (int) $made['partner_id']);
        self::assertSame(0, (int) $made['is_done']);
        self::assertNotNull($todos->find($id)['completed_at']);

        self::assertNull($todos->toggle($id), 'reopened');
        self::assertNull($todos->find($id)['completed_at']);
        self::assertNull($todos->toggle($id), 'done again: no second copy');
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM todos'));

        $once = $todos->create(['title' => 'Egyszeri', 'due_date' => '2026-09-21']);
        self::assertNull($todos->toggle($once));
    }

    public function testTheWeekAndMyOwn(): void
    {
        $me = $this->user('viewer', 'en');
        $other = $this->user('viewer', 'masik');
        $todos = new TodoModel();
        $todos->create(['title' => 'Hétfő délután', 'due_date' => '2026-09-21', 'due_time' => '14:00', 'assigned_to' => $me]);
        $todos->create(['title' => 'Hétfő reggel', 'due_date' => '2026-09-21', 'due_time' => '08:00', 'assigned_to' => $me]);
        $todos->create(['title' => 'Vasárnap', 'due_date' => '2026-09-27', 'created_by' => $me]);
        $todos->create(['title' => 'A másiké', 'due_date' => '2026-09-23', 'assigned_to' => $other, 'created_by' => $me]);
        $todos->create(['title' => 'Régi', 'due_date' => '2026-09-01', 'assigned_to' => $me]);

        $week = $todos->week('2026-09-21', $me);
        self::assertCount(7, $week['days']);
        self::assertSame(['Hétfő reggel', 'Hétfő délután'], array_column($week['days']['2026-09-21'], 'title'));
        self::assertSame(['Vasárnap'], array_column($week['days']['2026-09-27'], 'title'), 'unassigned, but mine');
        self::assertSame([], $week['days']['2026-09-23'], 'assigned to someone else');
        self::assertSame(['Régi'], array_column($week['overdue'], 'title'));

        self::assertCount(1, $todos->week('2026-09-21', null)['days']['2026-09-23'], 'everyone');
        self::assertSame(4, $todos->mineCount($me));
        self::assertSame('Régi', $todos->mine($me)[0]['title'], 'the oldest first');
    }
}
