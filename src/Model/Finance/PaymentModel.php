<?php

namespace Cloudexus\Model\Finance;

use Cloudexus\Core\Currency;
use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Sort;

/**
 * Kifizetések a kimenő és a bejövő számlákon. Egy számlára több is jöhet
 * (részfizetés); a számla paid_amount oszlopa mindig ezek összege, a státusza
 * pedig akkor 'paid', ha a végösszeg megvan.
 *
 * Minden írás zárolja a számla sorát, így két párhuzamos befizetés nem
 * fizetheti túl ugyanazt a számlát.
 */
class PaymentModel
{
    public const INVOICE = 'invoice';
    public const INCOMING = 'incoming_invoice';
    public const METHODS = ['transfer', 'cash', 'card', 'cod'];

    /** Sortable columns of the aging report (see Sort): key => SQL expression (the grouped query's aliases). */
    public const AGING_SORTS = [
        'partner' => 'partner_name',
        'documents' => 'document_count',
        'current_amount' => 'current_amount',
        'd1_30' => 'd1_30',
        'd31_60' => 'd31_60',
        'd61_90' => 'd61_90',
        'd90_plus' => 'd90_plus',
        'total_open' => 'total_open',
    ];

    /** A float-zaj és a kerekítés miatt ennyi eltérés még "kifizetett". */
    private const EPSILON = 0.004;

    /** @return list<array<string, mixed>> a számla kifizetései, időrendben */
    public function forDocument(string $type, int $documentId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT pm.*, v.voucher_number, u.full_name AS created_by_name
             FROM payments pm
             LEFT JOIN cash_vouchers v ON v.id = pm.cash_voucher_id
             LEFT JOIN users u ON u.id = pm.created_by
             WHERE pm.' . self::column($type) . ' = :id
             ORDER BY pm.paid_on, pm.id'
        );
        $stmt->execute(['id' => $documentId]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM payments WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Egy befizetés rögzítése. A hívó tranzakciójához csatlakozik (pénztár-
     * bizonylat), ha nincs ilyen, sajátot nyit.
     *
     * @throws \DomainException 'not_payable' ha a számla nem fizethető,
     *                          'overpayment' ha az összeg több a nyitott egyenlegnél
     */
    public function record(string $type, int $documentId, float $amount, string $paidOn, string $method, ?string $note, ?int $userId, ?int $cashVoucherId = null): int
    {
        $amount = Currency::round($amount);
        if ($amount <= 0) {
            throw new \DomainException('amount');
        }

        return $this->inTransaction(function () use ($type, $documentId, $amount, $paidOn, $method, $note, $userId, $cashVoucherId): int {
            $document = $this->lockDocument($type, $documentId);
            if ($document === null || $document['status'] !== 'unpaid' || ($type === self::INVOICE && $document['invoice_type'] !== 'normal')) {
                throw new \DomainException('not_payable');
            }
            $balance = (float) $document['total_amount'] - (float) $document['paid_amount'];
            if ($amount > $balance + self::EPSILON) {
                throw new \DomainException('overpayment');
            }

            $pdo = DatabaseConnection::get();
            $pdo->prepare(
                'INSERT INTO payments (' . self::column($type) . ', amount, paid_on, method, note, cash_voucher_id, created_by)
                 VALUES (:document_id, :amount, :paid_on, :method, :note, :voucher_id, :created_by)'
            )->execute([
                'document_id' => $documentId,
                'amount' => $amount,
                'paid_on' => $paidOn,
                'method' => in_array($method, self::METHODS, true) ? $method : 'transfer',
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
                'voucher_id' => $cashVoucherId,
                'created_by' => $userId,
            ]);
            $paymentId = (int) $pdo->lastInsertId();

            $this->refresh($type, $documentId);

            return $paymentId;
        });
    }

    /** A teljes nyitott egyenleg befizetése — a "Kifizetve" gomb. */
    public function settle(string $type, int $documentId, string $method, ?int $userId): ?int
    {
        return $this->inTransaction(function () use ($type, $documentId, $method, $userId): ?int {
            $document = $this->lockDocument($type, $documentId);
            if ($document === null) {
                throw new \DomainException('not_payable');
            }
            $balance = (float) $document['total_amount'] - (float) $document['paid_amount'];

            return $this->record($type, $documentId, $balance, date('Y-m-d'), $method, null, $userId);
        });
    }

    /**
     * Egy befizetés visszavonása (téves rögzítés). Egy pénztárbizonylathoz
     * tartozót csak a bizonylat törlése vonhat vissza ($fromVoucher).
     *
     * @throws \DomainException 'voucher' ha a befizetés bizonylathoz tartozik
     */
    public function delete(int $paymentId, bool $fromVoucher = false): void
    {
        $this->inTransaction(function () use ($paymentId, $fromVoucher): void {
            $payment = $this->findById($paymentId);
            if ($payment === null) {
                return;
            }
            if ($payment['cash_voucher_id'] !== null && !$fromVoucher) {
                throw new \DomainException('voucher');
            }

            [$type, $documentId] = $payment['invoice_id'] !== null
                ? [self::INVOICE, (int) $payment['invoice_id']]
                : [self::INCOMING, (int) $payment['incoming_invoice_id']];
            $this->lockDocument($type, $documentId);

            DatabaseConnection::get()->prepare('DELETE FROM payments WHERE id = :id')->execute(['id' => $paymentId]);
            $this->refresh($type, $documentId);
        });
    }

    /** A bizonylathoz tartozó befizetés id-ja, ha van. */
    public function idForVoucher(int $cashVoucherId): ?int
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT id FROM payments WHERE cash_voucher_id = :id');
        $stmt->execute(['id' => $cashVoucherId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Nyitott tételek korosítva, partnerenként. A kosarak a lejárat óta eltelt
     * napok szerint: még nem járt le, 1–30, 31–60, 61–90 és 90 napon túl.
     * Az $asOf napi állapot: az addig kiállított számlák, az addig beérkezett
     * kifizetésekkel.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function aging(string $type, string $asOf): array
    {
        $table = $type === self::INVOICE ? 'invoices' : 'incoming_invoices';
        $normalOnly = $type === self::INVOICE ? "AND i.invoice_type = 'normal'" : '';
        $column = self::column($type);

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT p.id AS partner_id, p.name AS partner_name, COUNT(*) AS document_count,
                    SUM(CASE WHEN d.days <= 0 THEN d.open ELSE 0 END) AS current_amount,
                    SUM(CASE WHEN d.days BETWEEN 1 AND 30 THEN d.open ELSE 0 END) AS d1_30,
                    SUM(CASE WHEN d.days BETWEEN 31 AND 60 THEN d.open ELSE 0 END) AS d31_60,
                    SUM(CASE WHEN d.days BETWEEN 61 AND 90 THEN d.open ELSE 0 END) AS d61_90,
                    SUM(CASE WHEN d.days > 90 THEN d.open ELSE 0 END) AS d90_plus,
                    SUM(d.open) AS total_open,
                    MAX(d.days) AS oldest_days
             FROM (
                 SELECT i.partner_id, DATEDIFF(:as_of1, i.due_date) AS days,
                        i.total_amount - COALESCE((SELECT SUM(pm.amount) FROM payments pm
                                                   WHERE pm.$column = i.id AND pm.paid_on <= :as_of2), 0) AS open
                 FROM $table i
                 WHERE i.issue_date <= :as_of3 $normalOnly
                   AND (i.status = 'unpaid' OR (i.status = 'paid' AND EXISTS (
                        SELECT 1 FROM payments pm2 WHERE pm2.$column = i.id AND pm2.paid_on > :as_of4)))
             ) d
             JOIN partners p ON p.id = d.partner_id
             WHERE d.open > 0.004
             GROUP BY p.id, p.name
             ORDER BY " . Sort::orderBy(self::AGING_SORTS, 'total_open DESC, partner_name ASC')
        );
        $stmt->execute(['as_of1' => $asOf, 'as_of2' => $asOf, 'as_of3' => $asOf, 'as_of4' => $asOf]);
        $rows = $stmt->fetchAll();

        $totals = ['document_count' => 0, 'current_amount' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total_open' => 0.0];
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += (float) $row[$key];
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** paid_amount = a kifizetések összege; a státusz ehhez igazodik. */
    private function refresh(string $type, int $documentId): void
    {
        $table = $type === self::INVOICE ? 'invoices' : 'incoming_invoices';
        $column = self::column($type);
        DatabaseConnection::get()->prepare(
            "UPDATE $table d
             SET d.paid_amount = (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE $column = d.id),
                 d.status = CASE
                     WHEN d.status NOT IN ('unpaid', 'paid') THEN d.status
                     WHEN (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE $column = d.id) >= d.total_amount - " . self::EPSILON . " THEN 'paid'
                     ELSE 'unpaid' END
             WHERE d.id = :id"
        )->execute(['id' => $documentId]);
    }

    private function lockDocument(string $type, int $documentId): ?array
    {
        $table = $type === self::INVOICE ? 'invoices' : 'incoming_invoices';
        $stmt = DatabaseConnection::get()->prepare("SELECT * FROM $table WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $documentId]);

        return $stmt->fetch() ?: null;
    }

    private static function column(string $type): string
    {
        return match ($type) {
            self::INVOICE => 'invoice_id',
            self::INCOMING => 'incoming_invoice_id',
        };
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $pdo = DatabaseConnection::get();
        if ($pdo->inTransaction()) {
            return $work();
        }

        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
