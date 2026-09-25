<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Currency;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Finance\PaymentModel;

/**
 * Befizetés rögzítése és visszavonása egy számla oldaláról — a kimenő és a
 * bejövő számla controllere ugyanígy csinálja, csak a típus és az útvonal más.
 */
trait HandlesPayments
{
    /** PaymentModel::INVOICE vagy PaymentModel::INCOMING */
    abstract protected function paymentType(): string;

    /** '/invoices' vagy '/incoming-invoices' */
    abstract protected function paymentBasePath(): string;

    /** A számla száma a naplóhoz. */
    abstract protected function paymentDocumentNumber(int $id): ?string;

    public function addPayment(int $id): void
    {
        $this->requirePermission(Permissions::FINANCE_MARK_PAID);

        $amount = (float) str_replace([',', ' '], ['.', ''], (string) ($_POST['amount'] ?? '0'));
        $paidOn = (string) ($_POST['paid_on'] ?? '');
        $method = (string) ($_POST['payment_method'] ?? 'transfer');
        $note = trim((string) ($_POST['note'] ?? ''));

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $paidOn, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $paidOn = date('Y-m-d');
        }
        if ($paidOn > date('Y-m-d')) {
            $this->flashError($this->t('payments.future_date'));
            $this->redirect($this->paymentBasePath() . '/' . $id);
        }

        try {
            (new PaymentModel())->record($this->paymentType(), $id, $amount, $paidOn, $method, $note, Auth::id());
        } catch (\DomainException $e) {
            $this->flashError($this->t('payments.error_' . $e->getMessage()));
            $this->redirect($this->paymentBasePath() . '/' . $id);
        }

        AuditLog::record(AuditLog::PAID, $this->paymentType(), $id, $this->paymentDocumentNumber($id), [
            'amount' => Currency::format($amount),
            'method' => $this->t('invoices.payment_methods.' . (in_array($method, PaymentModel::METHODS, true) ? $method : 'transfer')),
        ]);
        $this->flashSuccess($this->t('payments.recorded'));
        $this->redirect($this->paymentBasePath() . '/' . $id);
    }

    public function deletePayment(int $id, int $paymentId): void
    {
        $this->requirePermission(Permissions::FINANCE_MARK_PAID);

        $payments = new PaymentModel();
        $payment = $payments->findById($paymentId);
        $column = $this->paymentType() === PaymentModel::INVOICE ? 'invoice_id' : 'incoming_invoice_id';
        if ($payment === null || (int) $payment[$column] !== $id) {
            $this->redirect($this->paymentBasePath() . '/' . $id);
        }

        try {
            $payments->delete($paymentId);
        } catch (\DomainException) {
            $this->flashError($this->t('payments.error_voucher'));
            $this->redirect($this->paymentBasePath() . '/' . $id);
        }

        AuditLog::record(
            AuditLog::DELETE,
            'payment',
            $paymentId,
            $this->paymentDocumentNumber($id),
            ['amount' => Currency::format((float) $payment['amount'])]
        );
        $this->flashSuccess($this->t('payments.deleted'));
        $this->redirect($this->paymentBasePath() . '/' . $id);
    }
}
