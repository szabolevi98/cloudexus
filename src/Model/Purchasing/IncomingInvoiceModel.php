<?php

namespace Cloudexus\Model\Purchasing;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\DocumentNumber;

class IncomingInvoiceModel
{
    public function all(): array
    {
        return DatabaseConnection::get()->query(
            'SELECT i.*, p.name AS partner_name
             FROM incoming_invoices i
             JOIN partners p ON p.id = i.partner_id
             ORDER BY i.issue_date DESC, i.id DESC'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT i.*, p.name AS partner_name, p.tax_number, w.name AS warehouse_name
             FROM incoming_invoices i
             JOIN partners p ON p.id = i.partner_id
             LEFT JOIN warehouses w ON w.id = i.warehouse_id
             WHERE i.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            return null;
        }

        $invoice['items'] = $this->items($id);

        return $invoice;
    }

    public function items(int $invoiceId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT ii.*,
                    COALESCE(ii.product_sku, pr.sku) AS sku,
                    COALESCE(ii.product_name, ' . \Cloudexus\Core\Translation::pick('pd', 'name') . ') AS product_name,
                    COALESCE(ii.unit_code, un.code) AS unit
             FROM incoming_invoice_items ii
             JOIN products pr ON pr.id = ii.product_id
             LEFT JOIN units un ON un.id = pr.unit_id
             ' . \Cloudexus\Core\Translation::join('product_description', 'product_id', 'pr.id', 'pd') . '
             WHERE ii.incoming_invoice_id = :invoice_id'
        );
        $stmt->execute(['invoice_id' => $invoiceId]);

        return $stmt->fetchAll();
    }

    /** Filters: q (invoice_number), partner_id, status, date_from, date_to (issue_date). */
    public function paginate(array $filters, \Cloudexus\Core\Paginator $pager): array
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

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = DatabaseConnection::get()->prepare("SELECT COUNT(*) FROM incoming_invoices i $whereSql");
        $count->execute($params);
        $pager->total = (int) $count->fetchColumn();
        $pager->clamp();

        $stmt = DatabaseConnection::get()->prepare(
            "SELECT i.*, p.name AS partner_name
             FROM incoming_invoices i
             JOIN partners p ON p.id = i.partner_id
             $whereSql
             ORDER BY i.issue_date DESC, i.id DESC
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Unpaid total split into overdue (past due date) and current. */
    public function outstandingBreakdown(): array
    {
        $row = DatabaseConnection::get()->query(
            "SELECT COALESCE(SUM(total_amount), 0) AS total,
                    COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN total_amount ELSE 0 END), 0) AS overdue
             FROM incoming_invoices WHERE status = 'unpaid'"
        )->fetch();

        return [
            'total' => (float) $row['total'],
            'overdue' => (float) $row['overdue'],
            'current' => (float) $row['total'] - (float) $row['overdue'],
        ];
    }

    /** A várható következő sorszám, az űrlapon tájékoztatásnak — a valódit a mentés kapja. */
    public function nextInvoiceNumber(): string
    {
        return DocumentNumber::preview('incoming_invoice');
    }

    /**
     * Creates the incoming invoice and, when a warehouse is given, books each
     * line item as a stock-in movement so the goods receipt updates raktárkészlet.
     */
    public function create(array $data, array $items): int
    {
        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();

        try {
            $number = DocumentNumber::take('incoming_invoice', (string) ($data['issue_date'] ?? ''));
            $total = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));

            $stmt = $pdo->prepare(
                'INSERT INTO incoming_invoices (invoice_number, purchase_order_id, partner_id, warehouse_id, status, issue_date, due_date, total_amount, created_by, created_at)
                 VALUES (:invoice_number, :purchase_order_id, :partner_id, :warehouse_id, :status, :issue_date, :due_date, :total_amount, :created_by, NOW())'
            );
            $stmt->execute([
                'invoice_number' => $number,
                'purchase_order_id' => $data['purchase_order_id'] ?: null,
                'partner_id' => $data['partner_id'],
                'warehouse_id' => $data['warehouse_id'] ?: null,
                'status' => 'unpaid',
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'total_amount' => $total,
                'created_by' => $data['created_by'] ?: null,
            ]);

            $invoiceId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO incoming_invoice_items
                     (incoming_invoice_id, product_id, product_name, product_sku, unit_code, quantity, unit_price, line_total)
                 SELECT :invoice_id, p.id, COALESCE(d.name, p.sku), p.sku, u.code,
                        :quantity, :unit_price, :line_total
                 FROM products p
                 LEFT JOIN product_description d ON d.product_id = p.id
                       AND d.language_id = ' . \Cloudexus\Core\Language::defaultId() . '
                 LEFT JOIN units u ON u.id = p.unit_id
                 WHERE p.id = :product_id'
            );
            $stockStmt = $pdo->prepare(
                "INSERT INTO stock_movements (warehouse_id, product_id, type, quantity, note, created_by, created_at)
                 VALUES (:warehouse_id, :product_id, 'in', :quantity, :note, :created_by, :created_at)"
            );

            foreach ($items as $item) {
                $itemStmt->execute([
                    'invoice_id' => $invoiceId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['quantity'] * $item['unit_price'],
                ]);

                if (!empty($data['warehouse_id'])) {
                    $stockStmt->execute([
                        'warehouse_id' => $data['warehouse_id'],
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'note' => 'Beszerzés: ' . $number,
                        'created_by' => $data['created_by'] ?: null,
                        'created_at' => $data['issue_date'] . ' 09:00:00',
                    ]);
                }
            }

            if (!empty($data['purchase_order_id'])) {
                $pdo->prepare("UPDATE purchase_orders SET status = 'invoiced' WHERE id = :id")
                    ->execute(['id' => $data['purchase_order_id']]);
            }

            $pdo->commit();

            return $invoiceId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function updateStatus(int $id, string $status): void
    {
        DatabaseConnection::get()
            ->prepare('UPDATE incoming_invoices SET status = :status WHERE id = :id')
            ->execute(['id' => $id, 'status' => $status]);
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM incoming_invoices WHERE id = :id')->execute(['id' => $id]);
    }

    public function unpaidList(): array
    {
        return DatabaseConnection::get()->query(
            "SELECT i.*, p.name AS partner_name
             FROM incoming_invoices i
             JOIN partners p ON p.id = i.partner_id
             WHERE i.status = 'unpaid'
             ORDER BY i.due_date ASC"
        )->fetchAll();
    }

    public function outstandingTotal(): float
    {
        return (float) DatabaseConnection::get()
            ->query("SELECT COALESCE(SUM(total_amount), 0) FROM incoming_invoices WHERE status = 'unpaid'")
            ->fetchColumn();
    }
}
