<?php

namespace Cloudexus\Model\Account;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;

/** API kérés-napló: rate limit számításához és biztonsági visszakereséshez. */
class ApiRequestLogModel
{
    /**
     * @param array{api_user_id?: int, outcome?: string, date_from?: string, date_to?: string} $filters
     *   outcome: '' (mind), 'ok' (2xx), 'client_error' (4xx a 429 kivételével),
     *   'rate_limited' (429), 'server_error' (5xx).
     */
    public function paginate(array $filters, Paginator $pager): array
    {
        [$whereSql, $params] = $this->buildWhere($filters);

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM api_request_logs l $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT l.*, COALESCE(u.name, us.full_name) AS api_user_name
             FROM api_request_logs l
             LEFT JOIN api_users u ON u.id = l.api_user_id
             LEFT JOIN users us ON us.id = l.user_id
             $whereSql
             ORDER BY l.id DESC
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array{total: int, ok: int, client_error: int, rate_limited: int, server_error: int} */
    public function summary(array $filters): array
    {
        [$whereSql, $params] = $this->buildWhere($filters);

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(l.status_code < 400) AS ok,
                SUM(l.status_code BETWEEN 400 AND 499 AND l.status_code != 429) AS client_error,
                SUM(l.status_code = 429) AS rate_limited,
                SUM(l.status_code >= 500) AS server_error
             FROM api_request_logs l
             $whereSql"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'total' => (int) $row['total'],
            'ok' => (int) $row['ok'],
            'client_error' => (int) $row['client_error'],
            'rate_limited' => (int) $row['rate_limited'],
            'server_error' => (int) $row['server_error'],
        ];
    }

    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['api_user_id'])) {
            $where[] = 'l.api_user_id = :api_user_id';
            $params['api_user_id'] = (int) $filters['api_user_id'];
        }
        switch ($filters['outcome'] ?? '') {
            case 'ok':
                $where[] = 'l.status_code < 400';
                break;
            case 'client_error':
                $where[] = 'l.status_code BETWEEN 400 AND 499 AND l.status_code != 429';
                break;
            case 'rate_limited':
                $where[] = 'l.status_code = 429';
                break;
            case 'server_error':
                $where[] = 'l.status_code >= 500';
                break;
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'l.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'l.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /** Beszúr egy log-sort a kérés indulásakor, státusz/futásidő nélkül. Visszaadja a sor id-ját. */
    public function start(?int $apiUserId, ?int $userId, string $method, string $path, string $ip): int
    {
        $pdo = DatabaseConnection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO api_request_logs (api_user_id, user_id, method, path, ip_address, created_at)
             VALUES (:api_user_id, :user_id, :method, :path, :ip, NOW())'
        );
        $stmt->execute([
            'api_user_id' => $apiUserId,
            'user_id' => $userId,
            'method' => $method,
            'path' => $path,
            'ip' => $ip,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** A kérés végén (shutdown function) rögzíti a végleges HTTP státuszt és futásidőt. */
    public function finish(int $id, int $statusCode, float $durationMs): void
    {
        DatabaseConnection::get()
            ->prepare('UPDATE api_request_logs SET status_code = :status, duration_ms = :duration WHERE id = :id')
            ->execute([
                'status' => $statusCode,
                'duration' => (int) round($durationMs),
                'id' => $id,
            ]);
    }

    /**
     * Az adott kliens kéréseinek száma az utolsó $windowSeconds másodpercben (rate limit alap).
     * $column: 'api_user_id' (integrációs token) vagy 'user_id' (felhasználói token).
     */
    public function countRecent(string $column, int $ownerId, int $windowSeconds): int
    {
        $column = $column === 'user_id' ? 'user_id' : 'api_user_id';
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT COUNT(*) FROM api_request_logs
             WHERE $column = :owner_id AND created_at >= NOW() - INTERVAL :seconds SECOND"
        );
        $stmt->bindValue('owner_id', $ownerId, \PDO::PARAM_INT);
        $stmt->bindValue('seconds', $windowSeconds, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Sikertelen bejelentkezések száma egy IP-ről az utolsó $windowSeconds másodpercben
     * (a POST /api/auth/login brute force védelme). Az aktuális kérés sora még státusz
     * nélküli, így nem számít bele.
     */
    public function countFailedLogins(string $ip, string $path, int $windowSeconds): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT COUNT(*) FROM api_request_logs
             WHERE ip_address = :ip AND path = :path AND status_code = 401
               AND created_at >= NOW() - INTERVAL :seconds SECOND'
        );
        $stmt->bindValue('ip', $ip);
        $stmt->bindValue('path', $path);
        $stmt->bindValue('seconds', $windowSeconds, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** Törli a $days napnál régebbi log-sorokat, visszaadja a törölt sorok számát. */
    public function purgeOlderThan(int $days): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            'DELETE FROM api_request_logs WHERE created_at < NOW() - INTERVAL :days DAY'
        );
        $stmt->bindValue('days', $days, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
