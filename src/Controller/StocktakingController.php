<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\StockMovementModel;
use Cloudexus\Model\Core\StocktakingModel;
use Cloudexus\Model\Core\WarehouseModel;

class StocktakingController extends BaseController
{
    private StocktakingModel $stocktakings;
    private WarehouseModel $warehouses;
    private StockMovementModel $stock;

    public function __construct()
    {
        parent::__construct();
        $this->stocktakings = new StocktakingModel();
        $this->warehouses = new WarehouseModel();
        $this->stock = new StockMovementModel();
        $this->activeMenu = 'stocktaking';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::STOCK_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'warehouse_id' => (int) ($_GET['warehouse_id'] ?? 0),
        ];
        $pager = new Paginator(30);

        $this->pageTitle = $this->t('stocktaking.page_title');
        $this->render('stocktaking/list.twig', [
            'stocktakings' => $this->stocktakings->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'warehouses' => $this->warehouses->all(),
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::STOCKTAKING_MANAGE);

        $warehouseId = (int) ($_GET['warehouse_id'] ?? 0);
        $sheet = $warehouseId > 0 ? $this->stock->stockSheet($warehouseId) : [];

        $this->pageTitle = $this->t('stocktaking.new');
        $this->render('stocktaking/form.twig', [
            'warehouses' => $this->warehouses->activeList(),
            'warehouse_id' => $warehouseId,
            'sheet' => $sheet,
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::STOCKTAKING_MANAGE);

        $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $productIds = $_POST['product_id'] ?? [];
        $bookQtys = $_POST['book_quantity'] ?? [];
        $countedQtys = $_POST['counted_quantity'] ?? [];

        if ($warehouseId <= 0 || empty($productIds)) {
            $this->flashError($this->t('stocktaking.warehouse_and_sheet_required'));
            $this->redirect('/stocktaking/create');
        }

        $items = [];
        foreach ($productIds as $index => $productId) {
            $counted = $countedQtys[$index] ?? '';
            if ($counted === '') {
                continue; // a kihagyott sort nem leltározzuk
            }
            $items[] = [
                'product_id' => (int) $productId,
                'book_quantity' => (float) str_replace(',', '.', $bookQtys[$index] ?? '0'),
                'counted_quantity' => (float) str_replace(',', '.', $counted),
            ];
        }

        if (empty($items)) {
            $this->flashError($this->t('stocktaking.counted_quantity_required'));
            $this->redirect('/stocktaking/create?warehouse_id=' . $warehouseId);
        }

        $id = $this->stocktakings->book($warehouseId, $note, $items, Auth::id());
        AuditLog::record(AuditLog::BOOK, 'stocktaking', $id, $this->stocktakings->findById($id)['stocktaking_number'] ?? null,
            ['items' => count($items)]);

        $this->flashSuccess($this->t('stocktaking.booked'));
        $this->redirect('/stocktaking/' . $id);
    }

    public function show(int $id): void
    {
        $this->requirePermission(Permissions::STOCK_VIEW);

        $stocktaking = $this->stocktakings->findById($id);
        if (!$stocktaking) {
            $this->redirect('/stocktaking');
        }

        $this->pageTitle = $this->t('stocktaking.show_title', ['number' => $stocktaking['stocktaking_number']]);
        $this->render('stocktaking/show.twig', ['stocktaking' => $stocktaking]);
    }
}
