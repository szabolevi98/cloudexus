<?php

namespace Cloudexus\Model\Sales;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\DocumentNumber;
use Cloudexus\Core\Sort;

/**
 * Az árajánlatok — lásd a 17_quotes.sql migrációt. A sorok a számlához
 * hasonlóan árazódnak (InvoiceModel::priceLines, totals), így az ajánlat
 * összege ugyanaz, ami a számlán lesz belőle.
 */
class QuoteModel
{
    /** Az ajánlat alapból ennyi napig érvényes. */
    public const VALID_DAYS = 15;

    public const SORTS = [
        'number' => 'q.quote_number',
        'partner' => 'partner_name',
        'date' => 'q.quote_date',
        'valid_until' => 'q.valid_until',
        'status' => 'q.status',
        'total' => 'q.total_amount',
    ];

    /**
     * Az állapot, ahogy látszik: egy elküldött, de lejárt ajánlat "expired".
     *
     * @param array<string, mixed> $quote
     */
    public static function effectiveStatus(array $quote): string
    {
        return $quote['status'] === 'sent' && (string) $quote['valid_until'] < date('Y-m-d') ? 'expired' : (string) $quote['status'];
    }

    /** Filters: q (szám vagy partner), partner_id, status (expired is), date_from, date_to. */
    public function paginate(array $filters, \Cloudexus\Core\Paginator $pager): array
    {
        $where = [];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(q.quote_number LIKE :q1 OR p.name LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['partner_id'])) {
            $where[] = 'q.partner_id = :partner_id';
            $params['partner_id'] = (int) $filters['partner_id'];
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'expired') {
            $where[] = "q.status = 'sent' AND q.valid_until < CURDATE()";
        } elseif ($status === 'sent') {
            $where[] = "q.status = 'sent' AND q.valid_until >= CURDATE()";
        } elseif ($status !== '') {
            $where[] = 'q.status = :status';
            $params['status'] = $status;
        }
        if (($filters['date_from'] ?? '') !== '') {
            $where[] = 'q.quote_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (($filters['date_to'] ?? '') !== '') {
            $where[] = 'q.quote_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM quotes q JOIN partners p ON p.id = q.partner_id $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT q.*, p.name AS partner_name FROM quotes q JOIN partners p ON p.id = q.partner_id
             $whereSql
             ORDER BY " . Sort::orderBy(self::SORTS, 'q.quote_date DESC, q.id DESC') . "
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return array_map(static fn(array $row): array => $row + ['effective_status' => self::effectiveStatus($row)], $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null az ajánlat a soraival, a partnerrel és a belőle lett rendeléssel */
    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT q.*, p.name AS partner_name, p.email AS partner_email, p.tax_number, p.address,
                    o.order_number
             FROM quotes q JOIN partners p ON p.id = q.partner_id
             LEFT JOIN orders o ON o.id = q.order_id
             WHERE q.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $quote = $stmt->fetch();
        if (!$quote) {
            return null;
        }

        $items = DatabaseConnection::get()->prepare('SELECT qi.*, qi.product_sku AS sku, qi.unit_code AS unit FROM quote_items qi WHERE qi.quote_id = :id ORDER BY qi.id');
        $items->execute(['id' => $id]);
        $quote['items'] = $items->fetchAll();
        $quote['vat_summary'] = InvoiceModel::vatSummary($quote);
        $quote['effective_status'] = self::effectiveStatus($quote);

        return $quote;
    }

    /**
     * @param array{partner_id: int, quote_date: string, valid_until: string, shipping_cost: float, payment_cost: float, note: string, created_by: ?int} $data
     * @param list<array{product_id: int, quantity: float, unit_price: float}> $items
     */
    public function create(array $data, array $items): int
    {
        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();
        try {
            $number = DocumentNumber::take('quote', $data['quote_date']);
            $lines = InvoiceModel::priceLines($items);
            $totals = InvoiceModel::totals($lines, $data['shipping_cost'], $data['payment_cost'], InvoiceModel::EXTRA_VAT_RATE);

            $pdo->prepare(
                'INSERT INTO quotes (quote_number, partner_id, status, quote_date, valid_until, shipping_cost, payment_cost, extra_vat_rate,
                                     net_total, vat_total, total_amount, note, created_by, created_at)
                 VALUES (:number, :partner_id, \'draft\', :quote_date, :valid_until, :shipping, :payment, :extra_vat,
                         :net, :vat, :gross, :note, :created_by, NOW())'
            )->execute([
                'number' => $number,
                'partner_id' => $data['partner_id'],
                'quote_date' => $data['quote_date'],
                'valid_until' => $data['valid_until'],
                'shipping' => $totals['shipping'],
                'payment' => $totals['payment'],
                'extra_vat' => InvoiceModel::EXTRA_VAT_RATE,
                'net' => $totals['net'],
                'vat' => $totals['vat'],
                'gross' => $totals['gross'],
                'note' => $data['note'] !== '' ? $data['note'] : null,
                'created_by' => $data['created_by'],
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->insertLines($id, $lines);
            $pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Egy még nem elfogadott ajánlat átírása: a sorok újra árazva.
     *
     * @param array{partner_id: int, quote_date: string, valid_until: string, shipping_cost: float, payment_cost: float, note: string} $data
     * @param list<array{product_id: int, quantity: float, unit_price: float}> $items
     */
    public function update(int $id, array $data, array $items): bool
    {
        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT status FROM quotes WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            if (!in_array($lock->fetchColumn(), ['draft', 'sent'], true)) {
                $pdo->rollBack();

                return false;
            }

            $lines = InvoiceModel::priceLines($items);
            $totals = InvoiceModel::totals($lines, $data['shipping_cost'], $data['payment_cost'], InvoiceModel::EXTRA_VAT_RATE);
            $pdo->prepare(
                'UPDATE quotes SET partner_id = :partner_id, quote_date = :quote_date, valid_until = :valid_until,
                        shipping_cost = :shipping, payment_cost = :payment, net_total = :net, vat_total = :vat, total_amount = :gross,
                        note = :note WHERE id = :id'
            )->execute([
                'id' => $id,
                'partner_id' => $data['partner_id'],
                'quote_date' => $data['quote_date'],
                'valid_until' => $data['valid_until'],
                'shipping' => $totals['shipping'],
                'payment' => $totals['payment'],
                'net' => $totals['net'],
                'vat' => $totals['vat'],
                'gross' => $totals['gross'],
                'note' => $data['note'] !== '' ? $data['note'] : null,
            ]);
            $pdo->prepare('DELETE FROM quote_items WHERE quote_id = :id')->execute(['id' => $id]);
            $this->insertLines($id, $lines);
            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function markSent(int $id, ?string $to): void
    {
        DatabaseConnection::get()->prepare(
            "UPDATE quotes SET status = IF(status = 'draft', 'sent', status), sent_at = NOW(), emailed_to = COALESCE(:to, emailed_to) WHERE id = :id"
        )->execute(['id' => $id, 'to' => $to]);
    }

    /** Elfogadva vagy elutasítva — egy piszkozat vagy elküldött ajánlat. */
    public function decide(int $id, bool $accepted, ?string $reason = null): bool
    {
        $stmt = DatabaseConnection::get()->prepare(
            "UPDATE quotes SET status = :status, rejection_reason = :reason WHERE id = :id AND status IN ('draft', 'sent')"
        );
        $stmt->execute(['id' => $id, 'status' => $accepted ? 'accepted' : 'rejected', 'reason' => $accepted ? null : $reason]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Rendelés az ajánlatból, ugyanazokkal a sorokkal és árakkal. Csak egyszer,
     * és elutasított ajánlatból nem. Visszaadja a rendelés azonosítóját.
     *
     * @throws \DomainException 'not_convertible'
     */
    public function toOrder(int $id, ?int $userId): int
    {
        $quote = $this->findById($id);
        if ($quote === null || $quote['order_id'] !== null || !in_array($quote['status'], ['draft', 'sent', 'accepted'], true)) {
            throw new \DomainException('not_convertible');
        }

        $items = array_map(static fn(array $line): array => [
            'product_id' => (int) $line['product_id'],
            'quantity' => (float) $line['quantity'],
            'unit_price' => (float) $line['unit_price'],
        ], $quote['items']);

        $orderId = (new OrderModel())->create([
            'partner_id' => (int) $quote['partner_id'],
            'shipping_address_id' => 0,
            'billing_address_id' => 0,
            'status' => 'confirmed',
            'order_date' => date('Y-m-d'),
            'shipping_cost' => (float) $quote['shipping_cost'],
            'payment_cost' => (float) $quote['payment_cost'],
            'created_by' => $userId,
        ], $items);

        $pdo = DatabaseConnection::get();
        $pdo->prepare('UPDATE orders SET quote_id = :quote WHERE id = :order')->execute(['quote' => $id, 'order' => $orderId]);
        $pdo->prepare("UPDATE quotes SET status = 'ordered', order_id = :order WHERE id = :id")->execute(['order' => $orderId, 'id' => $id]);
        // Az ajánlathoz kötött üzlet ezzel megnyerve.
        \Cloudexus\Model\Crm\DealModel::winFromQuote($id, $orderId);

        return $orderId;
    }

    /** Törölhető, amíg nem lett belőle (még meglévő) rendelés. */
    public function delete(int $id): bool
    {
        $stmt = DatabaseConnection::get()->prepare('DELETE FROM quotes WHERE id = :id AND order_id IS NULL');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** @param list<array<string, mixed>> $lines */
    private function insertLines(int $quoteId, array $lines): void
    {
        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO quote_items (quote_id, product_id, product_name, product_sku, unit_code, quantity, unit_price, vat_rate, net_amount, vat_amount, gross_amount)
             VALUES (:quote_id, :product_id, :product_name, :product_sku, :unit_code, :quantity, :unit_price, :vat_rate, :net_amount, :vat_amount, :gross_amount)'
        );
        foreach ($lines as $line) {
            $stmt->execute(['quote_id' => $quoteId] + array_intersect_key($line, array_flip([
                'product_id', 'product_name', 'product_sku', 'unit_code', 'quantity', 'unit_price', 'vat_rate', 'net_amount', 'vat_amount', 'gross_amount',
            ])));
        }
    }
}
