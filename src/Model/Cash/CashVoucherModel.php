<?php

namespace Cloudexus\Model\Cash;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\DocumentNumber;
use Cloudexus\Core\Sort;

class CashVoucherModel
{
    public function all(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT v.*, p.name AS partner_name,
                    i.invoice_number AS sales_invoice_number,
                    ii.invoice_number AS incoming_invoice_number
             FROM cash_vouchers v
             LEFT JOIN partners p ON p.id = v.partner_id
             LEFT JOIN invoices i ON i.id = v.invoice_id
             LEFT JOIN incoming_invoices ii ON ii.id = v.incoming_invoice_id
             ORDER BY v.voucher_date DESC, v.id DESC'
        )->fetchAll();
    }

    /** Sortable columns of the list (see Sort): key => SQL expression. */
    public const SORTS = [
        'number' => 'v.voucher_number',
        'type' => 'v.type',
        'partner' => 'partner_name',
        'invoice' => 'COALESCE(i.invoice_number, ii.invoice_number)',
        'date' => 'v.voucher_date',
        // Signed, as the list shows it: income positive, expense negative.
        'amount' => "CASE WHEN v.type = 'bevetel' THEN v.amount ELSE -v.amount END",
    ];

    /** Filters: q (voucher_number/note/partner), type, date_from, date_to. */
    public function paginate(array $filters, \Cloudexus\Core\Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(v.voucher_number LIKE :q1 OR v.note LIKE :q2 OR p.name LIKE :q3)';
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        if ($filters['type'] !== '') {
            $where[] = 'v.type = :type';
            $params['type'] = $filters['type'];
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'v.voucher_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'v.voucher_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare(
            "SELECT COUNT(*) FROM cash_vouchers v LEFT JOIN partners p ON p.id = v.partner_id $whereSql"
        );
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT v.*, p.name AS partner_name,
                    i.invoice_number AS sales_invoice_number,
                    ii.invoice_number AS incoming_invoice_number
             FROM cash_vouchers v
             LEFT JOIN partners p ON p.id = v.partner_id
             LEFT JOIN invoices i ON i.id = v.invoice_id
             LEFT JOIN incoming_invoices ii ON ii.id = v.incoming_invoice_id
             $whereSql
             ORDER BY " . Sort::orderBy(self::SORTS, 'v.voucher_date DESC, v.id DESC') . "
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** A várható következő sorszám, az űrlapon tájékoztatásnak — a valódit a mentés kapja. */
    public function nextVoucherNumber(): string
    {
        return DocumentNumber::preview('cash_voucher');
    }

    public function create(array $data): int
    {
        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();

        try {
            $number = DocumentNumber::take('cash_voucher', (string) ($data['voucher_date'] ?? ''));
            $stmt = $pdo->prepare(
                'INSERT INTO cash_vouchers (voucher_number, type, amount, partner_id, invoice_id, incoming_invoice_id, note, voucher_date, created_by, created_at)
                 VALUES (:voucher_number, :type, :amount, :partner_id, :invoice_id, :incoming_invoice_id, :note, :voucher_date, :created_by, NOW())'
            );
            $stmt->execute([
                'voucher_number' => $number,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'partner_id' => $data['partner_id'] ?? null,
                'invoice_id' => $data['invoice_id'] ?? null,
                'incoming_invoice_id' => $data['incoming_invoice_id'] ?? null,
                'note' => $data['note'] ?: null,
                'voucher_date' => $data['voucher_date'],
                'created_by' => $data['created_by'] ?: null,
            ]);

            $id = (int) $pdo->lastInsertId();

            // A számlához kötött bizonylat egy befizetés a számlán: részben
            // vagy egészen kiegyenlíti, de túl nem fizetheti (DomainException).
            $payments = new \Cloudexus\Model\Finance\PaymentModel();
            foreach ([['invoice_id', $payments::INVOICE], ['incoming_invoice_id', $payments::INCOMING]] as [$key, $type]) {
                if (!empty($data[$key])) {
                    $payments->record($type, (int) $data[$key], (float) $data['amount'], (string) $data['voucher_date'], 'cash',
                        null, $data['created_by'] ?: null, $id);
                }
            }

            $pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function findNumber(int $id): ?string
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT voucher_number FROM cash_vouchers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $number = $stmt->fetchColumn();

        return $number === false ? null : (string) $number;
    }

    /** Törlés: ha számlát egyenlített, a befizetés is visszavonódik (a számla újra nyitott lesz). */
    public function delete(int $id): void
    {
        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();
        try {
            $payments = new \Cloudexus\Model\Finance\PaymentModel();
            $paymentId = $payments->idForVoucher($id);
            if ($paymentId !== null) {
                $payments->delete($paymentId, true);
            }
            $pdo->prepare('DELETE FROM cash_vouchers WHERE id = :id')->execute(['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function currentBalance(): float
    {
        return (float) DatabaseConnection::get()->query(
            "SELECT COALESCE(SUM(CASE WHEN type = 'bevetel' THEN amount ELSE -amount END), 0) FROM cash_vouchers"
        )->fetchColumn();
    }
}
