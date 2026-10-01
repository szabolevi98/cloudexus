<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\ProductModel;
use Cloudexus\Model\Core\StocktakingModel;
use Cloudexus\Model\Core\WarehouseModel;

/**
 * A stocktaking counted on the PDA: the counted quantities of the products
 * scanned in one warehouse, booked the same way as on the web
 * (StocktakingModel::book()) — the book stock is read at the moment of
 * booking, with the warehouse locked, and every difference becomes a
 * correction movement. Products not scanned are left as they are.
 *
 * Needs a user token whose role may manage stocktakings. Its Idempotency-Key
 * is the generic one (ApiController): the booking runs its own transaction.
 */
class StocktakingApiController extends ApiController
{
    private const MAX_ITEMS = 2000;

    /** {warehouse_id, note, items: [{product_id, counted_quantity}]} */
    public function create(): void
    {
        $this->requireUserPermission(Permissions::STOCKTAKING_MANAGE);

        $body = $this->body();
        $warehouseId = filter_var($body['warehouse_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $warehouse = $warehouseId ? (new WarehouseModel())->findById($warehouseId) : null;
        if (!$warehouse || !$warehouse['is_active']) {
            $this->error('warehouse_id must be an active warehouse.', 422);
        }

        $note = trim((string) ($body['note'] ?? ''));
        if (mb_strlen($note) > 200) {
            $this->error('note can be at most 200 characters.', 422);
        }

        $raw = $body['items'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || !$raw) {
            $this->error('items must be a non-empty list of {product_id, counted_quantity}.', 422);
        }
        if (count($raw) > self::MAX_ITEMS) {
            $this->error('At most ' . self::MAX_ITEMS . ' products can be counted in one stocktaking.', 422);
        }

        // One count per product: a product counted on several shelves is
        // added up by the app before it is sent.
        $products = new ProductModel();
        $items = [];
        $problems = [];
        foreach ($raw as $i => $item) {
            $productId = is_array($item) ? filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $product = $productId ? $products->findById($productId) : null;
            if (!$product) {
                $problems[] = ['index' => $i, 'message' => 'Unknown product_id.'];
                continue;
            }
            $counted = $item['counted_quantity'] ?? null;
            if (!is_numeric($counted) || (float) $counted < 0 || abs((float) $counted * 1000 - round((float) $counted * 1000)) > 1e-6) {
                $problems[] = ['index' => $i, 'message' => 'counted_quantity must be zero or more, with at most 3 decimals.'];
                continue;
            }
            if (isset($items[$productId])) {
                $problems[] = ['index' => $i, 'message' => 'This product is counted twice; send one count per product.'];
                continue;
            }
            $items[$productId] = ['product_id' => $productId, 'counted_quantity' => round((float) $counted, 3), 'product' => $product];
        }
        if ($problems) {
            $this->error('Some items are invalid.', 422, $problems);
        }

        $model = new StocktakingModel();
        $id = $model->book((int) $warehouse['id'], $note !== '' ? $note : 'Mobil app', array_values(array_map(
            static fn(array $item): array => ['product_id' => $item['product_id'], 'counted_quantity' => $item['counted_quantity']],
            $items
        )), (int) $this->user['id']);
        $booked = $model->findById($id) ?? [];

        \Cloudexus\Core\AuditLog::record(
            \Cloudexus\Core\AuditLog::BOOK,
            'stocktaking',
            $id,
            (string) ($booked['stocktaking_number'] ?? $id),
            ['items' => count($items), 'via' => 'api'],
            ['id' => (int) $this->user['id'], 'name' => (string) $this->user['full_name']]
        );

        $this->json(['data' => [
            'id' => $id,
            'stocktaking_number' => $booked['stocktaking_number'] ?? null,
            'warehouse' => ['id' => (int) $warehouse['id'], 'name' => $warehouse['name']],
            'item_count' => (int) ($booked['item_count'] ?? count($items)),
            'diff_count' => (int) ($booked['diff_count'] ?? 0),
            'items' => array_map(static fn(array $row): array => [
                'product_id' => (int) $row['product_id'],
                'sku' => $row['sku'] ?? $items[(int) $row['product_id']]['product']['sku'],
                'product_name' => $row['product_name'] ?? $items[(int) $row['product_id']]['product']['name'],
                'book_quantity' => number_format((float) $row['book_quantity'], 3, '.', ''),
                'counted_quantity' => number_format((float) $row['counted_quantity'], 3, '.', ''),
                'diff' => number_format((float) $row['diff'], 3, '.', ''),
            ], $booked['items'] ?? []),
        ]], 201);
    }
}
