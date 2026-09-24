<?php

namespace Cloudexus\Model\Sales;

use Cloudexus\Core\Currency;
use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\DocumentNumber;
use Cloudexus\Core\Paginator;
use Cloudexus\Model\Core\SettingModel;

class InvoiceModel
{
    /** A fizetési módok, ahogy a számla mondja. */
    public const PAYMENT_METHODS = ['transfer', 'cash', 'card', 'cod'];

    /** A szállítási és a fizetési költség ÁFA-kulcsa: az általános kulcs. */
    public const EXTRA_VAT_RATE = 27.0;

    public function all(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT i.*, p.name AS partner_name
             FROM invoices i
             JOIN partners p ON p.id = i.partner_id
             ORDER BY i.issue_date DESC, i.id DESC'
        )->fetchAll();
    }

    /** Filters: q (invoice_number), partner_id, status, date_from, date_to (issue_date). */
    public function paginate(array $filters, Paginator $pager): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = 'i.invoice_number LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['partner_id'])) {
            $where[] = 'i.partner_id = :partner_id';
            $params['partner_id'] = (int) $filters['partner_id'];
        }
        if ($filters['status'] !== '') {
            if ($filters['status'] === 'overdue') {
                $where[] = "i.status = 'unpaid' AND i.due_date < CURDATE()";
            } else {
                $where[] = 'i.status = :status';
                $params['status'] = $filters['status'];
            }
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'i.issue_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'i.issue_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'i.updated_at >= :updated_since';
            $params['updated_since'] = $filters['updated_since'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM invoices i $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT i.*, p.name AS partner_name
             FROM invoices i
             JOIN partners p ON p.id = i.partner_id
             $whereSql
             ORDER BY i.issue_date DESC, i.id DESC
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        // A vevő a kiállításkor rögzített adataival; a régebbi, rögzítés
        // előtti számláknál a partner mostani adataival.
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT i.*, COALESCE(i.buyer_name, p.name) AS partner_name,
                    COALESCE(i.buyer_tax_number, p.tax_number) AS tax_number,
                    i.buyer_address AS address, w.name AS warehouse_name,
                    so.invoice_number AS storno_of_number, sb.id AS storno_by_id, sb.invoice_number AS storno_by_number
             FROM invoices i
             JOIN partners p ON p.id = i.partner_id
             LEFT JOIN warehouses w ON w.id = i.warehouse_id
             LEFT JOIN invoices so ON so.id = i.storno_of_id
             LEFT JOIN invoices sb ON sb.storno_of_id = i.id
             WHERE i.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            return null;
        }

        $invoice['items'] = $this->items($id);
        $invoice['vat_summary'] = self::vatSummary($invoice);

        return $invoice;
    }

    public function items(int $invoiceId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT ii.*,
                    COALESCE(ii.product_sku, pr.sku) AS sku,
                    COALESCE(ii.product_name, ' . \Cloudexus\Core\Translation::pick('pd', 'name') . ') AS product_name,
                    COALESCE(ii.unit_code, un.code) AS unit
             FROM invoice_items ii
             JOIN products pr ON pr.id = ii.product_id
             LEFT JOIN units un ON un.id = pr.unit_id
             ' . \Cloudexus\Core\Translation::join('product_description', 'product_id', 'pr.id', 'pd') . '
             WHERE ii.invoice_id = :invoice_id'
        );
        $stmt->execute(['invoice_id' => $invoiceId]);

        return $stmt->fetchAll();
    }

    /** A várható következő számlaszám, az űrlapon tájékoztatásnak — a valódit a mentés kapja. */
    public function nextInvoiceNumber(): string
    {
        return DocumentNumber::preview('invoice');
    }

    /**
     * Kiállítja a számlát: sorszám a számlálóból, a tranzakción belül; minden
     * sor a termék ÁFA-kulcsával, nettó, ÁFA és bruttó összeggel; a vevő és
     * az eladó adatai rögzítve. Ha warehouse_id meg van adva, minden sort
     * raktári kiadásként is könyvel: a raktárat zárolja, és ha nincs elég
     * készlet, StockShortage-et dob, a számla pedig el sem készül.
     */
    public function create(array $data, array $items): int
    {
        $pdo = DatabaseConnection::get();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();

        try {
            if (!empty($data['warehouse_id'])) {
                $stock = new \Cloudexus\Model\Core\StockMovementModel();
                $stock->lockWarehouses([(int) $data['warehouse_id']]);
                $needed = [];
                foreach ($items as $item) {
                    $needed[(int) $item['product_id']] = ($needed[(int) $item['product_id']] ?? 0) + (float) $item['quantity'];
                }
                $stock->assertAvailable((int) $data['warehouse_id'], $needed);
            }

            $issueDate = (string) $data['issue_date'];
            $number = DocumentNumber::take('invoice', $issueDate);
            $lines = self::priceLines($items);
            $totals = self::totals($lines, (float) ($data['shipping_cost'] ?? 0), (float) ($data['payment_cost'] ?? 0), self::EXTRA_VAT_RATE);
            $buyer = self::buyer((int) $data['partner_id'], !empty($data['order_id']) ? (int) $data['order_id'] : null);
            $seller = (new SettingModel())->company();

            $stmt = $pdo->prepare(
                "INSERT INTO invoices (invoice_number, invoice_type, order_id, partner_id, warehouse_id, status, issue_date, fulfilment_date, due_date,
                                       payment_method, net_total, vat_total, total_amount, shipping_cost, payment_cost, extra_vat_rate,
                                       buyer_name, buyer_tax_number, buyer_address, seller_name, seller_tax_number, seller_address, seller_bank_account,
                                       created_by, created_at)
                 VALUES (:invoice_number, 'normal', :order_id, :partner_id, :warehouse_id, :status, :issue_date, :fulfilment_date, :due_date,
                         :payment_method, :net_total, :vat_total, :total_amount, :shipping_cost, :payment_cost, :extra_vat_rate,
                         :buyer_name, :buyer_tax_number, :buyer_address, :seller_name, :seller_tax_number, :seller_address, :seller_bank_account,
                         :created_by, NOW())"
            );
            $stmt->execute([
                'invoice_number' => $number,
                'order_id' => $data['order_id'] ?: null,
                'partner_id' => $data['partner_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'status' => $data['status'],
                'issue_date' => $issueDate,
                'fulfilment_date' => ($data['fulfilment_date'] ?? '') ?: $issueDate,
                'due_date' => $data['due_date'],
                'payment_method' => in_array($data['payment_method'] ?? '', self::PAYMENT_METHODS, true) ? $data['payment_method'] : 'transfer',
                'net_total' => $totals['net'],
                'vat_total' => $totals['vat'],
                'total_amount' => $totals['gross'],
                'shipping_cost' => $totals['shipping'],
                'payment_cost' => $totals['payment'],
                'extra_vat_rate' => self::EXTRA_VAT_RATE,
                'buyer_name' => $buyer['name'],
                'buyer_tax_number' => $buyer['tax_number'],
                'buyer_address' => $buyer['address'],
                'seller_name' => ($seller['name'] ?? '') ?: null,
                'seller_tax_number' => ($seller['tax_number'] ?? '') ?: null,
                'seller_address' => ($seller['address'] ?? '') ?: null,
                'seller_bank_account' => ($seller['bank_account'] ?? '') ?: null,
                'created_by' => $data['created_by'] ?: null,
            ]);

            $invoiceId = (int) $pdo->lastInsertId();
            $this->insertLines($invoiceId, $lines);

            if (!empty($data['warehouse_id'])) {
                $stockStmt = $pdo->prepare(
                    "INSERT INTO stock_movements (warehouse_id, product_id, type, quantity, note, created_by, created_at)
                     VALUES (:warehouse_id, :product_id, 'out', :quantity, :note, :created_by, :created_at)"
                );
                foreach ($lines as $line) {
                    $stockStmt->execute([
                        'warehouse_id' => $data['warehouse_id'],
                        'product_id' => $line['product_id'],
                        'quantity' => $line['quantity'],
                        'note' => 'Értékesítés: ' . $number,
                        'created_by' => $data['created_by'] ?: null,
                        'created_at' => $issueDate . ' ' . date('H:i:s'),
                    ]);
                }
            }

            if (!empty($data['order_id'])) {
                $pdo->prepare("UPDATE orders SET status = 'invoiced' WHERE id = :id")
                    ->execute(['id' => $data['order_id']]);
            }

            $pdo->commit();

            return $invoiceId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Sztornó számla egy kiállított, még ki nem fizetett számláról: saját
     * sorszámmal, ugyanazokkal a sorokkal negatív előjellel. Az eredeti
     * számla „sztornózva” állapotba kerül, a raktári kiadása bevételként
     * visszakönyvelődik, a rendelése pedig újra számlázható lesz.
     *
     * @return int a sztornó számla azonosítója
     * @throws \DomainException ha a számla nem sztornózható
     */
    public function storno(int $id, ?int $userId, ?string $date = null): int
    {
        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();

        try {
            $lock = $pdo->prepare('SELECT * FROM invoices WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            $original = $lock->fetch();

            if (!$original || $original['invoice_type'] !== 'normal' || $original['status'] !== 'unpaid') {
                throw new \DomainException('This invoice cannot be cancelled with a storno invoice.');
            }

            $date = $date ?: date('Y-m-d');
            $number = DocumentNumber::take('invoice', $date);

            $pdo->prepare(
                "INSERT INTO invoices (invoice_number, invoice_type, storno_of_id, order_id, partner_id, warehouse_id, status, issue_date, fulfilment_date,
                                       due_date, payment_method, net_total, vat_total, total_amount, shipping_cost, payment_cost, extra_vat_rate,
                                       buyer_name, buyer_tax_number, buyer_address, seller_name, seller_tax_number, seller_address, seller_bank_account,
                                       created_by, created_at)
                 SELECT :number, 'storno', id, order_id, partner_id, warehouse_id, 'storno', :issue_date, fulfilment_date,
                        :due_date, payment_method, -COALESCE(net_total, total_amount), -COALESCE(vat_total, 0), -total_amount,
                        -shipping_cost, -payment_cost, extra_vat_rate,
                        buyer_name, buyer_tax_number, buyer_address, seller_name, seller_tax_number, seller_address, seller_bank_account,
                        :created_by, NOW()
                 FROM invoices WHERE id = :id"
            )->execute(['number' => $number, 'issue_date' => $date, 'due_date' => $date, 'created_by' => $userId, 'id' => $id]);
            $stornoId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO invoice_items (invoice_id, product_id, product_name, product_sku, unit_code, quantity, unit_price, vat_rate,
                                            line_total, net_amount, vat_amount, gross_amount)
                 SELECT :storno_id, product_id, product_name, product_sku, unit_code, -quantity, unit_price, vat_rate,
                        -line_total, -COALESCE(net_amount, line_total), -COALESCE(vat_amount, 0), -COALESCE(gross_amount, line_total)
                 FROM invoice_items WHERE invoice_id = :id'
            )->execute(['storno_id' => $stornoId, 'id' => $id]);

            // A kiadott áru visszakerül abba a raktárba, ahonnan kiment.
            if (!empty($original['warehouse_id'])) {
                $pdo->prepare(
                    "INSERT INTO stock_movements (warehouse_id, product_id, type, quantity, note, created_by, created_at)
                     SELECT :warehouse_id, product_id, 'in', quantity, :note, :created_by, NOW()
                     FROM invoice_items WHERE invoice_id = :id"
                )->execute([
                    'warehouse_id' => $original['warehouse_id'],
                    'note' => 'Sztornó: ' . $number . ' (' . $original['invoice_number'] . ')',
                    'created_by' => $userId,
                    'id' => $id,
                ]);
            }

            $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = :id")->execute(['id' => $id]);

            if (!empty($original['order_id'])) {
                $pdo->prepare("UPDATE orders SET status = 'confirmed' WHERE id = :id AND status = 'invoiced'")
                    ->execute(['id' => $original['order_id']]);
            }

            $pdo->commit();

            return $stornoId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Kifizetettnek jelöl egy kiállított, még nyitott számlát; másikat nem. */
    public function markPaid(int $id): bool
    {
        $stmt = DatabaseConnection::get()->prepare(
            "UPDATE invoices SET status = 'paid' WHERE id = :id AND status = 'unpaid' AND invoice_type = 'normal'"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** Van-e a rendelésnek élő (nem sztornózott) számlája. */
    public function orderIsInvoiced(int $orderId): bool
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT EXISTS (SELECT 1 FROM invoices WHERE order_id = :id AND invoice_type = 'normal' AND status <> 'cancelled')"
        );
        $stmt->execute(['id' => $orderId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * A tételsorok árazva: a termék ÁFA-kulcsa, nettó, ÁFA és bruttó összeg,
     * az elsődleges pénznem pontosságára kerekítve. Ismeretlen termék sora
     * kimarad — és így a végösszegbe sem számít bele.
     *
     * @param list<array{product_id: int, quantity: float, unit_price: float}> $items
     * @return list<array<string, mixed>>
     */
    public static function priceLines(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(static fn(array $i): int => (int) $i['product_id'], $items)));
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT p.id, p.sku, p.vat_rate, COALESCE(d.name, p.sku) AS name, u.code AS unit_code
             FROM products p
             LEFT JOIN product_description d ON d.product_id = p.id AND d.language_id = ' . \Cloudexus\Core\Language::defaultId() . '
             LEFT JOIN units u ON u.id = p.unit_id
             WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        $products = [];
        foreach ($stmt->fetchAll() as $row) {
            $products[(int) $row['id']] = $row;
        }

        $lines = [];
        foreach ($items as $item) {
            $product = $products[(int) $item['product_id']] ?? null;
            if ($product === null) {
                continue;
            }

            $net = Currency::round((float) $item['quantity'] * (float) $item['unit_price']);
            $rate = (float) $product['vat_rate'];
            $vat = Currency::round($net * $rate / 100);

            $lines[] = [
                'product_id' => (int) $product['id'],
                'product_name' => (string) $product['name'],
                'product_sku' => (string) $product['sku'],
                'unit_code' => $product['unit_code'],
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'vat_rate' => $rate,
                'net_amount' => $net,
                'vat_amount' => $vat,
                'gross_amount' => $net + $vat,
            ];
        }

        return $lines;
    }

    /**
     * A számla összegei: a sorok, a szállítási és a fizetési költség (nettó,
     * az általános kulccsal), mind kerekítve.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{net: float, vat: float, gross: float, shipping: float, payment: float}
     */
    public static function totals(array $lines, float $shipping, float $payment, float $extraVatRate): array
    {
        $shipping = Currency::round($shipping);
        $payment = Currency::round($payment);
        $net = array_sum(array_column($lines, 'net_amount')) + $shipping + $payment;
        $vat = array_sum(array_column($lines, 'vat_amount')) + Currency::round(($shipping + $payment) * $extraVatRate / 100);

        return ['net' => $net, 'vat' => $vat, 'gross' => $net + $vat, 'shipping' => $shipping, 'payment' => $payment];
    }

    /**
     * ÁFA-összesítő kulcsonként: nettó, ÁFA, bruttó — a sorokból és a
     * szállítási/fizetési költségből.
     *
     * @return list<array{rate: float, net: float, vat: float, gross: float}>
     */
    public static function vatSummary(array $invoice): array
    {
        $byRate = [];
        foreach ($invoice['items'] ?? [] as $item) {
            $rate = number_format((float) $item['vat_rate'], 2, '.', '');
            $byRate[$rate]['net'] = ($byRate[$rate]['net'] ?? 0) + (float) ($item['net_amount'] ?? $item['line_total']);
            $byRate[$rate]['vat'] = ($byRate[$rate]['vat'] ?? 0) + (float) ($item['vat_amount'] ?? 0);
        }

        $extraNet = (float) $invoice['shipping_cost'] + (float) $invoice['payment_cost'];
        if ($extraNet != 0.0) {
            $rate = number_format((float) ($invoice['extra_vat_rate'] ?? self::EXTRA_VAT_RATE), 2, '.', '');
            $byRate[$rate]['net'] = ($byRate[$rate]['net'] ?? 0) + $extraNet;
            $byRate[$rate]['vat'] = ($byRate[$rate]['vat'] ?? 0) + Currency::round($extraNet * (float) $rate / 100);
        }

        krsort($byRate);
        $summary = [];
        foreach ($byRate as $rate => $sum) {
            $summary[] = ['rate' => (float) $rate, 'net' => $sum['net'], 'vat' => $sum['vat'], 'gross' => $sum['net'] + $sum['vat']];
        }

        return $summary;
    }

    /**
     * Akinek a számla szól, ahogy a kiállításkor van: név, adószám és cím —
     * a rendelés számlázási címe, különben a partner első címe.
     *
     * @return array{name: string, tax_number: ?string, address: ?string}
     */
    public static function buyer(int $partnerId, ?int $orderId): array
    {
        $pdo = DatabaseConnection::get();
        $partner = $pdo->prepare('SELECT name, tax_number, address FROM partners WHERE id = :id');
        $partner->execute(['id' => $partnerId]);
        $row = $partner->fetch() ?: ['name' => '', 'tax_number' => null, 'address' => null];

        $address = $pdo->prepare(
            'SELECT a.* FROM partner_addresses a
             WHERE a.id = COALESCE((SELECT billing_address_id FROM orders WHERE id = :order),
                                   (SELECT MIN(id) FROM partner_addresses WHERE partner_id = :partner))'
        );
        $address->execute(['order' => $orderId ?? 0, 'partner' => $partnerId]);
        $a = $address->fetch();

        $text = $a
            ? trim($a['postal_code'] . ' ' . $a['city'] . ', ' . $a['street'] . ($a['country'] !== 'Magyarország' ? ', ' . $a['country'] : ''))
            : (((string) ($row['address'] ?? '')) ?: null);

        return ['name' => (string) $row['name'], 'tax_number' => ($row['tax_number'] ?? '') ?: null, 'address' => $text];
    }

    /** @param list<array<string, mixed>> $lines */
    private function insertLines(int $invoiceId, array $lines): void
    {
        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO invoice_items (invoice_id, product_id, product_name, product_sku, unit_code, quantity, unit_price, vat_rate,
                                        line_total, net_amount, vat_amount, gross_amount)
             VALUES (:invoice_id, :product_id, :product_name, :product_sku, :unit_code, :quantity, :unit_price, :vat_rate,
                     :line_total, :net_amount, :vat_amount, :gross_amount)'
        );

        foreach ($lines as $line) {
            $stmt->execute($line + ['invoice_id' => $invoiceId, 'line_total' => $line['net_amount']]);
        }
    }

    public function unpaidList(): array
    {
        return DatabaseConnection::get()->query(
            "SELECT i.*, p.name AS partner_name
             FROM invoices i
             JOIN partners p ON p.id = i.partner_id
             WHERE i.status = 'unpaid'
             ORDER BY i.due_date ASC"
        )->fetchAll();
    }

    public function recent(int $limit = 6): array
    {
        return DatabaseConnection::get()->query(
            'SELECT i.*, p.name AS partner_name
             FROM invoices i
             JOIN partners p ON p.id = i.partner_id
             ORDER BY i.issue_date DESC, i.id DESC
             LIMIT ' . (int) $limit
        )->fetchAll();
    }

    public function outstandingTotal(): float
    {
        return (float) DatabaseConnection::get()
            ->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status = 'unpaid'")
            ->fetchColumn();
    }

    /** Unpaid total split into overdue (past due date) and current. */
    public function outstandingBreakdown(): array
    {
        $row = DatabaseConnection::get()->query(
            "SELECT COALESCE(SUM(total_amount), 0) AS total,
                    COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN total_amount ELSE 0 END), 0) AS overdue
             FROM invoices WHERE status = 'unpaid'"
        )->fetch();

        return [
            'total' => (float) $row['total'],
            'overdue' => (float) $row['overdue'],
            'current' => (float) $row['total'] - (float) $row['overdue'],
        ];
    }
}
