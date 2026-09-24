<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Cash\CashVoucherModel;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Purchasing\IncomingInvoiceModel;
use Cloudexus\Model\Sales\InvoiceModel;

class CashVoucherController extends BaseController
{
    private CashVoucherModel $vouchers;
    private PartnerModel $partners;
    private InvoiceModel $invoices;
    private IncomingInvoiceModel $incomingInvoices;

    public function __construct()
    {
        parent::__construct();
        $this->vouchers = new CashVoucherModel();
        $this->partners = new PartnerModel();
        $this->invoices = new InvoiceModel();
        $this->incomingInvoices = new IncomingInvoiceModel();
        $this->activeMenu = 'cash';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::CASH_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'type' => $_GET['type'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $pager = new \Cloudexus\Core\Paginator(25);

        $this->pageTitle = $this->t('cash.list_title');
        $this->render('cash/list.twig', [
            'vouchers' => $this->vouchers->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'balance' => $this->vouchers->currentBalance(),
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::CASH_MANAGE);

        $this->pageTitle = $this->t('cash.new_voucher');
        $this->render('cash/form.twig', [
            'voucher_number' => $this->vouchers->nextVoucherNumber(),
            'unpaid_invoices' => $this->invoices->unpaidList(),
            'unpaid_incoming_invoices' => $this->incomingInvoices->unpaidList(),
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::CASH_MANAGE);

        $amount = (float) str_replace(',', '.', $_POST['amount'] ?? '0');

        if ($amount <= 0) {
            $this->flashError($this->t('cash.amount_required'));
            $this->redirect('/cash/create');
        }

        // Egy üresen hagyott választó '' értéket küld, ami idegen kulcsként
        // nem létező sorra mutatna: a hiányzót NULL-ként mentjük.
        $ref = static fn(string $key): ?int => (int) ($_POST[$key] ?? 0) > 0 ? (int) $_POST[$key] : null;
        $type = in_array($_POST['type'] ?? '', ['bevetel', 'kiadas'], true) ? $_POST['type'] : 'bevetel';

        $id = $this->vouchers->create([
            'type' => $type,
            'amount' => $amount,
            'partner_id' => $ref('partner_id'),
            'invoice_id' => $ref('invoice_id'),
            'incoming_invoice_id' => $ref('incoming_invoice_id'),
            'note' => trim($_POST['note'] ?? ''),
            'voucher_date' => ($_POST['voucher_date'] ?? '') ?: date('Y-m-d'),
            'created_by' => Auth::id(),
        ]);

        AuditLog::record(AuditLog::CREATE, 'cash_voucher', $id, $this->vouchers->findNumber($id),
            ['type' => $this->t($type === 'kiadas' ? 'cash.expense' : 'cash.income'), 'total' => \Cloudexus\Core\Currency::format($amount)]);
        $this->flashSuccess($this->t('cash.created'));
        $this->redirect('/cash');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::CASH_MANAGE);

        $number = $this->vouchers->findNumber($id);
        $this->vouchers->delete($id);
        AuditLog::record(AuditLog::DELETE, 'cash_voucher', $id, $number);
        $this->flashSuccess($this->t('cash.deleted'));
        $this->redirect('/cash');
    }
}
