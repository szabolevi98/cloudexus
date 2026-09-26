<?php

namespace Cloudexus\Model\Crm;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;

/**
 * A teendők — lásd a 21_todo_details.sql migrációt a típusról, az időpontról,
 * az ismétlődésről és az üzlethez/ajánlathoz kötésről.
 */
class TodoModel
{
    /** Típus => Bootstrap-ikon. */
    public const TYPES = [
        'task' => 'bi-check2-square',
        'call' => 'bi-telephone',
        'email' => 'bi-envelope',
        'meeting' => 'bi-people',
    ];

    public const RECURRENCES = ['none', 'daily', 'weekly', 'monthly'];

    private const SELECT = 'SELECT t.*, p.name AS partner_name, u.full_name AS assigned_name,
                                   d.title AS deal_title, q.quote_number
                            FROM todos t
                            LEFT JOIN partners p ON p.id = t.partner_id
                            LEFT JOIN users u ON u.id = t.assigned_to
                            LEFT JOIN deals d ON d.id = t.deal_id
                            LEFT JOIN quotes q ON q.id = t.quote_id';

    /** Az időpont nélküliek a nap végére, a dátum nélküliek a lista végére. */
    private const ORDER = 't.is_done ASC, (t.due_date IS NULL), t.due_date ASC, (t.due_time IS NULL), t.due_time ASC, t.id DESC';

    /** Az én teendőm: rám van osztva, vagy senkire, de én vettem fel. */
    private const MINE = '(t.assigned_to = :me OR (t.assigned_to IS NULL AND t.created_by = :me2))';

    /** Filters: q (title), status ('open'|'done'), assigned_to, type. */
    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $where[] = 't.title LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (($filters['status'] ?? '') === 'open') {
            $where[] = 't.is_done = 0';
        } elseif (($filters['status'] ?? '') === 'done') {
            $where[] = 't.is_done = 1';
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = 't.assigned_to = :assigned_to';
            $params['assigned_to'] = (int) $filters['assigned_to'];
        }
        if (isset(self::TYPES[$filters['type'] ?? ''])) {
            $where[] = 't.type = :type';
            $params['type'] = $filters['type'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM todos t $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            self::SELECT . " $whereSql ORDER BY " . self::ORDER . " LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(self::SELECT . ' WHERE t.id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * A felhasználó nyitott teendői a vezérlőpultra: a lejártak és a maiak
     * elöl, aztán a következők.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(int $userId, int $limit = 8): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            self::SELECT . ' WHERE t.is_done = 0 AND ' . self::MINE . ' ORDER BY ' . self::ORDER . ' LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['me' => $userId, 'me2' => $userId]);

        return $stmt->fetchAll();
    }

    public function mineCount(int $userId): int
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT COUNT(*) FROM todos t WHERE t.is_done = 0 AND ' . self::MINE);
        $stmt->execute(['me' => $userId, 'me2' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Egy hét teendői naponként (hétfőtől vasárnapig), és a hét előttről
     * nyitva maradtak.
     *
     * @param ?int $userId csak az övéi (lásd MINE), vagy mindenkié
     * @return array{days: array<string, list<array<string, mixed>>>, overdue: list<array<string, mixed>>}
     */
    public function week(string $monday, ?int $userId): array
    {
        $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
        $mine = $userId !== null ? ' AND ' . self::MINE : '';
        $params = $userId !== null ? ['me' => $userId, 'me2' => $userId] : [];

        $stmt = DatabaseConnection::get()->prepare(
            self::SELECT . " WHERE t.due_date BETWEEN :from AND :to $mine ORDER BY t.due_date, (t.due_time IS NULL), t.due_time, t.is_done, t.id"
        );
        $stmt->execute($params + ['from' => $monday, 'to' => $sunday]);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[date('Y-m-d', strtotime($monday . " +$i days"))] = [];
        }
        foreach ($stmt->fetchAll() as $todo) {
            $days[$todo['due_date']][] = $todo;
        }

        $late = DatabaseConnection::get()->prepare(
            self::SELECT . " WHERE t.is_done = 0 AND t.due_date < :from AND t.due_date < CURDATE() $mine ORDER BY t.due_date, t.due_time LIMIT 50"
        );
        $late->execute($params + ['from' => $monday]);

        return ['days' => $days, 'overdue' => $late->fetchAll()];
    }

    /** @return list<array<string, mixed>> Az üzlethez tartozók, a nyitottak elöl. */
    public function forDeal(int $dealId): array
    {
        return $this->linkedTo('t.deal_id', $dealId);
    }

    /** @return list<array<string, mixed>> Az ajánlathoz tartozók, a nyitottak elöl. */
    public function forQuote(int $quoteId): array
    {
        return $this->linkedTo('t.quote_id', $quoteId);
    }

    /** @param array<string, mixed> $data lásd fields() */
    public function create(array $data): int
    {
        $fields = self::fields($data);
        $fields['created_by'] = $data['created_by'] ?? null;
        $fields['recurrence_parent_id'] = $data['recurrence_parent_id'] ?? null;

        $columns = array_keys($fields);
        DatabaseConnection::get()->prepare(
            'INSERT INTO todos (' . implode(', ', $columns) . ', created_at)
             VALUES (:' . implode(', :', $columns) . ', NOW())'
        )->execute($fields);

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    /** @param array<string, mixed> $data lásd fields() */
    public function update(int $id, array $data): void
    {
        $fields = self::fields($data);
        $set = implode(', ', array_map(static fn(string $c): string => "$c = :$c", array_keys($fields)));
        DatabaseConnection::get()->prepare("UPDATE todos SET $set WHERE id = :id")->execute($fields + ['id' => $id]);
    }

    /**
     * Kész/nyitott váltás. Egy ismétlődő teendő első késznek jelölésekor
     * létrejön a következő; ennek azonosítója a visszatérési érték.
     */
    public function toggle(int $id): ?int
    {
        $pdo = DatabaseConnection::get();
        $pdo->prepare(
            'UPDATE todos SET is_done = 1 - is_done,
                completed_at = CASE WHEN is_done = 1 THEN NOW() ELSE NULL END
             WHERE id = :id'
        )->execute(['id' => $id]);

        $todo = $this->find($id);
        if ($todo === null || !$todo['is_done'] || $todo['recurrence'] === 'none') {
            return null;
        }
        $already = $pdo->prepare('SELECT COUNT(*) FROM todos WHERE recurrence_parent_id = :id');
        $already->execute(['id' => $id]);
        if ((int) $already->fetchColumn() > 0) {
            return null;
        }

        return $this->create([
            'due_date' => self::nextDate((string) ($todo['due_date'] ?: date('Y-m-d')), (string) $todo['recurrence']),
            'recurrence_parent_id' => $id,
        ] + $todo);
    }

    /** A következő alkalom napja: egy nappal, héttel vagy hónappal később (a hónap végén a hónap utolsó napja). */
    public static function nextDate(string $date, string $recurrence): string
    {
        $from = new \DateTimeImmutable($date);

        return match ($recurrence) {
            'daily' => $from->modify('+1 day')->format('Y-m-d'),
            'weekly' => $from->modify('+1 week')->format('Y-m-d'),
            'monthly' => (static function () use ($from): string {
                $next = $from->modify('first day of next month');
                $day = min((int) $from->format('j'), (int) $next->format('t'));

                return $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $day)->format('Y-m-d');
            })(),
            default => $date,
        };
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM todos WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    private function linkedTo(string $column, int $id): array
    {
        $stmt = DatabaseConnection::get()->prepare(self::SELECT . " WHERE $column = :id ORDER BY " . self::ORDER);
        $stmt->execute(['id' => $id]);

        return $stmt->fetchAll();
    }

    /**
     * A tárolt mezők, a hiányzók alapértékével.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function fields(array $data): array
    {
        $type = (string) ($data['type'] ?? 'task');
        $recurrence = (string) ($data['recurrence'] ?? 'none');
        $time = (string) ($data['due_time'] ?? '');

        return [
            'title' => (string) $data['title'],
            'type' => isset(self::TYPES[$type]) ? $type : 'task',
            'note' => ($data['note'] ?? '') !== '' ? (string) $data['note'] : null,
            'due_date' => ($data['due_date'] ?? '') ?: null,
            'due_time' => preg_match('/^\d{2}:\d{2}/', $time) ? substr($time, 0, 5) : null,
            'recurrence' => in_array($recurrence, self::RECURRENCES, true) ? $recurrence : 'none',
            'partner_id' => ($data['partner_id'] ?? 0) ?: null,
            'deal_id' => ($data['deal_id'] ?? 0) ?: null,
            'quote_id' => ($data['quote_id'] ?? 0) ?: null,
            'assigned_to' => ($data['assigned_to'] ?? 0) ?: null,
        ];
    }
}
