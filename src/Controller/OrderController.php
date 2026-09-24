<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Auth;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\PartnerAddressModel;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Sales\OrderModel;

class OrderController extends BaseController
{
    private OrderModel $orders;
    private PartnerModel $partners;
    private PartnerAddressModel $addresses;

    public function __construct()
    {
        parent::__construct();
        $this->orders = new OrderModel();
        $this->partners = new PartnerModel();
        $this->addresses = new PartnerAddressModel();
        $this->activeMenu = 'orders';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::ORDERS_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'partner_id' => (int) ($_GET['partner_id'] ?? 0),
            'status' => $_GET['status'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $pager = new \Cloudexus\Core\Paginator(25);

        $this->pageTitle = $this->t('orders.list_title');
        $this->render('orders/list.twig', [
            'orders' => $this->orders->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'partner_option' => $filters['partner_id'] ? $this->partners->labelsForIds([$filters['partner_id']]) : [],
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $this->pageTitle = $this->t('orders.new_full');
        $this->render('orders/form.twig', [
            'order_number' => $this->orders->nextOrderNumber(),
            'partner_addresses' => $this->addresses->all(),
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $items = $this->collectItems();

        if (empty($_POST['partner_id']) || empty($items)) {
            $this->flashError($this->t('orders.required'));
            $this->redirect('/orders/create');
        }

        $id = $this->orders->create([
            'order_number' => $_POST['order_number'],
            'partner_id' => (int) $_POST['partner_id'],
            'shipping_address_id' => (int) ($_POST['shipping_address_id'] ?? 0),
            'billing_address_id' => (int) ($_POST['billing_address_id'] ?? 0),
            'status' => 'confirmed',
            'order_date' => ($_POST['order_date'] ?? '') ?: date('Y-m-d'),
            'shipping_cost' => (float) str_replace(',', '.', $_POST['shipping_cost'] ?? '0'),
            'payment_cost' => (float) str_replace(',', '.', $_POST['payment_cost'] ?? '0'),
            'created_by' => Auth::id(),
        ], $items);

        $this->flashSuccess($this->t('orders.created'));
        $this->redirect('/orders/' . $id);
    }

    public function show(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_VIEW);

        $order = $this->orders->findById($id);
        if (!$order) {
            $this->redirect('/orders');
        }

        $this->pageTitle = $this->t('orders.title_prefix') . ': ' . $order['order_number'];
        $this->render('orders/show.twig', ['order' => $order]);
    }

    public function cancel(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $this->orders->updateStatus($id, 'cancelled');
        $this->flashSuccess($this->t('orders.cancelled'));
        $this->redirect('/orders/' . $id);
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $this->orders->delete($id);
        $this->flashSuccess($this->t('orders.deleted'));
        $this->redirect('/orders');
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
