<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Sort;

/** Az audit napló olvasása. Írni csak a Core\AuditLog ír bele. */
class AuditLogModel
{
    /** Sortable columns of the list (see Sort): key => SQL expression. */
    public const SORTS = [
        'created_at' => 'a.created_at',
        'user' => 'a.user_name',
        'action' => 'a.action',
        'entity' => 'a.entity_type',
        'subject' => 'a.label',
        'ip' => 'a.ip',
    ];

    /** @return list<array<string, mixed>> */
    public function paginate(array $filters, Paginator $pager): array
    {
        [$where, $params] = $this->where($filters);

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM audit_log a $where");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT a.* FROM audit_log a $where ORDER BY " . Sort::orderBy(self::SORTS, 'a.id DESC') . " LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return array_map(static function (array $row): array {
            $row['details'] = $row['details'] !== null ? (json_decode($row['details'], true) ?? $row['details']) : null;
            return $row;
        }, $stmt->fetchAll());
    }

    /** Ennyi $action sor jött erről az IP-ről az elmúlt $seconds másodpercben. */
    public function countRecentFromIp(string $action, string $ip, int $seconds): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT COUNT(*) FROM audit_log WHERE ip = :ip AND action = :action AND created_at >= NOW() - INTERVAL :seconds SECOND'
        );
        $stmt->bindValue('ip', $ip);
        $stmt->bindValue('action', $action);
        $stmt->bindValue('seconds', $seconds, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, string> a naplóban szereplő felhasználók, a szűrőhöz */
    public function users(): array
    {
        $rows = DatabaseConnection::get()->query(
            'SELECT user_id, MAX(user_name) AS user_name FROM audit_log WHERE user_id IS NOT NULL GROUP BY user_id ORDER BY user_name'
        )->fetchAll();

        return array_column($rows, 'user_name', 'user_id');
    }

    /** @return list<string> */
    public function entityTypes(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT DISTINCT entity_type FROM audit_log WHERE entity_type IS NOT NULL ORDER BY entity_type'
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function where(array $filters): array
    {
        $clauses = [];
        $params = [];

        if ($filters['q'] !== '') {
            $clauses[] = '(a.label LIKE :q1 OR a.user_name LIKE :q2 OR a.details LIKE :q3 OR a.ip LIKE :q4)';
            foreach (['q1', 'q2', 'q3', 'q4'] as $k) {
                $params[$k] = '%' . $filters['q'] . '%';
            }
        }
        if ($filters['user_id'] !== '') {
            $clauses[] = 'a.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if ($filters['action'] !== '') {
            $clauses[] = 'a.action = :action';
            $params['action'] = $filters['action'];
        }
        if ($filters['entity_type'] !== '') {
            $clauses[] = 'a.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if ($filters['from'] !== '') {
            $clauses[] = 'a.created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            $clauses[] = 'a.created_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
