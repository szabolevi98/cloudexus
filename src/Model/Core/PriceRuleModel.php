<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Sort;
use Cloudexus\Core\Translation;

/**
 * Árszabályok: vevőcsoport-kedvezmény, mennyiségi kedvezmény és időszakos
 * akció egy táblában. A feltételek szűkítenek (üres = bármi), a hatás vagy
 * százalék az alapárból, vagy fix nettó egységár. Hogy több illeszkedő
 * szabályból melyik nyer, azt a ProductModel::effectivePrice() dönti el.
 */
class PriceRuleModel
{
    private const LIST_SELECT = 'r.*, g.name AS customer_group_name, pr.sku AS product_sku';

    /** Sortable columns of the list (see Sort): key => SQL expression. */
    public const SORTS = [
        'name' => 'r.name',
        'customer_group' => 'customer_group_name',
        'min_quantity' => 'r.min_quantity',
        'status' => 'r.is_active',
    ];

    /** Filters: q (név), status (active / scheduled / expired / inactive). */
    public function paginate(array $filters, Paginator $pager): array
    {
        [$whereSql, $params] = $this->where($filters);

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM price_rules r $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            'SELECT ' . self::LIST_SELECT . ', ' . Translation::select('pd', 'name', 'product_name') . ',
                    ' . Translation::select('cd', 'name', 'category_name') . '
             FROM price_rules r
             LEFT JOIN customer_groups g ON g.id = r.customer_group_id
             LEFT JOIN products pr ON pr.id = r.product_id
             ' . Translation::join('product_description', 'product_id', 'r.product_id', 'pd') . '
             ' . Translation::join('category_description', 'category_id', 'r.category_id', 'cd') . "
             $whereSql
             ORDER BY " . Sort::orderBy(self::SORTS, 'r.is_active DESC, r.name ASC') . "
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT ' . self::LIST_SELECT . ', ' . Translation::select('pd', 'name', 'product_name') . ',
                    ' . Translation::select('cd', 'name', 'category_name') . '
             FROM price_rules r
             LEFT JOIN customer_groups g ON g.id = r.customer_group_id
             LEFT JOIN products pr ON pr.id = r.product_id
             ' . Translation::join('product_description', 'product_id', 'r.product_id', 'pd') . '
             ' . Translation::join('category_description', 'category_id', 'r.category_id', 'cd') . '
             WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        DatabaseConnection::get()->prepare(
            'INSERT INTO price_rules (name, customer_group_id, product_id, category_id, min_quantity, discount_percent,
                                      fixed_price, valid_from, valid_to, is_active)
             VALUES (:name, :customer_group_id, :product_id, :category_id, :min_quantity, :discount_percent,
                     :fixed_price, :valid_from, :valid_to, :is_active)'
        )->execute($this->params($data));

        return (int) DatabaseConnection::get()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE price_rules SET name = :name, customer_group_id = :customer_group_id, product_id = :product_id,
                    category_id = :category_id, min_quantity = :min_quantity, discount_percent = :discount_percent,
                    fixed_price = :fixed_price, valid_from = :valid_from, valid_to = :valid_to, is_active = :is_active
             WHERE id = :id'
        )->execute($this->params($data) + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM price_rules WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * The active rules that fit one sales line: the partner's group (or rules
     * for everyone), the product itself or one of its categories — including
     * their parent categories, so a rule on "Bicycles" covers "City bikes" —,
     * at least the minimum quantity, and the date inside the validity period.
     *
     * @return list<array<string, mixed>>
     */
    public function applicable(int $productId, ?int $customerGroupId, float $quantity, string $date): array
    {
        $categoryIds = $this->categoryChain($productId);
        $categorySql = $categoryIds ? 'r.category_id IN (' . implode(',', $categoryIds) . ')' : 'FALSE';

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT r.* FROM price_rules r
             WHERE r.is_active = 1
               AND (r.customer_group_id IS NULL OR r.customer_group_id = :group_id)
               AND (r.product_id IS NULL OR r.product_id = :product_id)
               AND (r.category_id IS NULL OR $categorySql)
               AND r.min_quantity <= :quantity
               AND (r.valid_from IS NULL OR r.valid_from <= :date1)
               AND (r.valid_to IS NULL OR r.valid_to >= :date2)
             ORDER BY r.id"
        );
        $stmt->execute([
            'group_id' => $customerGroupId ?? 0,
            'product_id' => $productId,
            'quantity' => $quantity,
            'date1' => $date,
            'date2' => $date,
        ]);

        return $stmt->fetchAll();
    }

    /** @return list<int> the product's categories (main and extra) with all their ancestors */
    private function categoryChain(int $productId): array
    {
        $pdo = DatabaseConnection::get();
        $stmt = $pdo->prepare(
            'SELECT category_id FROM products WHERE id = :id AND category_id IS NOT NULL
             UNION SELECT category_id FROM product_categories WHERE product_id = :id2'
        );
        $stmt->execute(['id' => $productId, 'id2' => $productId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $parents = $pdo->query('SELECT id, parent_id FROM categories WHERE parent_id IS NOT NULL')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $chain = [];
        foreach ($ids as $id) {
            // The depth guard stops a (broken) cycle in the category tree.
            for ($depth = 0; $id && !isset($chain[$id]) && $depth < 20; $depth++) {
                $chain[$id] = true;
                $id = (int) ($parents[$id] ?? 0);
            }
        }

        return array_keys($chain);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function where(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $where[] = 'r.name LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $today = date('Y-m-d');
        switch ($filters['status'] ?? '') {
            case 'active':
                $where[] = 'r.is_active = 1 AND (r.valid_from IS NULL OR r.valid_from <= :t1) AND (r.valid_to IS NULL OR r.valid_to >= :t2)';
                $params += ['t1' => $today, 't2' => $today];
                break;
            case 'scheduled':
                $where[] = 'r.is_active = 1 AND r.valid_from > :t1';
                $params['t1'] = $today;
                break;
            case 'expired':
                $where[] = 'r.valid_to < :t1';
                $params['t1'] = $today;
                break;
            case 'inactive':
                $where[] = 'r.is_active = 0';
                break;
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    private function params(array $data): array
    {
        return [
            'name' => $data['name'],
            'customer_group_id' => $data['customer_group_id'] ?: null,
            'product_id' => $data['product_id'] ?: null,
            'category_id' => $data['category_id'] ?: null,
            'min_quantity' => $data['min_quantity'] ?? 0,
            'discount_percent' => $data['discount_percent'],
            'fixed_price' => $data['fixed_price'],
            'valid_from' => $data['valid_from'] ?: null,
            'valid_to' => $data['valid_to'] ?: null,
            'is_active' => $data['is_active'] ? 1 : 0,
        ];
    }
}
