<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Mailer;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Pdf;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Core\ProductModel;
use Cloudexus\Model\Core\WarehouseModel;
use Cloudexus\Model\Finance\PaymentModel;
use Cloudexus\Model\Sales\InvoiceModel;
use Cloudexus\Model\Sales\OrderModel;

class InvoiceController extends BaseController
{
    use HandlesPayments;

    private InvoiceModel $invoices;
    private PartnerModel $partners;
    private ProductModel $products;
    private OrderModel $orders;
    private WarehouseModel $warehouses;

    public function __construct()
    {
        parent::__construct();
        $this->invoices = new InvoiceModel();
        $this->partners = new PartnerModel();
        $this->products = new ProductModel();
        $this->orders = new OrderModel();
        $this->warehouses = new WarehouseModel();
        $this->activeMenu = 'invoices';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::INVOICES_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'partner_id' => (int) ($_GET['partner_id'] ?? 0),
            'status' => $_GET['status'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $pager = new Paginator(25);

        $this->pageTitle = $this->t('invoices.list_title');
        $this->render('invoices/list.twig', [
            'invoices' => $this->invoices->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'partner_option' => $filters['partner_id'] ? $this->partners->labelsForIds([$filters['partner_id']]) : [],
        ]);
    }

    public function export(): void
    {
        $this->requirePermission(Permissions::INVOICES_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'partner_id' => (int) ($_GET['partner_id'] ?? 0),
            'status' => $_GET['status'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $pager = new Paginator(1000000);
        $rows = $this->invoices->paginate($filters, $pager);

        $statusLabels = [
            'unpaid' => $this->t('invoices.csv_status.unpaid'),
            'paid' => $this->t('invoices.csv_status.paid'),
            'cancelled' => $this->t('invoices.csv_status.cancelled'),
        ];

        \Cloudexus\Core\CsvExporter::download(
            'szamlak',
            [
                $this->t('invoices.csv.number'), $this->t('invoices.csv.partner'), $this->t('invoices.csv.issue_date'),
                $this->t('invoices.csv.due_date'), $this->t('invoices.csv.status'), $this->t('invoices.csv.total'),
            ],
            array_map(fn($i) => [
                $i['invoice_number'], $i['partner_name'], $i['issue_date'], $i['due_date'],
                $statusLabels[$i['status']] ?? $i['status'], $i['total_amount'],
            ], $rows)
        );
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::INVOICES_ISSUE);

        $fromOrder = null;
        if (!empty($_GET['order_id'])) {
            $fromOrder = $this->orders->findById((int) $_GET['order_id']);

            // Egy rendelésből egy élő számla: a már számlázott vagy lemondott
            // rendelésből nem indul új.
            if ($fromOrder && ($fromOrder['status'] !== 'confirmed' || $this->invoices->orderIsInvoiced((int) $fromOrder['id']))) {
                $this->flashError($this->t('invoices.order_not_invoiceable', ['number' => $fromOrder['order_number']]));
                $this->redirect('/orders/' . $fromOrder['id']);
            }
        }

        $this->pageTitle = $this->t('invoices.new');
        $this->render('invoices/form.twig', [
            'invoice_number' => $this->invoices->nextInvoiceNumber(),
            'payment_methods' => InvoiceModel::PAYMENT_METHODS,
            'warehouses' => $this->warehouses->activeList(),
            'from_order' => $fromOrder,
            // A rendelésből átvett partner felirata a Select2 AJAX előtöltéshez.
            'partner_option' => $fromOrder ? $this->partners->labelsForIds([$fromOrder['partner_id']]) : [],
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::INVOICES_ISSUE);

        $items = $this->collectItems();

        if (empty($_POST['partner_id']) || empty($items)) {
            $this->flashError($this->t('invoices.required'));
            $this->redirect('/invoices/create');
        }

        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            $order = $this->orders->findById($orderId);
            if (!$order || $order['status'] !== 'confirmed' || $this->invoices->orderIsInvoiced($orderId)) {
                $this->flashError($this->t('invoices.order_not_invoiceable', ['number' => $order['order_number'] ?? $orderId]));
                $this->redirect('/invoices');
            }
        }

        $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);

        // A számlaszámot a mentés adja ki; az űrlapon látott csak előnézet.
        // A készletet is a mentés ellenőrzi, zárolt raktárral.
        try {
            $id = $this->invoices->create([
            'order_id' => $orderId ?: null,
            'partner_id' => (int) $_POST['partner_id'],
            'warehouse_id' => $warehouseId ?: null,
            'status' => 'unpaid',
            'issue_date' => ($_POST['issue_date'] ?? '') ?: date('Y-m-d'),
            'fulfilment_date' => ($_POST['fulfilment_date'] ?? '') ?: null,
            'due_date' => ($_POST['due_date'] ?? '') ?: date('Y-m-d', strtotime('+8 days')),
            'payment_method' => (string) ($_POST['payment_method'] ?? 'transfer'),
            'shipping_cost' => (float) str_replace(',', '.', $_POST['shipping_cost'] ?? '0'),
            'payment_cost' => (float) str_replace(',', '.', $_POST['payment_cost'] ?? '0'),
            'created_by' => Auth::id(),
            ], $items);
        } catch (\Cloudexus\Model\Core\StockShortage $e) {
            $this->flashError($this->t('invoices.shortage', ['items' => implode(', ', $this->describeShortages($e->shortages))]));
            $this->redirect('/invoices/create' . ($orderId ? '?order_id=' . $orderId : ''));
        }

        $issued = $this->invoices->findById($id);
        AuditLog::record(
            AuditLog::ISSUE,
            'invoice',
            $id,
            $issued['invoice_number'] ?? null,
            ['total' => \Cloudexus\Core\Currency::format((float) ($issued['total_amount'] ?? 0))]
        );

        $this->flashSuccess($warehouseId ? $this->t('invoices.created_with_stock') : $this->t('invoices.created'));
        $this->redirect('/invoices/' . $id);
    }

    public function show(int $id): void
    {
        $this->requirePermission(Permissions::INVOICES_VIEW);

        $invoice = $this->invoices->findById($id);
        if (!$invoice) {
            $this->redirect('/invoices');
        }

        $this->remember('invoice', $id);
        $this->pageTitle = $this->t('invoices.title_prefix') . ': ' . $invoice['invoice_number'];
        $this->render('invoices/show.twig', [
            'invoice' => $invoice,
            'payments' => (new PaymentModel())->forDocument(PaymentModel::INVOICE, $id),
            'mail_enabled' => Mailer::isConfigured(),
            'email_to' => $invoice['emailed_to'] ?: (new \Cloudexus\Model\Core\PartnerContactModel())->recipientFor((int) $invoice['partner_id'], true, $invoice['partner_email']),
            'recipients' => array_values(array_filter((new \Cloudexus\Model\Core\PartnerContactModel())->forPartner((int) $invoice['partner_id']), static fn(array $c): bool => (string) $c['email'] !== '')),
            'email_message' => $this->t('invoices.email_default_message', [
                'number' => $invoice['invoice_number'],
                'due' => $invoice['due_date'],
                'company' => (string) ((new \Cloudexus\Model\Core\SettingModel())->company()['name'] ?? ''),
            ]),
        ]);
    }

    /** A számla PDF-ként, letöltésre. */
    public function pdf(int $id): void
    {
        $this->requirePermission(Permissions::INVOICES_VIEW);

        $invoice = $this->invoices->findById($id);
        if (!$invoice) {
            $this->redirect('/invoices');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . self::pdfName($invoice) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $this->renderPdf($invoice);
        exit;
    }

    /**
     * A számla PDF-je e-mailben, a levélsoron át. A cím alapból a partneré,
     * de átírható; minden küldés az audit naplóba kerül.
     */
    public function email(int $id): void
    {
        $this->requirePermission(Permissions::INVOICES_ISSUE);

        $invoice = $this->invoices->findById($id);
        if (!$invoice) {
            $this->redirect('/invoices');
        }

        $to = trim((string) ($_POST['to'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->flashError($this->t('invoices.email_invalid'));
            $this->redirect('/invoices/' . $id);
        }

        $queued = Mailer::send(
            $to,
            (string) $invoice['partner_name'],
            $this->t('invoices.email_subject', ['number' => $invoice['invoice_number']]),
            $message,
            'invoice',
            self::pdfName($invoice),
            $this->renderPdf($invoice)
        );
        if (!$queued) {
            $this->flashError($this->t(Mailer::isConfigured() ? 'invoices.email_unreachable' : 'email.off', ['address' => $to]));
            $this->redirect('/invoices/' . $id);
        }

        $this->invoices->markEmailed($id, $to);
        AuditLog::record(AuditLog::EMAILED, 'invoice', $id, (string) $invoice['invoice_number'], ['to' => $to]);
        $this->flashSuccess($this->t('invoices.email_queued', ['address' => $to]));
        $this->redirect('/invoices/' . $id);
    }

    private function renderPdf(array $invoice): string
    {
        $html = $this->twig->render('invoices/pdf.twig', [
            'invoice' => $invoice,
            'company' => (new \Cloudexus\Model\Core\SettingModel())->company(),
            'current_locale' => \Cloudexus\Core\Lang::locale(),
        ]);

        return Pdf::render($html, $this->t('invoices.pdf_page'));
    }

    /** "SZLA-2026-0042.pdf" — a számlaszám, fájlnévnek való betűkkel. */
    private static function pdfName(array $invoice): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $invoice['invoice_number']) . '.pdf';
    }

    /** Printer-friendly invoice document. */
    public function printView(int $id): void
    {
        $this->requirePermission(Permissions::INVOICES_VIEW);

        $invoice = $this->invoices->findById($id);
        if (!$invoice) {
            $this->redirect('/invoices');
        }

        $this->render('invoices/print.twig', [
            'invoice' => $invoice,
            'company' => (new \Cloudexus\Model\Core\SettingModel())->company(),
        ]);
    }

    public function markPaid(int $id): void
    {
        $this->requirePermission(Permissions::FINANCE_MARK_PAID);

        if ($this->invoices->markPaid($id, Auth::id())) {
            AuditLog::record(AuditLog::PAID, 'invoice', $id, $this->invoices->findById($id)['invoice_number'] ?? null);
            $this->flashSuccess($this->t('invoices.marked_paid'));
        } else {
            $this->flashError($this->t('invoices.not_payable'));
        }
        $this->redirect('/invoices/' . $id);
    }

    /**
     * Sztornó számla: a kiállított számlát nem töröljük és nem írjuk át,
     * hanem egy ellentételező bizonylattal vonjuk vissza. A kiadott áru
     * visszakerül a raktárba, a rendelés újra számlázható lesz.
     */
    public function storno(int $id): void
    {
        $this->requirePermission(Permissions::INVOICES_STORNO);

        try {
            $stornoId = $this->invoices->storno($id, Auth::id());
        } catch (\DomainException) {
            $invoice = $this->invoices->findById($id);
            $this->flashError($this->t($invoice && (float) $invoice['paid_amount'] > 0 && $invoice['status'] === 'unpaid'
                ? 'invoices.storno_has_payments' : 'invoices.not_stornoable'));
            $this->redirect('/invoices/' . $id);
        }

        $original = $this->invoices->findById($id);
        $storno = $this->invoices->findById($stornoId);
        AuditLog::record(
            AuditLog::STORNO,
            'invoice',
            $stornoId,
            $storno['invoice_number'] ?? null,
            ['storno_of' => $original['invoice_number'] ?? $id]
        );
        $this->flashSuccess($this->t('invoices.stornoed'));
        $this->redirect('/invoices/' . $stornoId);
    }

    /**
     * @param array<int, array{available: float, requested: float}> $shortages
     * @return list<string> "SKU (elérhető: 2, kért: 5)" termékenként
     */
    private function describeShortages(array $shortages): array
    {
        $lines = [];
        foreach ($shortages as $productId => $shortage) {
            $product = $this->products->findById($productId);
            $lines[] = $this->t('invoices.shortage_item', [
                'sku' => $product['sku'] ?? $productId,
                'available' => \Cloudexus\Core\Quantity::format($shortage['available']),
                'requested' => \Cloudexus\Core\Quantity::format($shortage['requested']),
            ]);
        }

        return $lines;
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
        return PaymentModel::INVOICE;
    }

    protected function paymentBasePath(): string
    {
        return '/invoices';
    }

    protected function paymentDocumentNumber(int $id): ?string
    {
        return $this->invoices->findById($id)['invoice_number'] ?? null;
    }
}
