<?php

namespace Cloudexus\Model\Crm;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;

class TodoModel
{
    /** Filters: q (title), status ('open'|'done'), assigned_to. */
    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = 't.title LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if ($filters['status'] === 'open') {
            $where[] = 't.is_done = 0';
        } elseif ($filters['status'] === 'done') {
            $where[] = 't.is_done = 1';
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = 't.assigned_to = :assigned_to';
            $params['assigned_to'] = (int) $filters['assigned_to'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM todos t $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT t.*, p.name AS partner_name, u.full_name AS assigned_name
             FROM todos t
             LEFT JOIN partners p ON p.id = t.partner_id
             LEFT JOIN users u ON u.id = t.assigned_to
             $whereSql
             ORDER BY t.is_done ASC, (t.due_date IS NULL), t.due_date ASC, t.id DESC
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Open todos for the dashboard widget, soonest due first. */
    public function openForDashboard(int $limit = 8): array
    {
        return DatabaseConnection::get()->query(
            'SELECT t.*, p.name AS partner_name
             FROM todos t
             LEFT JOIN partners p ON p.id = t.partner_id
             WHERE t.is_done = 0
             ORDER BY (t.due_date IS NULL), t.due_date ASC, t.id DESC
             LIMIT ' . (int) $limit
        )->fetchAll();
    }

    public function openCount(): int
    {
        return (int) DatabaseConnection::get()->query('SELECT COUNT(*) FROM todos WHERE is_done = 0')->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO todos (title, due_date, partner_id, assigned_to, created_by, created_at)
             VALUES (:title, :due_date, :partner_id, :assigned_to, :created_by, NOW())'
        );
        $stmt->execute([
            'title' => $data['title'],
            'due_date' => $data['due_date'] ?: null,
            'partner_id' => $data['partner_id'] ?: null,
            'assigned_to' => $data['assigned_to'] ?: null,
            'created_by' => $data['created_by'] ?: null,
        ]);

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    public function toggle(int $id): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE todos SET is_done = 1 - is_done,
                completed_at = CASE WHEN is_done = 0 THEN NOW() ELSE NULL END
             WHERE id = :id'
        )->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM todos WHERE id = :id')->execute(['id' => $id]);
    }
}
