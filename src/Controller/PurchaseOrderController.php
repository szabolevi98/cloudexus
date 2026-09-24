<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Auth;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Purchasing\PurchaseOrderModel;

class PurchaseOrderController extends BaseController
{
    private PurchaseOrderModel $orders;
    private PartnerModel $partners;

    public function __construct()
    {
        parent::__construct();
        $this->orders = new PurchaseOrderModel();
        $this->partners = new PartnerModel();
        $this->activeMenu = 'purchase-orders';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::PURCHASING_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'partner_id' => (int) ($_GET['partner_id'] ?? 0),
            'status' => $_GET['status'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $pager = new \Cloudexus\Core\Paginator(25);

        $this->pageTitle = $this->t('purchase_orders.list_title');
        $this->render('purchase-orders/list.twig', [
            'orders' => $this->orders->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'partner_option' => $filters['partner_id'] ? $this->partners->labelsForIds([$filters['partner_id']]) : [],
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::PURCHASING_MANAGE);

        $this->pageTitle = $this->t('purchase_orders.new_full');
        $this->render('purchase-orders/form.twig', [
            'po_number' => $this->orders->nextPoNumber(),
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::PURCHASING_MANAGE);

        $items = $this->collectItems();

        if (empty($_POST['partner_id']) || empty($items)) {
            $this->flashError($this->t('purchase_orders.required'));
            $this->redirect('/purchase-orders/create');
        }

        $id = $this->orders->create([
            'po_number' => $_POST['po_number'],
            'partner_id' => (int) $_POST['partner_id'],
            'status' => 'confirmed',
            'order_date' => ($_POST['order_date'] ?? '') ?: date('Y-m-d'),
            'created_by' => Auth::id(),
        ], $items);

        $this->flashSuccess($this->t('purchase_orders.created'));
        $this->redirect('/purchase-orders/' . $id);
    }

    public function show(int $id): void
    {
        $this->requirePermission(Permissions::PURCHASING_VIEW);

        $order = $this->orders->findById($id);
        if (!$order) {
            $this->redirect('/purchase-orders');
        }

        $this->pageTitle = $this->t('purchase_orders.title_prefix') . ': ' . $order['po_number'];
        $this->render('purchase-orders/show.twig', ['order' => $order]);
    }

    public function cancel(int $id): void
    {
        $this->requirePermission(Permissions::PURCHASING_MANAGE);

        $this->orders->updateStatus($id, 'cancelled');
        $this->flashSuccess($this->t('purchase_orders.cancelled'));
        $this->redirect('/purchase-orders/' . $id);
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::PURCHASING_MANAGE);

        $this->orders->delete($id);
        $this->flashSuccess($this->t('purchase_orders.deleted'));
        $this->redirect('/purchase-orders');
    }

    private function collectItems(): array
    {
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unitPrices = $_POST['unit_price'] ?? [];

        $items = [];
        foreach ($productIds as $index => $productId) {
            $productId = (int) $productId;
            $quantity = (float) str_replace(',', '.', $quantities[$index] ?? '0');
            $unitPrice = (float) str_replace(',', '.', $unitPrices[$index] ?? '0');

            if ($productId > 0 && $quantity > 0) {
                $items[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ];
            }
        }

        return $items;
    }
}
