<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Sort;

class PartnerModel
{
    public function all(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT p.*, g.name AS customer_group_name
             FROM partners p
             LEFT JOIN customer_groups g ON g.id = p.customer_group_id
             ORDER BY p.name ASC'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT p.*, g.name AS customer_group_name
             FROM partners p
             LEFT JOIN customer_groups g ON g.id = p.customer_group_id
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Select2 AJAX search: partners by name/tax number, text = name (+ tax
     * number, ha van). $role szűkíthet 'customer' vagy 'supplier' szerepre —
     * ilyenkor a 'both' típusú partnerek is bekerülnek, mint a
     * customersAndBoth()/suppliersAndBoth() metódusoknál. is_active = 1 mindig.
     */
    public function search(string $q, int $page = 1, int $perPage = 20, ?string $role = null): array
    {
        $where = ['p.is_active = 1'];
        $params = [];

        if ($q !== '') {
            $where[] = '(p.name LIKE :q1 OR p.tax_number LIKE :q2)';
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }
        if ($role === 'customer') {
            $where[] = "p.type IN ('customer', 'both')";
        } elseif ($role === 'supplier') {
            $where[] = "p.type IN ('supplier', 'both')";
        }

        $offset = max(0, ($page - 1) * $perPage);
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT p.id, p.name, p.tax_number FROM partners p
             $whereSql
             ORDER BY p.name ASC LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('lim', $perPage + 1, \PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        return [
            'results' => array_map(fn($r) => [
                'id' => (int) $r['id'],
                'text' => $r['tax_number'] ? $r['name'] . ' — ' . $r['tax_number'] : $r['name'],
            ], $rows),
            'more' => $more,
        ];
    }

    /** Resolves ids to {id,text} pairs, to preselect Select2 options on edit. */
    public function labelsForIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', $ids);
        $rows = DatabaseConnection::get()->query(
            "SELECT id, name, tax_number FROM partners WHERE id IN ($in)"
        )->fetchAll();

        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'text' => $r['tax_number'] ? $r['name'] . ' — ' . $r['tax_number'] : $r['name'],
        ], $rows);
    }

    /** Partner resolved by exact tax number (for the REST API's /partners/tax/{taxNumber}). */
    public function findByTaxNumber(string $taxNumber): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT p.*, g.name AS customer_group_name
             FROM partners p
             LEFT JOIN customer_groups g ON g.id = p.customer_group_id
             WHERE p.tax_number = :tax LIMIT 1'
        );
        $stmt->execute(['tax' => $taxNumber]);
        return $stmt->fetch() ?: null;
    }

    /** Sortable columns of the list (see Sort): key => SQL expression. */
    public const SORTS = [
        'name' => 'p.name',
        'type' => 'p.type',
        'tax_number' => 'p.tax_number',
        'email' => 'p.email',
        'phone' => 'p.phone',
        'customer_group' => 'customer_group_name',
        'status' => 'p.is_active',
    ];

    /**
     * Filters: q (name/tax_number/email), type ('customer'|'supplier'|'both'), status, customer_group_id.
     */
    public function paginate(array $filters, \Cloudexus\Core\Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(p.name LIKE :q1 OR p.tax_number LIKE :q2 OR p.email LIKE :q3)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        if ($filters['type'] !== '') {
            $where[] = 'p.type = :type';
            $params['type'] = $filters['type'];
        }
        if ($filters['status'] !== '') {
            $where[] = 'p.is_active = :is_active';
            $params['is_active'] = $filters['status'] === 'active' ? 1 : 0;
        }
        if (!empty($filters['customer_group_id'])) {
            $where[] = 'p.customer_group_id = :group_id';
            $params['group_id'] = (int) $filters['customer_group_id'];
        }
        if (!empty($filters['tag_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM partner_tags pt WHERE pt.partner_id = p.id AND pt.tag_id = :tag_id)';
            $params['tag_id'] = (int) $filters['tag_id'];
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'p.updated_at >= :updated_since';
            $params['updated_since'] = $filters['updated_since'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM partners p $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT p.*, g.name AS customer_group_name
             FROM partners p
             LEFT JOIN customer_groups g ON g.id = p.customer_group_id
             $whereSql ORDER BY " . Sort::orderBy(self::SORTS, 'p.name ASC') . " LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function customersAndBoth(): array
    {
        return DatabaseConnection::get()
            ->query("SELECT * FROM partners WHERE type IN ('customer', 'both') AND is_active = 1 ORDER BY name ASC")
            ->fetchAll();
    }

    public function suppliersAndBoth(): array
    {
        return DatabaseConnection::get()
            ->query("SELECT * FROM partners WHERE type IN ('supplier', 'both') AND is_active = 1 ORDER BY name ASC")
            ->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO partners (type, customer_group_id, name, tax_number, email, phone, address, credit_limit, payment_terms_days, is_active, created_at)
             VALUES (:type, :customer_group_id, :name, :tax_number, :email, :phone, :address, :credit_limit, :payment_terms_days, :is_active, NOW())'
        );
        $stmt->execute([
            'type' => $data['type'],
            'customer_group_id' => !empty($data['customer_group_id']) ? $data['customer_group_id'] : null,
            'name' => $data['name'],
            'tax_number' => $data['tax_number'] ?: null,
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'address' => !empty($data['address']) ? $data['address'] : null,
            'credit_limit' => isset($data['credit_limit']) && $data['credit_limit'] !== '' ? (float) $data['credit_limit'] : null,
            'payment_terms_days' => isset($data['payment_terms_days']) && $data['payment_terms_days'] !== '' ? (int) $data['payment_terms_days'] : null,
            'is_active' => $data['is_active'],
        ]);
        $id = (int) DatabaseConnection::get()->lastInsertId();
        \Cloudexus\Core\Webhooks::dispatch('partner.changed', ['id' => $id, 'action' => 'created']);

        return $id;
    }

    public function update(int $id, array $data): void
    {
        $stmt = DatabaseConnection::get()->prepare(
            'UPDATE partners SET type = :type, customer_group_id = :customer_group_id, name = :name, tax_number = :tax_number, email = :email,
                phone = :phone, address = :address, credit_limit = :credit_limit, payment_terms_days = :payment_terms_days, is_active = :is_active, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'type' => $data['type'],
            'customer_group_id' => !empty($data['customer_group_id']) ? $data['customer_group_id'] : null,
            'name' => $data['name'],
            'tax_number' => $data['tax_number'] ?: null,
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'address' => !empty($data['address']) ? $data['address'] : null,
            'credit_limit' => isset($data['credit_limit']) && $data['credit_limit'] !== '' ? (float) $data['credit_limit'] : null,
            'payment_terms_days' => isset($data['payment_terms_days']) && $data['payment_terms_days'] !== '' ? (int) $data['payment_terms_days'] : null,
            'is_active' => $data['is_active'],
        ]);
        \Cloudexus\Core\Webhooks::dispatch('partner.changed', ['id' => $id, 'action' => 'updated']);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM partners WHERE id = :id')->execute(['id' => $id]);
        \Cloudexus\Core\Webhooks::dispatch('partner.changed', ['id' => $id, 'action' => 'deleted']);
    }

    /**
     * Egy oszlop sok partneren egyszerre — a lista tömeges műveleteihez.
     * Csak a felsorolt oszlopok írhatók. Visszaadja, hány sor változott.
     *
     * @param list<int> $ids
     */
    public function bulkSet(array $ids, string $column, ?int $value): int
    {
        if ($ids === [] || !in_array($column, ['is_active', 'customer_group_id'], true)) {
            return 0;
        }

        $in = implode(', ', array_map('intval', $ids));
        foreach ($ids as $partnerId) {
            \Cloudexus\Core\Webhooks::dispatch('partner.changed', ['id' => (int) $partnerId, 'action' => 'updated']);
        }

        return (int) DatabaseConnection::get()->exec("UPDATE partners SET $column = " . ($value === null ? 'NULL' : (int) $value) . " WHERE id IN ($in)");
    }
}
