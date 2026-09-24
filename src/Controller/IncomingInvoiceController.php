<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Core\WarehouseModel;
use Cloudexus\Model\Finance\PaymentModel;
use Cloudexus\Model\Purchasing\IncomingInvoiceModel;
use Cloudexus\Model\Purchasing\PurchaseOrderModel;

class IncomingInvoiceController extends BaseController
{
    use HandlesPayments;

    private IncomingInvoiceModel $invoices;
    private PartnerModel $partners;
    private WarehouseModel $warehouses;
    private PurchaseOrderModel $orders;

    public function __construct()
    {
        parent::__construct();
        $this->invoices = new IncomingInvoiceModel();
        $this->partners = new PartnerModel();
        $this->warehouses = new WarehouseModel();
        $this->orders = new PurchaseOrderModel();
        $this->activeMenu = 'incoming-invoices';
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

        $this->pageTitle = $this->t('incoming_invoices.list_title');
        $this->render('incoming-invoices/list.twig', [
            'invoices' => $this->invoices->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'partner_option' => $filters['partner_id'] ? $this->partners->labelsForIds([$filters['partner_id']]) : [],
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::PURCHASING_MANAGE);

        $fromOrder = null;
        if (!empty($_GET['order_id'])) {
            $fromOrder = $this->orders->findById((int) $_GET['order_id']);
        }

        $this->pageTitle = $this->t('incoming_invoices.new');
        $this->render('incoming-invoices/form.twig', [
            'invoice_number' => $this->invoices->nextInvoiceNumber(),
            'warehouses' => $this->warehouses->activeList(),
            'from_order' => $fromOrder,
            'partner_option' => $fromOrder ? $this->partners->labelsForIds([$fromOrder['partner_id']]) : [],
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::PURCHASING_MANAGE);

        $items = $this->collectItems();

        if (empty($_POST['partner_id']) || empty($items)) {
            $this->flashError($this->t('incoming_invoices.required'));
            $this->redirect('/incoming-invoices/create');
        }

        $id = $this->invoices->create([
            'purchase_order_id' => ($_POST['purchase_order_id'] ?? '') ?: null,
            'partner_id' => (int) $_POST['partner_id'],
            'warehouse_id' => ($_POST['warehouse_id'] ?? '') ?: null,
            'issue_date' => ($_POST['issue_date'] ?? '') ?: date('Y-m-d'),
            'due_date' => ($_POST['due_date'] ?? '') ?: date('Y-m-d', strtotime('+8 days')),
            'created_by' => Auth::id(),
        ], $items);

        $this->flashSuccess(!empty($_POST['warehouse_id'])
            ? $this->t('incoming_invoices.created_with_stock')
            : $this->t('incoming_invoices.created'));
        $this->redirect('/incoming-invoices/' . $id);
    }

    public function show(int $id): void
    {
        $this->requirePermission(Permissions::PURCHASING_VIEW);

        $invoice = $this->invoices->findById($id);
        if (!$invoice) {
            $this->redirect('/incoming-invoices');
        }

        $this->pageTitle = $this->t('incoming_invoices.title_prefix') . ': ' . $invoice['invoice_number'];
        $this->render('incoming-invoices/show.twig', [
            'invoice' => $invoice,
            'payments' => (new PaymentModel())->forDocument(PaymentModel::INCOMING, $id),
        ]);
    }

    public function markPaid(int $id): void
    {
        $this->requirePermission(Permissions::FINANCE_MARK_PAID);

        if (!$this->invoices->markPaid($id, Auth::id())) {
            $this->flashError($this->t('incoming_invoices.not_payable'));
            $this->redirect('/incoming-invoices/' . $id);
        }
        AuditLog::record(AuditLog::PAID, 'incoming_invoice', $id, $this->invoices->findById($id)['invoice_number'] ?? null);
        $this->flashSuccess($this->t('incoming_invoices.marked_paid'));
        $this->redirect('/incoming-invoices/' . $id);
    }

    public function cancel(int $id): void
    {
        $this->requirePermission(Permissions::PURCHASING_MANAGE);

        try {
            $this->invoices->cancel($id, Auth::id());
        } catch (\Cloudexus\Model\Core\StockShortage $e) {
            $this->flashError($this->t('incoming_invoices.cancel_shortage'));
            $this->redirect('/incoming-invoices/' . $id);
        } catch (\DomainException) {
            $invoice = $this->invoices->findById($id);
            $this->flashError($this->t($invoice && (float) $invoice['paid_amount'] > 0 && $invoice['status'] === 'unpaid'
                ? 'incoming_invoices.cancel_has_payments' : 'incoming_invoices.not_cancellable'));
            $this->redirect('/incoming-invoices/' . $id);
        }
        AuditLog::record(AuditLog::STORNO, 'incoming_invoice', $id, $this->invoices->findById($id)['invoice_number'] ?? null);
        $this->flashSuccess($this->t('incoming_invoices.cancelled'));
        $this->redirect('/incoming-invoices/' . $id);
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

    protected function paymentType(): string
    {
        return PaymentModel::INCOMING;
    }

    protected function paymentBasePath(): string
    {
        return '/incoming-invoices';
    }

    protected function paymentDocumentNumber(int $id): ?string
    {
        return $this->invoices->findById($id)['invoice_number'] ?? null;
    }
}
