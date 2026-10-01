<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Model\Core\ProductModel;
use Cloudexus\Model\Core\StockMovementModel;

class ProductApiController extends ApiController
{
    public function index(): void
    {
        $this->authenticate();

        $filters = $this->baseFilters() + [
            'category_id' => (int) ($_GET['category_id'] ?? 0),
            'status' => $_GET['status'] ?? '',
        ];
        $pager = $this->paginator();
        $rows = (new ProductModel())->paginate($filters, $pager);

        $this->collection($rows, $pager);
    }

    public function show(int $id): void
    {
        $this->authenticate();

        $product = (new ProductModel())->findFull($id);
        if (!$product) {
            $this->error('Product not found.', 404);
        }
        $this->resource($product);
    }

    /**
     * Resolves a scanned code (barcode first, then SKU) to an active product,
     * with its stock per warehouse and location. A query parameter rather than
     * a path segment, because a scanned code may contain a slash.
     */
    public function lookup(): void
    {
        $this->authenticate();

        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            $this->error('The code query parameter is required.', 422);
        }

        $product = (new ProductModel())->findByCode($code);
        if (!$product) {
            $this->error('No active product with this barcode or SKU.', 404);
        }

        $stock = array_map(static fn(array $row): array => [
            'warehouse_id' => (int) $row['warehouse_id'],
            'warehouse_name' => $row['warehouse_name'],
            'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
            'location_code' => $row['location_code'],
            'quantity' => $row['quantity'],
        ], (new StockMovementModel())->stockForProduct((int) $product['id']));

        $total = (float) array_sum(array_column($stock, 'quantity'));
        $image = (new ProductModel())->images((int) $product['id'])[0]['path'] ?? null;

        $this->resource([
            'id' => (int) $product['id'],
            'sku' => $product['sku'],
            'barcode' => $product['barcode'],
            'name' => $product['name'],
            'unit' => $product['unit'],
            'unit_name' => $product['unit_name'],
            'min_stock' => $product['min_stock'],
            'matched_by' => $product['barcode'] === $code ? 'barcode' : 'sku',
            'stock_total' => number_format($total, 3, '.', ''),
            'stock' => $stock,
            // What a worker at the shelf asks next: the price on the label, and
            // whether the product needs reordering.
            'price' => $product['price'],
            'sale_price' => $product['sale_price'],
            'vat_rate' => $product['vat_rate'],
            'below_min_stock' => (float) $product['min_stock'] > 0 && $total < (float) $product['min_stock'],
            'image_url' => $image === null ? null : (preg_match('~^https?://~', (string) $image) ? $image : rtrim((string) \Cloudexus\Core\Config::get('app.base_url'), '/') . '/' . ltrim((string) $image, '/')),
        ]);
    }

    public function showBySku(string $sku): void
    {
        $this->authenticate();

        $product = (new ProductModel())->findFullBySku($sku);
        if (!$product) {
            $this->error('Product not found.', 404);
        }
        $this->resource($product);
    }
}
