<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\DocumentNumber;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Sort;

class StocktakingModel
{
    public function all(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT s.*, w.name AS warehouse_name, u.full_name AS created_by_name
             FROM stocktakings s
             JOIN warehouses w ON w.id = s.warehouse_id
             LEFT JOIN users u ON u.id = s.created_by
             ORDER BY s.created_at DESC, s.id DESC'
        )->fetchAll();
    }

    /** Sortable columns of the list (see Sort): key => SQL expression. */
    public const SORTS = [
        'number' => 's.stocktaking_number',
        'warehouse' => 'warehouse_name',
        'date' => 's.created_at',
        'created_by' => 'created_by_name',
        'items' => 's.item_count',
        'variances' => 's.diff_count',
    ];

    /** Filters: q (stocktaking_number), warehouse_id. */
    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = 's.stocktaking_number LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['warehouse_id'])) {
            $where[] = 's.warehouse_id = :wid';
            $params['wid'] = (int) $filters['warehouse_id'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM stocktakings s $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT s.*, w.name AS warehouse_name, u.full_name AS created_by_name
             FROM stocktakings s
             JOIN warehouses w ON w.id = s.warehouse_id
             LEFT JOIN users u ON u.id = s.created_by
             $whereSql
             ORDER BY " . Sort::orderBy(self::SORTS, 's.created_at DESC, s.id DESC') . "
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT s.*, w.name AS warehouse_name, u.full_name AS created_by_name
             FROM stocktakings s
             JOIN warehouses w ON w.id = s.warehouse_id
             LEFT JOIN users u ON u.id = s.created_by
             WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $itemStmt = DatabaseConnection::get()->prepare(
            'SELECT si.*, p.sku, ' . \Cloudexus\Core\Translation::select('pd', 'name', 'product_name') . ', un.code AS unit
             FROM stocktaking_items si
             JOIN products p ON p.id = si.product_id
             ' . \Cloudexus\Core\Translation::join('product_description', 'product_id', 'p.id', 'pd') . '
             LEFT JOIN units un ON un.id = p.unit_id
             WHERE si.stocktaking_id = :id
             ORDER BY product_name ASC'
        );
        $itemStmt->execute(['id' => $id]);
        $row['items'] = $itemStmt->fetchAll();

        return $row;
    }

    /** A várható következő leltárszám, tájékoztatásnak — a valódit a könyvelés kapja. */
    public function nextNumber(): string
    {
        return DocumentNumber::preview('stocktaking');
    }

    /**
     * Books a stocktaking: records the header + all counted items, and for every
     * product whose counted quantity differs from the book quantity, posts a
     * correction stock movement so the book stock matches the physical count.
     *
     * The book quantity is read here, with the warehouse locked — not taken
     * from the form, which may have been opened before other bookings (or
     * edited): the correction always brings the stock to the counted figure.
     *
     * @param array $items List of ['product_id', 'counted_quantity'].
     */
    public function book(int $warehouseId, string $note, array $items, ?int $userId): int
    {
        $stock = new StockMovementModel();

        return $stock->locked([$warehouseId], function () use ($stock, $warehouseId, $note, $items, $userId): int {
            $pdo = DatabaseConnection::get();
            $number = DocumentNumber::take('stocktaking');
            $diffCount = 0;

            $stmt = $pdo->prepare(
                'INSERT INTO stocktakings (stocktaking_number, warehouse_id, note, item_count, diff_count, created_by, created_at)
                 VALUES (:number, :warehouse_id, :note, :item_count, 0, :created_by, NOW())'
            );
            $stmt->execute([
                'number' => $number,
                'warehouse_id' => $warehouseId,
                'note' => $note ?: null,
                'item_count' => count($items),
                'created_by' => $userId,
            ]);
            $stocktakingId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO stocktaking_items (stocktaking_id, product_id, book_quantity, counted_quantity, diff)
                 VALUES (:stocktaking_id, :product_id, :book, :counted, :diff)'
            );
            $moveStmt = $pdo->prepare(
                'INSERT INTO stock_movements (warehouse_id, product_id, type, quantity, note, created_by, created_at)
                 VALUES (:warehouse_id, :product_id, :type, :quantity, :note, :created_by, NOW())'
            );

            foreach ($items as $item) {
                $item['book_quantity'] = $stock->availableQuantity((int) $item['product_id'], $warehouseId);
                $diff = round($item['counted_quantity'] - $item['book_quantity'], 3);

                $itemStmt->execute([
                    'stocktaking_id' => $stocktakingId,
                    'product_id' => $item['product_id'],
                    'book' => $item['book_quantity'],
                    'counted' => $item['counted_quantity'],
                    'diff' => $diff,
                ]);

                if (abs($diff) > 0.0001) {
                    $diffCount++;
                    $moveStmt->execute([
                        'warehouse_id' => $warehouseId,
                        'product_id' => $item['product_id'],
                        'type' => $diff > 0 ? 'in' : 'out',
                        'quantity' => abs($diff),
                        'note' => 'Leltár korrekció: ' . $number,
                        'created_by' => $userId,
                    ]);
                }
            }

            $pdo->prepare('UPDATE stocktakings SET diff_count = :d WHERE id = :id')
                ->execute(['d' => $diffCount, 'id' => $stocktakingId]);

            return $stocktakingId;
        });
    }
}
