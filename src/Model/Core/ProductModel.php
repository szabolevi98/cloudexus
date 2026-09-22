<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Language;
use Cloudexus\Core\Translation;

class ProductModel
{
    /** All product columns written by create()/update(). */
    private const FIELDS = [
        'sku', 'barcode',
        'category_id', 'unit_id', 'price', 'sale_price', 'vat_rate', 'min_stock',
        'width_mm', 'height_mm', 'depth_mm', 'weight_g',
        'is_active', 'is_webshop',
    ];

    /**
     * A mértékegység kódja a units törzsből, `unit` néven, a neve pedig a
     * unit_description táblából, `unit_name` néven. A termék csak unit_id-t
     * tárol, de a lekérdezések a feloldott kódot is visszaadják, hogy a listák
     * és a REST API változatlan `unit` mezőt kapjanak.
     */
    private static function unitJoin(): string
    {
        return 'LEFT JOIN units un ON un.id = p.unit_id'
            . "\n             " . Translation::join('unit_description', 'unit_id', 'un.id', 'ud');
    }

    private static function unitSelect(): string
    {
        return 'un.code AS unit, ' . Translation::select('ud', 'name', 'unit_name');
    }

    /** A termék fordítható szövegei (name, short_description, description). */
    private static function descJoin(): string
    {
        return Translation::join('product_description', 'product_id', 'p.id', 'pd');
    }

    private static function descSelect(): string
    {
        return Translation::select('pd', 'name');
    }

    private static function descSelectFull(): string
    {
        return Translation::select('pd', 'name')
            . ', ' . Translation::select('pd', 'short_description')
            . ', ' . Translation::select('pd', 'description');
    }

    /** A kategória neve a category_description táblából, `category_name` néven. */
    private static function categoryJoin(): string
    {
        return 'LEFT JOIN categories c ON c.id = p.category_id'
            . "\n             " . Translation::join('category_description', 'category_id', 'c.id', 'cd');
    }

    private static function categorySelect(): string
    {
        return Translation::select('cd', 'name', 'category_name');
    }

    public function all(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT p.*, ' . self::descSelectFull() . ', ' . self::categorySelect() . ', ' . self::unitSelect() . '
             FROM products p
             ' . self::descJoin() . '
             ' . self::categoryJoin() . '
             ' . self::unitJoin() . '
             ORDER BY name ASC'
        )->fetchAll();
    }

    /** Lightweight active list for related/substitute pickers. */
    public function activeSelectList(): array
    {
        return DatabaseConnection::get()
            ->query('SELECT p.id, p.sku, ' . self::descSelect() . '
                     FROM products p
                     ' . self::descJoin() . '
                     WHERE p.is_active = 1 ORDER BY name ASC')
            ->fetchAll();
    }

    /** Select2 AJAX search: active products by sku/name/barcode, paginated. */
    public function search(string $q, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $like = '%' . $q . '%';

        $stmt = DatabaseConnection::get()->prepare(
            'SELECT p.id, p.sku, ' . self::descSelect() . '
             FROM products p
             ' . self::descJoin() . '
             WHERE p.is_active = 1
               AND (p.sku LIKE :q1 OR ' . Translation::pick('pd', 'name') . ' LIKE :q2 OR p.barcode LIKE :q3)
             ORDER BY name ASC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue('q1', $like);
        $stmt->bindValue('q2', $like);
        $stmt->bindValue('q3', $like);
        $stmt->bindValue('lim', $perPage + 1, \PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        return [
            'results' => array_map(fn($r) => ['id' => (int) $r['id'], 'text' => $r['sku'] . ' — ' . $r['name']], $rows),
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
            'SELECT p.id, p.sku, ' . self::descSelect() . '
             FROM products p
             ' . self::descJoin() . "
             WHERE p.id IN ($in)"
        )->fetchAll();

        return array_map(fn($r) => ['id' => (int) $r['id'], 'text' => $r['sku'] . ' — ' . $r['name']], $rows);
    }

    public function count(): int
    {
        return (int) DatabaseConnection::get()->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT p.*, ' . self::descSelectFull() . ', ' . self::unitSelect() . '
             FROM products p
             ' . self::descJoin() . '
             ' . self::unitJoin() . '
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Product with all related data loaded (images, attributes, categories, links). */
    public function findFull(int $id): ?array
    {
        $product = $this->findById($id);
        if (!$product) {
            return null;
        }

        $product['images'] = $this->images($id);
        $product['attributes'] = $this->attributes($id);
        $product['category_ids'] = $this->categoryIds($id);
        $product['related_ids'] = $this->linkedIds($id, 'related');
        $product['substitute_ids'] = $this->linkedIds($id, 'substitute');
        $product['stock_qty'] = $this->currentStock($id);
        $product['group_prices'] = $this->groupPrices($id);

        return $product;
    }

    /** Full product resolved by SKU (for the REST API's /products/sku/{sku}). */
    public function findFullBySku(string $sku): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT id FROM products WHERE sku = :sku LIMIT 1');
        $stmt->execute(['sku' => $sku]);
        $id = $stmt->fetchColumn();

        return $id ? $this->findFull((int) $id) : null;
    }

    /** All group prices for a product, keyed by customer_group_id. */
    public function groupPrices(int $productId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT customer_group_id, price, sale_price FROM product_group_prices WHERE product_id = :id'
        );
        $stmt->execute(['id' => $productId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['customer_group_id']] = $row;
        }

        return $rows;
    }

    /**
     * Saves the per-group prices for a product from parallel POST arrays
     * (group_id[], group_price[], group_sale_price[]). Rows with an empty
     * price are skipped (no override for that group).
     */
    public function saveGroupPrices(int $productId, array $groupIds, array $prices, array $salePrices): void
    {
        $pdo = DatabaseConnection::get();
        $pdo->prepare('DELETE FROM product_group_prices WHERE product_id = :id')->execute(['id' => $productId]);

        $stmt = $pdo->prepare(
            'INSERT INTO product_group_prices (product_id, customer_group_id, price, sale_price)
             VALUES (:product_id, :group_id, :price, :sale_price)'
        );

        foreach ($groupIds as $i => $groupId) {
            $groupId = (int) $groupId;
            $price = trim((string) ($prices[$i] ?? ''));
            $salePrice = trim((string) ($salePrices[$i] ?? ''));

            if ($groupId <= 0 || $price === '') {
                continue;
            }

            $stmt->execute([
                'product_id' => $productId,
                'group_id' => $groupId,
                'price' => (float) str_replace(',', '.', $price),
                'sale_price' => $salePrice !== '' ? (float) str_replace(',', '.', $salePrice) : null,
            ]);
        }
    }

    /**
     * The price to charge for a product to a given partner: group price (with
     * its own optional sale price) if the partner's group has an override,
     * otherwise the product's own price/sale price.
     */
    public function effectivePrice(int $productId, ?int $partnerId): array
    {
        $product = $this->findById($productId);
        if (!$product) {
            return ['price' => 0.0, 'is_sale' => false];
        }

        if ($partnerId) {
            $stmt = DatabaseConnection::get()->prepare(
                'SELECT gp.price, gp.sale_price
                 FROM partners p
                 JOIN product_group_prices gp ON gp.customer_group_id = p.customer_group_id AND gp.product_id = :product_id
                 WHERE p.id = :partner_id LIMIT 1'
            );
            $stmt->execute(['product_id' => $productId, 'partner_id' => $partnerId]);
            $group = $stmt->fetch();

            if ($group) {
                $price = $group['sale_price'] !== null ? (float) $group['sale_price'] : (float) $group['price'];
                return ['price' => $price, 'is_sale' => $group['sale_price'] !== null];
            }
        }

        $price = $product['sale_price'] !== null ? (float) $product['sale_price'] : (float) $product['price'];
        return ['price' => $price, 'is_sale' => $product['sale_price'] !== null];
    }

    public function images(int $productId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT * FROM product_images WHERE product_id = :id ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $stmt->execute(['id' => $productId]);
        return $stmt->fetchAll();
    }

    /**
     * A termék paraméterei. A név a parameter_description táblából oldódik fel;
     * a kifelé adott kulcsok (attr_name / attr_value) szándékosan a korábbiak,
     * hogy a REST API válasza ne változzon.
     *
     * Az érték magán a product_parameters táblán van, nyelvenként egy sorral.
     * A választott nyelv sora nyer; ha a paraméternek abban a nyelvben nincs
     * értéke, az alapnyelvi sor jön helyette (ezt szűri az önmagára mutató
     * LEFT JOIN: csak akkor engedi be az alapnyelvi sort, ha a választott
     * nyelvben nincs párja). Így minden paraméter pontosan egyszer szerepel.
     */
    public function attributes(int $productId): array
    {
        $lang = Language::id();
        $default = Language::defaultId();

        $stmt = DatabaseConnection::get()->prepare(
            'SELECT pp.id, pp.product_id, pp.parameter_id, ' . Translation::select('pad', 'name', 'attr_name') . ',
                    pp.value AS attr_value, pp.sort_order, pp.created_at, pp.updated_at
             FROM product_parameters pp
             JOIN parameters pa ON pa.id = pp.parameter_id
             ' . Translation::join('parameter_description', 'parameter_id', 'pa.id', 'pad') . '
             LEFT JOIN product_parameters ppc
                    ON ppc.product_id = pp.product_id
                   AND ppc.parameter_id = pp.parameter_id
                   AND ppc.language_id = ' . $lang . '
             WHERE pp.product_id = :id
               AND (pp.language_id = ' . $lang . ' OR (pp.language_id = ' . $default . ' AND ppc.id IS NULL))
             ORDER BY pp.sort_order ASC, pp.id ASC'
        );
        $stmt->execute(['id' => $productId]);
        return $stmt->fetchAll();
    }

    public function categoryIds(int $productId): array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT category_id FROM product_categories WHERE product_id = :id');
        $stmt->execute(['id' => $productId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function linkedIds(int $productId, string $type): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT linked_product_id FROM product_links WHERE product_id = :id AND link_type = :t'
        );
        $stmt->execute(['id' => $productId, 't' => $type]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function currentStock(int $productId): float
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END), 0)
             FROM stock_movements WHERE product_id = :id"
        );
        $stmt->execute(['id' => $productId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Filtered, paginated product list with the current total stock joined in.
     * Filters: q (sku/name/barcode), category_id, status, webshop.
     */
    public function paginate(array $filters, \Cloudexus\Core\Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(p.sku LIKE :q1 OR ' . Translation::pick('pd', 'name') . ' LIKE :q2 OR p.barcode LIKE :q3)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[] = '(p.category_id = :category_id OR EXISTS (
                SELECT 1 FROM product_categories pc WHERE pc.product_id = p.id AND pc.category_id = :category_id2
            ))';
            $params['category_id'] = (int) $filters['category_id'];
            $params['category_id2'] = (int) $filters['category_id'];
        }
        if ($filters['status'] !== '') {
            $where[] = 'p.is_active = :is_active';
            $params['is_active'] = $filters['status'] === 'active' ? 1 : 0;
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'p.updated_at >= :updated_since';
            $params['updated_since'] = $filters['updated_since'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // A COUNT ugyanazokat a fordítás-joinokat kapja, mint a SELECT: a q szűrő
        // a lefordított névre nézik, ami join nélkül nem létezik.
        $count = DatabaseConnection::get()->prepare(
            'SELECT COUNT(*) FROM products p
             ' . self::descJoin() . "
             $whereSql"
        );
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT p.*, " . self::descSelectFull() . ", " . self::categorySelect() . ", " . self::unitSelect() . ", COALESCE(s.qty, 0) AS stock_qty,
                    (SELECT path FROM product_images pi WHERE pi.product_id = p.id ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1) AS thumb
             FROM products p
             " . self::descJoin() . "
             " . self::categoryJoin() . "
             " . self::unitJoin() . "
             LEFT JOIN (
                 SELECT product_id, SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END) AS qty
                 FROM stock_movements GROUP BY product_id
             ) s ON s.product_id = p.id
             $whereSql
             ORDER BY name ASC
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function skuExists(string $sku, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM products WHERE sku = :sku';
        $params = ['sku' => $sku];

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeId;
        }

        $stmt = DatabaseConnection::get()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(array $data): int
    {
        $columns = implode(', ', self::FIELDS);
        $placeholders = ':' . implode(', :', self::FIELDS);

        $stmt = DatabaseConnection::get()->prepare(
            "INSERT INTO products ($columns, created_at) VALUES ($placeholders, NOW())"
        );
        $stmt->execute($this->bind($data));

        $id = (int) DatabaseConnection::get()->lastInsertId();
        $this->saveDescriptions($id, $data);
        $this->syncRelations($id, $data);

        return $id;
    }

    public function update(int $id, array $data): void
    {
        $sets = implode(', ', array_map(fn($f) => "$f = :$f", self::FIELDS));

        $stmt = DatabaseConnection::get()->prepare(
            "UPDATE products SET $sets, updated_at = NOW() WHERE id = :id"
        );
        $stmt->execute($this->bind($data) + ['id' => $id]);

        $this->saveDescriptions($id, $data);
        $this->syncRelations($id, $data);
    }

    /**
     * A termék fordítható szövegei nyelvenként. A $data['name'],
     * $data['short_description'] és $data['description'] nyelv szerint kulcsolt
     * tömb (nyelv id => szöveg); egyetlen string is elfogadott, az az alapnyelv
     * sorába kerül. Csak a beküldött nyelveket írja — a nem beküldött nyelvek
     * meglévő fordítása marad.
     */
    public function saveDescriptions(int $productId, array $data): void
    {
        $names = self::perLanguage($data['name'] ?? null);
        $shorts = self::perLanguage($data['short_description'] ?? null);
        $longs = self::perLanguage($data['description'] ?? null);

        $languageIds = array_unique(array_merge(array_keys($names), array_keys($shorts), array_keys($longs)));
        if (!$languageIds) {
            return;
        }

        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO product_description (product_id, language_id, name, short_description, description)
             VALUES (:product_id, :language_id, :name, :short_description, :description)
             ON DUPLICATE KEY UPDATE name = VALUES(name),
                 short_description = VALUES(short_description),
                 description = VALUES(description)'
        );

        foreach ($languageIds as $languageId) {
            $stmt->execute([
                'product_id' => $productId,
                'language_id' => $languageId,
                'name' => (string) ($names[$languageId] ?? ''),
                'short_description' => ($shorts[$languageId] ?? '') !== '' ? $shorts[$languageId] : null,
                'description' => ($longs[$languageId] ?? '') !== '' ? $longs[$languageId] : null,
            ]);
        }
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
    }

    /** Looks up an active product by scanned barcode or SKU (for the vonalkód gyűjtő). */
    public function findByCode(string $code): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT p.*, ' . self::descSelectFull() . ', ' . self::unitSelect() . '
             FROM products p
             ' . self::descJoin() . '
             ' . self::unitJoin() . '
             WHERE (p.barcode = :c1 OR p.sku = :c2) AND p.is_active = 1
             ORDER BY p.barcode = :c3 DESC, p.id ASC LIMIT 1'
        );
        // Ha egy vonalkód egy másik termék cikkszámával egyezik, a vonalkód nyer.
        $stmt->execute(['c1' => $code, 'c2' => $code, 'c3' => $code]);

        return $stmt->fetch() ?: null;
    }

    /** Products whose total stock has fallen below their minimum stock level. */
    public function lowStock(int $limit = 10): array
    {
        return DatabaseConnection::get()->query(
            "SELECT p.id, p.sku, " . self::descSelect() . ", " . self::unitSelect() . ", p.min_stock, COALESCE(s.qty, 0) AS stock_qty
             FROM products p
             " . self::descJoin() . "
             " . self::unitJoin() . "
             LEFT JOIN (
                 SELECT product_id, SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END) AS qty
                 FROM stock_movements GROUP BY product_id
             ) s ON s.product_id = p.id
             WHERE p.is_active = 1 AND p.min_stock > 0 AND COALESCE(s.qty, 0) < p.min_stock
             ORDER BY (COALESCE(s.qty, 0) / p.min_stock) ASC
             LIMIT " . (int) $limit
        )->fetchAll();
    }

    // --- image helpers -----------------------------------------------------

    public function addImage(int $productId, string $path, bool $isPrimary = false): void
    {
        DatabaseConnection::get()->prepare(
            'INSERT INTO product_images (product_id, path, is_primary, created_at) VALUES (:p, :path, :primary, NOW())'
        )->execute(['p' => $productId, 'path' => $path, 'primary' => $isPrimary ? 1 : 0]);

        $this->ensureOnePrimary($productId);
    }

    public function findImage(int $imageId): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM product_images WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $imageId]);
        return $stmt->fetch() ?: null;
    }

    public function deleteImage(int $imageId): void
    {
        $img = $this->findImage($imageId);
        DatabaseConnection::get()->prepare('DELETE FROM product_images WHERE id = :id')->execute(['id' => $imageId]);
        if ($img) {
            $this->ensureOnePrimary((int) $img['product_id']);
        }
    }

    public function setPrimaryImage(int $imageId): void
    {
        $img = $this->findImage($imageId);
        if (!$img) {
            return;
        }
        $pdo = DatabaseConnection::get();
        $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = :p')
            ->execute(['p' => $img['product_id']]);
        $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = :id')->execute(['id' => $imageId]);
    }

    private function ensureOnePrimary(int $productId): void
    {
        $pdo = DatabaseConnection::get();
        $hasPrimary = (int) $pdo->query("SELECT COUNT(*) FROM product_images WHERE product_id = $productId AND is_primary = 1")->fetchColumn();
        if ($hasPrimary === 0) {
            $pdo->exec("UPDATE product_images SET is_primary = 1
                        WHERE id = (SELECT id FROM (SELECT id FROM product_images WHERE product_id = $productId ORDER BY sort_order, id LIMIT 1) t)");
        }
    }

    // --- internals ---------------------------------------------------------

    /**
     * Fordítható szöveg nyelv szerint kulcsolt tömbje. Sima stringet is elfogad
     * (az alapnyelvhez rendeli), így a még nem nyelvesített hívók sem törnek el.
     *
     * @return array<int, string>
     */
    private static function perLanguage(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            return [Language::defaultId() => (string) $value];
        }

        $out = [];
        foreach ($value as $languageId => $text) {
            $languageId = (int) $languageId;
            if ($languageId > 0) {
                $out[$languageId] = (string) $text;
            }
        }

        return $out;
    }

    private function bind(array $data): array
    {
        return [
            'sku' => $data['sku'],
            'barcode' => ($data['barcode'] ?? '') !== '' ? $data['barcode'] : null,
            'category_id' => $data['category_id'] ?: null,
            'unit_id' => ($data['unit_id'] ?? null) ?: null,
            'price' => $data['price'],
            'sale_price' => ($data['sale_price'] ?? '') !== '' ? $data['sale_price'] : null,
            'vat_rate' => $data['vat_rate'] ?? 27,
            'min_stock' => $data['min_stock'] ?? 0,
            'width_mm' => ($data['width_mm'] ?? '') !== '' ? (int) $data['width_mm'] : null,
            'height_mm' => ($data['height_mm'] ?? '') !== '' ? (int) $data['height_mm'] : null,
            'depth_mm' => ($data['depth_mm'] ?? '') !== '' ? (int) $data['depth_mm'] : null,
            'weight_g' => ($data['weight_g'] ?? '') !== '' ? (int) $data['weight_g'] : null,
            'is_active' => $data['is_active'],
            'is_webshop' => $data['is_webshop'] ?? 1,
        ];
    }

    private function syncRelations(int $id, array $data): void
    {
        $pdo = DatabaseConnection::get();

        // Categories: primary (category_id) + any extra selected.
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $data['category_ids'] ?? []))));
        if ($data['category_id'] ?? null) {
            array_unshift($categoryIds, (int) $data['category_id']);
            $categoryIds = array_values(array_unique($categoryIds));
        }
        $pdo->prepare('DELETE FROM product_categories WHERE product_id = :id')->execute(['id' => $id]);
        $catStmt = $pdo->prepare('INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (:p, :c)');
        foreach ($categoryIds as $catId) {
            $catStmt->execute(['p' => $id, 'c' => $catId]);
        }

        // Paraméterek: párhuzamos tömbök, parameter_id[] / parameter_value[].
        // Az érték nyelvenként külön sor, ezért a parameter_value nyelv szerint
        // kulcsolt tömbök tömbje: parameter_value[nyelv][sorindex]. Egy sima
        // (nyelv nélküli) tömb az alapnyelvre kerül. Ugyanaz a paraméter egy
        // terméken nyelvenként csak egyszer szerepelhet (uniq kulcs), ezért az
        // ismételt választást az INSERT IGNORE eldobja.
        $pdo->prepare('DELETE FROM product_parameters WHERE product_id = :id')->execute(['id' => $id]);
        $parameterIds = $data['parameter_id'] ?? [];
        $values = $data['parameter_value'] ?? [];
        if ($values && !is_array(reset($values))) {
            $values = [Language::defaultId() => $values];
        }
        $paramStmt = $pdo->prepare(
            'INSERT IGNORE INTO product_parameters (product_id, parameter_id, language_id, value, sort_order)
             VALUES (:p, :pid, :lang, :v, :s)'
        );
        $sort = 0;
        foreach ($parameterIds as $i => $parameterId) {
            $parameterId = (int) $parameterId;
            if ($parameterId <= 0) {
                continue;
            }
            $rowSort = $sort++;
            foreach ($values as $languageId => $languageValues) {
                $languageId = (int) $languageId;
                $value = trim((string) (is_array($languageValues) ? ($languageValues[$i] ?? '') : ''));
                if ($languageId > 0 && $value !== '') {
                    $paramStmt->execute([
                        'p' => $id,
                        'pid' => $parameterId,
                        'lang' => $languageId,
                        'v' => $value,
                        's' => $rowSort,
                    ]);
                }
            }
        }

        // Related / substitute links.
        $this->syncLinks($id, 'related', $data['related_ids'] ?? []);
        $this->syncLinks($id, 'substitute', $data['substitute_ids'] ?? []);

        // Vevőcsoportos árak: parallel arrays group_id[] / group_price[] / group_sale_price[].
        $this->saveGroupPrices(
            $id,
            $data['group_id'] ?? [],
            $data['group_price'] ?? [],
            $data['group_sale_price'] ?? []
        );
    }

    private function syncLinks(int $id, string $type, array $linkedIds): void
    {
        $pdo = DatabaseConnection::get();
        $pdo->prepare('DELETE FROM product_links WHERE product_id = :id AND link_type = :t')
            ->execute(['id' => $id, 't' => $type]);

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO product_links (product_id, linked_product_id, link_type) VALUES (:p, :l, :t)'
        );
        foreach (array_unique(array_filter(array_map('intval', $linkedIds))) as $linkedId) {
            if ($linkedId !== $id) {
                $stmt->execute(['p' => $id, 'l' => $linkedId, 't' => $type]);
            }
        }
    }

    /**
     * A termék szövegei minden nyelven, szerkesztéshez.
     *
     * @return array<int, array{name: string, short_description: ?string, description: ?string}>
     */
    public function descriptions(int $productId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT language_id, name, short_description, description
             FROM product_description WHERE product_id = :id'
        );
        $stmt->execute(['id' => $productId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['language_id']] = [
                'name' => (string) $row['name'],
                'short_description' => $row['short_description'],
                'description' => $row['description'],
            ];
        }

        return $out;
    }

    /**
     * A termék paraméterei szerkesztéshez: soronként a paraméter id-ja és az
     * értéke minden nyelven. A sorrend a sort_order, hogy az űrlap ugyanabban a
     * sorrendben jelenítse meg, ahogy mentve lett.
     *
     * @return list<array{parameter_id: int, name: string, values: array<int, string>}>
     */
    public function parameterRows(int $productId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT pp.parameter_id, pp.language_id, pp.value, pp.sort_order,
                    ' . Translation::select('pad', 'name') . '
             FROM product_parameters pp
             ' . Translation::join('parameter_description', 'parameter_id', 'pp.parameter_id', 'pad') . '
             WHERE pp.product_id = :id
             ORDER BY pp.sort_order ASC, pp.parameter_id ASC'
        );
        $stmt->execute(['id' => $productId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $parameterId = (int) $row['parameter_id'];
            if (!isset($rows[$parameterId])) {
                $rows[$parameterId] = [
                    'parameter_id' => $parameterId,
                    'name' => (string) $row['name'],
                    'values' => [],
                ];
            }
            $rows[$parameterId]['values'][(int) $row['language_id']] = (string) $row['value'];
        }

        return array_values($rows);
    }
}
