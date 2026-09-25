<?php

namespace Cloudexus\Core\Import;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Language;
use PDO;

/**
 * Termékek CSV-ből, a cikkszám a kulcs: ami megvan, az frissül, ami nincs,
 * az új lesz. Ugyanazok az oszlopok, amiket a termékek exportja ír, így egy
 * export szerkesztve visszatölthető.
 *
 * Csak a törzsadat: cikkszám, vonalkód, név (az alapnyelven), kategória (a
 * neve szerint), egység, nettó ár, ÁFA, minimumkészlet, webshop, aktív. A
 * készlet oszlopot kihagyja — a készlet csak mozgásból változik —, és egy
 * frissítés nem nyúl a termék képeihez, paramétereihez, kapcsolataihoz.
 */
final class ProductImport extends Import
{
    private PDO $db;

    /** @var array<string, int>|null kisbetűs név => kategória id */
    private ?array $categories = null;

    /** @var array<string, int>|null kisbetűs kód vagy név => egység id */
    private ?array $units = null;

    /** @var array<string, int> cikkszám => a fájl sora, ahol először szerepelt */
    private array $seen = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    protected function columns(): array
    {
        return [
            'sku' => ['cikkszam', 'sku', 'cikk', 'termekkod', 'kod'],
            'barcode' => ['vonalkod', 'barcode', 'ean', 'gtin'],
            'name' => ['megnevezes', 'nev', 'termeknev', 'name', 'productname'],
            'category' => ['kategoria', 'category'],
            'unit' => ['egyseg', 'mertekegyseg', 'me', 'unit'],
            'price' => ['nettoar', 'netto', 'ar', 'nettoegysegar', 'price', 'netprice'],
            'vat_rate' => ['afa', 'afakulcs', 'vat', 'vatrate'],
            'min_stock' => ['minkeszlet', 'minimumkeszlet', 'minimum', 'minstock'],
            'is_webshop' => ['webshop', 'webshopban'],
            'is_active' => ['aktiv', 'active', 'allapot', 'status'],
            'stock' => ['keszlet', 'stock', 'raktarkeszlet'],
        ];
    }

    protected function requiredColumns(): array
    {
        return ['sku'];
    }

    protected function planRow(array $values, int $line): array
    {
        $sku = $values['sku'] ?? '';
        $messages = [];
        $plan = static fn(string $action, ?int $id, array $data, array $messages) => ['action' => $action, 'id' => $id, 'label' => $sku, 'data' => $data, 'messages' => $messages];

        if ($sku === '') {
            return $plan('error', null, [], [self::say('sku_missing')]);
        }
        if (isset($this->seen[mb_strtolower($sku)])) {
            return $plan('error', null, [], [self::say('duplicate', ['line' => $this->seen[mb_strtolower($sku)]])]);
        }
        $this->seen[mb_strtolower($sku)] = $line;

        $existing = $this->existing($sku);
        $data = [];

        if (($values['barcode'] ?? '') !== '') {
            $data['barcode'] = mb_substr($values['barcode'], 0, 64);
        }
        if (($values['name'] ?? '') !== '') {
            $data['name'] = mb_substr($values['name'], 0, 200);
        }
        foreach (['price' => 'price', 'vat_rate' => 'vat', 'min_stock' => 'min_stock'] as $field => $label) {
            if (($values[$field] ?? '') === '') {
                continue;
            }
            $number = CsvReader::number($values[$field]);
            if ($number === null || $number < 0 || ($field === 'vat_rate' && $number > 100)) {
                return $plan('error', $existing['id'] ?? null, [], [self::say('bad_number', ['column' => self::say('field_' . $label), 'value' => $values[$field]])]);
            }
            $data[$field] = $number;
        }
        foreach (['is_webshop', 'is_active'] as $field) {
            if (($values[$field] ?? '') === '') {
                continue;
            }
            $flag = CsvReader::yesNo($values[$field]);
            if ($flag === null) {
                return $plan('error', $existing['id'] ?? null, [], [self::say('bad_yes_no', ['value' => $values[$field]])]);
            }
            $data[$field] = $flag ? 1 : 0;
        }
        if (($values['category'] ?? '') !== '') {
            $category = $this->categoryId($values['category']);
            $category === null
                ? $messages[] = self::say('unknown_category', ['name' => $values['category']])
                : $data['category_id'] = $category;
        }
        if (($values['unit'] ?? '') !== '') {
            $unit = $this->unitId($values['unit']);
            $unit === null
                ? $messages[] = self::say('unknown_unit', ['name' => $values['unit']])
                : $data['unit_id'] = $unit;
        }
        if (array_key_exists('stock', $values) && $values['stock'] !== '') {
            $messages[] = self::say('stock_ignored');
        }

        if ($existing === null) {
            if (!isset($data['name'])) {
                return $plan('error', null, [], [self::say('name_missing')]);
            }
            if (!isset($data['price'])) {
                return $plan('error', null, [], [self::say('price_missing')]);
            }
            if (isset($data['barcode']) && $this->barcodeTaken($data['barcode'], null)) {
                return $plan('error', null, [], [self::say('barcode_taken', ['code' => $data['barcode']])]);
            }

            return $plan('create', null, ['sku' => $sku] + $data, $messages);
        }

        if (isset($data['barcode']) && $this->barcodeTaken($data['barcode'], (int) $existing['id'])) {
            return $plan('error', (int) $existing['id'], [], [self::say('barcode_taken', ['code' => $data['barcode']])]);
        }

        // Csak ami tényleg más: egy visszatöltött, szerkesztetlen export sorai "nincs változás".
        $changes = [];
        foreach ($data as $field => $value) {
            $current = $existing[$field] ?? null;
            $same = is_float($value) ? abs((float) $current - $value) < 0.0001 : (string) $current === (string) $value;
            if (!$same) {
                $changes[$field] = $value;
            }
        }

        return $plan($changes === [] ? 'unchanged' : 'update', (int) $existing['id'], $changes, $messages);
    }

    protected function write(array $rows): void
    {
        $language = Language::defaultId();
        $insert = $this->db->prepare(
            'INSERT INTO products (sku, barcode, category_id, unit_id, price, vat_rate, min_stock, is_active, is_webshop, created_at)
             VALUES (:sku, :barcode, :category_id, :unit_id, :price, :vat_rate, :min_stock, :is_active, :is_webshop, NOW())'
        );
        $name = $this->db->prepare(
            'INSERT INTO product_description (product_id, language_id, name) VALUES (:id, :language, :name)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        $category = $this->db->prepare('INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (:id, :category)');

        foreach ($rows as $row) {
            $data = $row['data'];
            if ($row['action'] === 'create') {
                $insert->execute([
                    'sku' => $data['sku'],
                    'barcode' => $data['barcode'] ?? null,
                    'category_id' => $data['category_id'] ?? null,
                    'unit_id' => $data['unit_id'] ?? $this->unitId('db'),
                    'price' => $data['price'],
                    'vat_rate' => $data['vat_rate'] ?? 27,
                    'min_stock' => $data['min_stock'] ?? 0,
                    'is_active' => $data['is_active'] ?? 1,
                    'is_webshop' => $data['is_webshop'] ?? 1,
                ]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $id = (int) $row['id'];
                $columns = array_intersect_key($data, array_flip(['barcode', 'category_id', 'unit_id', 'price', 'vat_rate', 'min_stock', 'is_active', 'is_webshop']));
                if ($columns !== []) {
                    $sets = implode(', ', array_map(static fn(string $c): string => "$c = :$c", array_keys($columns)));
                    $this->db->prepare("UPDATE products SET $sets, updated_at = NOW() WHERE id = :id")->execute($columns + ['id' => $id]);
                }
            }

            if (isset($data['name'])) {
                $name->execute(['id' => $id, 'language' => $language, 'name' => $data['name']]);
            }
            if (isset($data['category_id'])) {
                $category->execute(['id' => $id, 'category' => $data['category_id']]);
            }
            \Cloudexus\Core\Webhooks::dispatch('product.changed', ['id' => $id, 'sku' => $data['sku'] ?? $row['label'], 'action' => $row['action'] === 'create' ? 'created' : 'updated']);
        }
    }

    /** @return array<string, mixed>|null */
    private function existing(string $sku): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, pd.name FROM products p
             LEFT JOIN product_description pd ON pd.product_id = p.id AND pd.language_id = :language
             WHERE p.sku = :sku LIMIT 1'
        );
        $stmt->execute(['sku' => $sku, 'language' => Language::defaultId()]);

        return $stmt->fetch() ?: null;
    }

    private function barcodeTaken(string $barcode, ?int $exceptId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM products WHERE barcode = :code AND id <> :id');
        $stmt->execute(['code' => $barcode, 'id' => $exceptId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function categoryId(string $name): ?int
    {
        if ($this->categories === null) {
            $this->categories = [];
            foreach ($this->db->query('SELECT category_id, name FROM category_description ORDER BY language_id') ?: [] as $row) {
                $this->categories[mb_strtolower(trim((string) $row['name']))] ??= (int) $row['category_id'];
            }
        }

        return $this->categories[mb_strtolower(trim($name))] ?? null;
    }

    private function unitId(string $name): ?int
    {
        if ($this->units === null) {
            $this->units = [];
            foreach ($this->db->query('SELECT id, code FROM units') ?: [] as $row) {
                $this->units[mb_strtolower((string) $row['code'])] = (int) $row['id'];
            }
            foreach ($this->db->query('SELECT unit_id, name FROM unit_description') ?: [] as $row) {
                $this->units[mb_strtolower((string) $row['name'])] ??= (int) $row['unit_id'];
            }
        }

        return $this->units[mb_strtolower(trim($name))] ?? null;
    }
}
