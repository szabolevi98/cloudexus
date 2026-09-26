<?php

namespace Cloudexus\Model\Crm;

use Cloudexus\Core\DatabaseConnection;

/**
 * A CRM riport számai egy időszakra: a folyamat és az előrejelzése, a
 * megnyert és elvesztett üzletek, az ajánlatokból lett rendelések aránya,
 * a tevékenységek, és mindez értékesítőnként.
 *
 * Az időszak két dátum, mindkettő benne van. A nyitott üzletek (folyamat,
 * előrejelzés) a mai állapotot mutatják, nem az időszakét.
 */
class CrmReportModel
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::get();
    }

    /** Az üzlet valószínűsége SQL-ben: a sajátja, vagy a szakaszé (lásd DealModel::probability). */
    private static function chance(string $alias = 'd'): string
    {
        $cases = '';
        foreach (DealModel::STAGES as $stage => $percent) {
            $cases .= " WHEN '$stage' THEN $percent";
        }

        return "(CASE WHEN $alias.stage IN ('lead','qualified','proposal','negotiation') AND $alias.probability IS NOT NULL
                      THEN $alias.probability ELSE CASE $alias.stage$cases END END)";
    }

    /** @return array{count: int, amount: float, weighted: float} A nyitott üzletek most. */
    public function pipeline(): array
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS n, COALESCE(SUM(d.amount), 0) AS amount, COALESCE(SUM(d.amount * ' . self::chance() . " / 100), 0) AS weighted
             FROM deals d WHERE d.stage IN ('lead','qualified','proposal','negotiation')"
        )->fetch();

        return ['count' => (int) $row['n'], 'amount' => (float) $row['amount'], 'weighted' => round((float) $row['weighted'], 2)];
    }

    /**
     * A nyitott üzletek a várható lezárásuk hónapja szerint: ami már elmúlt,
     * az idei hónap és a következő öt, ami később, és aminek nincs dátuma.
     *
     * @return list<array{key: string, count: int, amount: float, weighted: float}>
     */
    public function forecast(int $months = 6): array
    {
        $first = date('Y-m-01');
        $buckets = ['overdue' => null];
        for ($i = 0; $i < $months; $i++) {
            $buckets[date('Y-m', strtotime("$first +$i months"))] = null;
        }
        $buckets['later'] = null;
        $buckets['none'] = null;
        $end = date('Y-m-d', strtotime("$first +$months months"));

        $stmt = $this->db->prepare(
            "SELECT CASE WHEN d.expected_close IS NULL THEN 'none'
                         WHEN d.expected_close < CURDATE() THEN 'overdue'
                         WHEN d.expected_close >= :end THEN 'later'
                         ELSE DATE_FORMAT(d.expected_close, '%Y-%m') END AS bucket,
                    COUNT(*) AS n, SUM(d.amount) AS amount, SUM(d.amount * " . self::chance() . " / 100) AS weighted
             FROM deals d WHERE d.stage IN ('lead','qualified','proposal','negotiation')
             GROUP BY bucket"
        );
        $stmt->execute(['end' => $end]);
        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['bucket']] = $row;
        }

        $rows = [];
        foreach (array_keys($buckets) as $key) {
            $row = $found[$key] ?? null;
            $rows[] = [
                'key' => (string) $key,
                'count' => (int) ($row['n'] ?? 0),
                'amount' => (float) ($row['amount'] ?? 0),
                'weighted' => round((float) ($row['weighted'] ?? 0), 2),
            ];
        }

        return $rows;
    }

    /** @return array{won: int, won_amount: float, lost: int, lost_amount: float, win_rate: ?float} Az időszakban lezárt üzletek. */
    public function closed(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(stage = 'won'), 0) AS won, COALESCE(SUM(IF(stage = 'won', amount, 0)), 0) AS won_amount,
                    COALESCE(SUM(stage = 'lost'), 0) AS lost, COALESCE(SUM(IF(stage = 'lost', amount, 0)), 0) AS lost_amount
             FROM deals WHERE stage IN ('won', 'lost') AND closed_at >= :from AND closed_at < :to + INTERVAL 1 DAY"
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        $row = $stmt->fetch();
        $won = (int) $row['won'];
        $lost = (int) $row['lost'];

        return [
            'won' => $won, 'won_amount' => (float) $row['won_amount'],
            'lost' => $lost, 'lost_amount' => (float) $row['lost_amount'],
            'win_rate' => $won + $lost > 0 ? round($won / ($won + $lost) * 100, 1) : null,
        ];
    }

    /** @return list<array{reason: string, count: int, amount: float}> Miért veszítettünk az időszakban, a leggyakoribb elöl. */
    public function lostReasons(string $from, string $to, int $limit = 8): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(NULLIF(TRIM(lost_reason), ''), '—') AS reason, COUNT(*) AS n, SUM(amount) AS amount
             FROM deals WHERE stage = 'lost' AND closed_at >= :from AND closed_at < :to + INTERVAL 1 DAY
             GROUP BY reason ORDER BY n DESC, amount DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute(['from' => $from, 'to' => $to]);

        return array_map(static fn(array $r): array => ['reason' => (string) $r['reason'], 'count' => (int) $r['n'], 'amount' => (float) $r['amount']], $stmt->fetchAll());
    }

    /**
     * Az időszakban kiadott ajánlatok, és hogy mennyiből lett rendelés.
     *
     * @return array{count: int, amount: float, ordered: int, ordered_amount: float, rejected: int, open: int, rate: ?float}
     */
    public function quotes(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(net_total), 0) AS amount,
                    COALESCE(SUM(status = 'ordered'), 0) AS ordered, COALESCE(SUM(IF(status = 'ordered', net_total, 0)), 0) AS ordered_amount,
                    COALESCE(SUM(status = 'rejected'), 0) AS rejected,
                    COALESCE(SUM(status IN ('draft', 'sent', 'accepted')), 0) AS open
             FROM quotes WHERE quote_date BETWEEN :from AND :to"
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        $row = $stmt->fetch();
        $count = (int) $row['n'];

        return [
            'count' => $count, 'amount' => (float) $row['amount'],
            'ordered' => (int) $row['ordered'], 'ordered_amount' => (float) $row['ordered_amount'],
            'rejected' => (int) $row['rejected'], 'open' => (int) $row['open'],
            'rate' => $count > 0 ? round((int) $row['ordered'] / $count * 100, 1) : null,
        ];
    }

    /**
     * A partnereknél rögzített tevékenységek és a kész teendők fajtánként.
     *
     * @return array{activities: array<string, int>, todos: array<string, int>}
     */
    public function activities(string $from, string $to): array
    {
        $params = ['from' => $from, 'to' => $to];
        $a = $this->db->prepare('SELECT type, COUNT(*) AS n FROM partner_activities WHERE activity_date >= :from AND activity_date < :to + INTERVAL 1 DAY GROUP BY type');
        $a->execute($params);
        $t = $this->db->prepare('SELECT type, COUNT(*) AS n FROM todos WHERE is_done = 1 AND completed_at >= :from AND completed_at < :to + INTERVAL 1 DAY GROUP BY type');
        $t->execute($params);

        return [
            'activities' => array_map('intval', array_column($a->fetchAll(), 'n', 'type')),
            'todos' => array_map('intval', array_column($t->fetchAll(), 'n', 'type')),
        ];
    }

    /**
     * Értékesítőnként: nyitott üzletek, az időszakban megnyert és elvesztett,
     * kiadott ajánlatok és az azokból lett rendelések, tevékenységek, kész és
     * most lejárt teendők. Csak aki valamiben szerepel; a felelős nélküli
     * üzletek egy külön sorban (user_id null).
     *
     * @return list<array<string, mixed>>
     */
    public function bySalesperson(string $from, string $to): array
    {
        $params = ['from' => $from, 'to' => $to];
        $rows = [];
        $merge = function (string $sql, array $params) use (&$rows): void {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $key = $r['uid'] === null ? 'none' : (string) $r['uid'];
                unset($r['uid']);
                $rows[$key] = array_merge($rows[$key] ?? [], $r);
            }
        };

        $merge(
            'SELECT owner_id AS uid, COUNT(*) AS open_deals, SUM(d.amount * ' . self::chance() . " / 100) AS open_weighted
             FROM deals d WHERE d.stage IN ('lead','qualified','proposal','negotiation') GROUP BY owner_id",
            []
        );
        $merge(
            "SELECT owner_id AS uid, SUM(stage = 'won') AS won, SUM(IF(stage = 'won', amount, 0)) AS won_amount, SUM(stage = 'lost') AS lost
             FROM deals WHERE stage IN ('won', 'lost') AND closed_at >= :from AND closed_at < :to + INTERVAL 1 DAY GROUP BY owner_id",
            $params
        );
        $merge(
            "SELECT created_by AS uid, COUNT(*) AS quotes, SUM(status = 'ordered') AS quotes_ordered
             FROM quotes WHERE quote_date BETWEEN :from AND :to AND created_by IS NOT NULL GROUP BY created_by",
            $params
        );
        $merge(
            'SELECT created_by AS uid, COUNT(*) AS activities FROM partner_activities
             WHERE activity_date >= :from AND activity_date < :to + INTERVAL 1 DAY AND created_by IS NOT NULL GROUP BY created_by',
            $params
        );
        $merge(
            'SELECT COALESCE(assigned_to, created_by) AS uid, COUNT(*) AS todos_done FROM todos
             WHERE is_done = 1 AND completed_at >= :from AND completed_at < :to + INTERVAL 1 DAY AND COALESCE(assigned_to, created_by) IS NOT NULL
             GROUP BY COALESCE(assigned_to, created_by)',
            $params
        );
        $merge(
            'SELECT COALESCE(assigned_to, created_by) AS uid, COUNT(*) AS todos_late FROM todos
             WHERE is_done = 0 AND due_date < CURDATE() AND COALESCE(assigned_to, created_by) IS NOT NULL
             GROUP BY COALESCE(assigned_to, created_by)',
            []
        );

        $names = array_column($this->db->query('SELECT id, full_name FROM users')->fetchAll(), 'full_name', 'id');
        $result = [];
        foreach ($rows as $key => $r) {
            if ($key !== 'none' && !isset($names[(int) $key])) {
                continue;
            }
            $won = (int) ($r['won'] ?? 0);
            $lost = (int) ($r['lost'] ?? 0);
            $quotes = (int) ($r['quotes'] ?? 0);
            $result[] = [
                'user_id' => $key === 'none' ? null : (int) $key,
                'name' => $key === 'none' ? null : (string) $names[(int) $key],
                'open_deals' => (int) ($r['open_deals'] ?? 0),
                'open_weighted' => round((float) ($r['open_weighted'] ?? 0), 2),
                'won' => $won,
                'won_amount' => (float) ($r['won_amount'] ?? 0),
                'lost' => $lost,
                'win_rate' => $won + $lost > 0 ? round($won / ($won + $lost) * 100, 1) : null,
                'quotes' => $quotes,
                'quotes_ordered' => (int) ($r['quotes_ordered'] ?? 0),
                'quote_rate' => $quotes > 0 ? round((int) ($r['quotes_ordered'] ?? 0) / $quotes * 100, 1) : null,
                'activities' => (int) ($r['activities'] ?? 0),
                'todos_done' => (int) ($r['todos_done'] ?? 0),
                'todos_late' => (int) ($r['todos_late'] ?? 0),
            ];
        }
        // A legtöbbet nyerők elöl; a felelős nélküli sor a végén.
        usort($result, static fn(array $a, array $b): int => [$a['user_id'] === null, -$a['won_amount'], -$a['open_weighted']] <=> [$b['user_id'] === null, -$b['won_amount'], -$b['open_weighted']]);

        return $result;
    }
}
