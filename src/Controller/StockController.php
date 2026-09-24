<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Auth;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\LocationModel;
use Cloudexus\Model\Core\ProductModel;
use Cloudexus\Model\Core\StockMovementModel;
use Cloudexus\Model\Core\WarehouseModel;

class StockController extends BaseController
{
    private StockMovementModel $movements;
    private WarehouseModel $warehouses;
    private ProductModel $products;
    private LocationModel $locations;

    public function __construct()
    {
        parent::__construct();
        $this->movements = new StockMovementModel();
        $this->warehouses = new WarehouseModel();
        $this->products = new ProductModel();
        $this->locations = new LocationModel();
    }

    public function overview(): void
    {
        $this->requirePermission(Permissions::STOCK_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'warehouse_id' => (int) ($_GET['warehouse_id'] ?? 0),
            'location_id' => (int) ($_GET['location_id'] ?? 0),
        ];
        $pager = new Paginator(25);

        $this->activeMenu = 'stock-overview';
        $this->pageTitle = $this->t('stock.overview_title');
        $this->render('stock/overview.twig', [
            'rows' => $this->movements->overview($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'warehouses' => $this->warehouses->activeList(),
            'locations' => $this->locations->activeWithWarehouse(),
        ]);
    }

    public function inList(): void
    {
        $this->requirePermission(Permissions::STOCK_VIEW);

        [$filters, $pager, $rows] = $this->movementListData('in');

        $this->activeMenu = 'stock-in';
        $this->pageTitle = $this->t('stock.in_title');
        $this->render('stock/in.twig', [
            'movements' => $rows,
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'warehouses' => $this->warehouses->activeList(),
            'locations' => $this->locations->activeWithWarehouse(),
            // ?product_id=… -val előre kiválasztható a termék (pl. a vezérlőpult
            // alacsony készlet listájának "Bevételezés" gombjáról érkezve) — a
            // Select2 AJAX választóhoz csak a kiválasztott termék felirata kell.
            'selected_product' => $this->selectedProductOption(),
        ]);
    }

    public function inCreate(): void
    {
        $this->requirePermission(Permissions::STOCK_MOVE);
        $this->createMovement('in', '/stock/in');
    }

    public function outList(): void
    {
        $this->requirePermission(Permissions::STOCK_VIEW);

        [$filters, $pager, $rows] = $this->movementListData('out');

        $this->activeMenu = 'stock-out';
        $this->pageTitle = $this->t('stock.out_title');
        $this->render('stock/out.twig', [
            'movements' => $rows,
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'warehouses' => $this->warehouses->activeList(),
            'locations' => $this->locations->activeWithWarehouse(),
            'selected_product' => $this->selectedProductOption(),
        ]);
    }

    public function outCreate(): void
    {
        $this->requirePermission(Permissions::STOCK_MOVE);
        $this->createMovement('out', '/stock/out');
    }

    public function transferForm(): void
    {
        $this->requirePermission(Permissions::STOCK_MOVE);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'warehouse_id' => (int) ($_GET['warehouse_id'] ?? 0),
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $pager = new Paginator(20);

        $this->activeMenu = 'stock-transfer';
        $this->pageTitle = $this->t('stock.transfer_title');
        $this->render('stock/transfer.twig', [
            'warehouses' => $this->warehouses->activeList(),
            'locations' => $this->locations->activeWithWarehouse(),
            'transfers' => $this->movements->paginateTransfers($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
        ]);
    }

    public function transferCreate(): void
    {
        $this->requirePermission(Permissions::STOCK_MOVE);

        $fromId = (int) ($_POST['from_warehouse_id'] ?? 0);
        $toId = (int) ($_POST['to_warehouse_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = (float) str_replace(',', '.', $_POST['quantity'] ?? '0');
        $note = trim($_POST['note'] ?? '');

        if ($fromId <= 0 || $toId <= 0 || $productId <= 0 || $quantity <= 0) {
            $this->flashError($this->t('stock.transfer_required'));
            $this->redirect('/stock/transfer');
        }

        if ($fromId === $toId) {
            $this->flashError($this->t('stock.transfer_same_warehouse'));
            $this->redirect('/stock/transfer');
        }

        $available = $this->movements->availableQuantity($productId, $fromId);
        if ($quantity > $available) {
            $this->flashError($this->t('stock.transfer_not_enough', [
                'available' => $available,
                'requested' => $quantity,
            ]));
            $this->redirect('/stock/transfer');
        }

        $from = $this->warehouses->findById($fromId);
        $to = $this->warehouses->findById($toId);

        $this->movements->transfer(
            $fromId,
            $toId,
            $productId,
            $quantity,
            'Raktárközi átadás: ' . ($from['name'] ?? $fromId) . ' → ' . ($to['name'] ?? $toId) . ($note !== '' ? ' — ' . $note : ''),
            Auth::id(),
            (int) ($_POST['from_location_id'] ?? 0) ?: null,
            (int) ($_POST['to_location_id'] ?? 0) ?: null
        );

        $this->flashSuccess($this->t('stock.transfer_created'));
        $this->redirect('/stock/transfer');
    }

    public function barcodeForm(): void
    {
        $this->requirePermission(Permissions::STOCK_MOVE);

        $this->activeMenu = 'stock-barcode';
        $this->pageTitle = $this->t('stock.barcode_title');
        $this->render('stock/barcode.twig', [
            'warehouses' => $this->warehouses->activeList(),
            'locations' => $this->locations->activeWithWarehouse(),
        ]);
    }

    /** JSON lookup endpoint: resolves a scanned barcode or SKU to a product. */
    public function barcodeLookup(): void
    {
        $this->requirePermission(Permissions::STOCK_MOVE);

        $code = trim($_GET['code'] ?? '');
        $product = $code !== '' ? $this->products->findByCode($code) : null;

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($product
            ? ['found' => true, 'product' => [
                'id' => (int) $product['id'],
                'sku' => $product['sku'],
                'name' => $product['name'],
                'unit' => $product['unit'],
            ]]
            : ['found' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Books all collected barcode rows as stock movements in one batch. */
    public function barcodeSubmit(): void
    {
        $this->requirePermission(Permissions::STOCK_MOVE);

        $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
        $direction = $_POST['direction'] === 'out' ? 'out' : 'in';
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];

        $items = [];
        foreach ($productIds as $index => $productId) {
            $productId = (int) $productId;
            $quantity = (float) str_replace(',', '.', $quantities[$index] ?? '0');
            if ($productId > 0 && $quantity > 0) {
                $items[$productId] = ($items[$productId] ?? 0) + $quantity;
            }
        }

        if ($warehouseId <= 0 || empty($items)) {
            $this->flashError($this->t('stock.barcode_required'));
            $this->redirect('/stock/barcode');
        }

        if ($direction === 'out') {
            foreach ($items as $productId => $quantity) {
                $available = $this->movements->availableQuantity($productId, $warehouseId);
                if ($quantity > $available) {
                    $product = $this->products->findById($productId);
                    $this->flashError($this->t('stock.barcode_not_enough', [
                        'sku' => $product['sku'] ?? $productId,
                        'available' => $available,
                        'requested' => $quantity,
                    ]));
                    $this->redirect('/stock/barcode');
                }
            }
        }

        $locationId = (int) ($_POST['location_id'] ?? 0) ?: null;
        foreach ($items as $productId => $quantity) {
            $this->movements->create([
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'product_id' => $productId,
                'type' => $direction,
                'quantity' => $quantity,
                'note' => 'Vonalkód gyűjtő',
                'created_by' => Auth::id(),
            ]);
        }

        $this->flashSuccess($this->t('stock.barcode_booked', [
            'count' => count($items),
            'direction' => $this->t($direction === 'in' ? 'stock.barcode_booked_as_in' : 'stock.barcode_booked_as_out'),
        ]));
        $this->redirect('/stock/barcode');
    }

    /** @return array{0: array, 1: Paginator, 2: array} */
    private function movementListData(string $type): array
    {
        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'warehouse_id' => (int) ($_GET['warehouse_id'] ?? 0),
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $pager = new Paginator(20);

        return [$filters, $pager, $this->movements->paginateByType($type, $filters, $pager)];
    }

    /** A ?product_id=-ból előre kiválasztott termék {id,text} párja, ha van. */
    private function selectedProductOption(): array
    {
        $id = (int) ($_GET['product_id'] ?? 0);
        return $id > 0 ? $this->products->labelsForIds([$id]) : [];
    }

    private function createMovement(string $type, string $redirectPath): void
    {
        $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = (float) str_replace(',', '.', $_POST['quantity'] ?? '0');
        $note = trim($_POST['note'] ?? '');

        if ($warehouseId <= 0 || $productId <= 0 || $quantity <= 0) {
            $this->flashError($this->t('stock.movement_required'));
            $this->redirect($redirectPath);
        }

        if ($type === 'out') {
            $available = $this->movements->availableQuantity($productId, $warehouseId);

            if ($quantity > $available) {
                $this->flashError($this->t('stock.not_enough', [
                    'available' => $available,
                    'requested' => $quantity,
                ]));
                $this->redirect($redirectPath);
            }
        }

        $this->movements->create([
            'warehouse_id' => $warehouseId,
            'location_id' => (int) ($_POST['location_id'] ?? 0) ?: null,
            'product_id' => $productId,
            'type' => $type,
            'quantity' => $quantity,
            'note' => $note,
            'created_by' => Auth::id(),
        ]);

        $this->flashSuccess($this->t($type === 'in' ? 'stock.in_created' : 'stock.out_created'));
        $this->redirect($redirectPath);
    }
}
