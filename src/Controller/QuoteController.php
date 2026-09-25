<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Lang;
use Cloudexus\Core\Mailer;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Pdf;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Core\SettingModel;
use Cloudexus\Model\Sales\QuoteModel;

/**
 * Árajánlatok: összeállítás a rendelés tételsoraival, PDF és küldés
 * e-mailben, elfogadás vagy elutasítás (az okkal), és egy kattintással
 * rendelés belőle. A rendelések jogai vonatkoznak rá: aki rendelést
 * láthat/kezelhet, ajánlatot is.
 */
class QuoteController extends BaseController
{
    private QuoteModel $quotes;

    public function __construct()
    {
        parent::__construct();
        $this->quotes = new QuoteModel();
        $this->activeMenu = 'quotes';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::ORDERS_VIEW);

        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'partner_id' => (int) ($_GET['partner_id'] ?? 0),
            'status' => (string) ($_GET['status'] ?? ''),
            'date_from' => (string) ($_GET['date_from'] ?? ''),
            'date_to' => (string) ($_GET['date_to'] ?? ''),
        ];
        $pager = new Paginator(25);

        $this->pageTitle = $this->t('quotes.list_title');
        $this->render('quotes/list.twig', [
            'quotes' => $this->quotes->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'partner_option' => $filters['partner_id'] ? (new PartnerModel())->labelsForIds([$filters['partner_id']]) : [],
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $partnerId = (int) ($_GET['partner_id'] ?? 0);
        $this->pageTitle = $this->t('quotes.new');
        $this->render('quotes/form.twig', [
            'quote' => null,
            'number' => \Cloudexus\Core\DocumentNumber::preview('quote'),
            'valid_until' => date('Y-m-d', strtotime('+' . QuoteModel::VALID_DAYS . ' days')),
            'partner_option' => $partnerId ? (new PartnerModel())->labelsForIds([$partnerId]) : [],
            'prefill' => [],
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        [$data, $items] = $this->input('/quotes/create');
        $id = $this->quotes->create($data + ['created_by' => Auth::id()], $items);
        $quote = $this->quotes->findById($id);
        AuditLog::record(AuditLog::CREATE, 'quote', $id, $quote['quote_number'] ?? null, ['total' => \Cloudexus\Core\Currency::format((float) ($quote['total_amount'] ?? 0))]);

        $this->flashSuccess($this->t('quotes.created'));
        $this->redirect('/quotes/' . $id);
    }

    public function show(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_VIEW);

        $quote = $this->quoteOr404($id);
        $this->remember('quote', $id);
        $this->pageTitle = $this->t('quotes.title_prefix') . ': ' . $quote['quote_number'];
        $this->render('quotes/show.twig', [
            'quote' => $quote,
            'mail_enabled' => Mailer::isConfigured(),
            'email_to' => $quote['emailed_to'] ?: (new \Cloudexus\Model\Core\PartnerContactModel())->recipientFor((int) $quote['partner_id'], false, $quote['partner_email']),
            'recipients' => array_values(array_filter((new \Cloudexus\Model\Core\PartnerContactModel())->forPartner((int) $quote['partner_id']), static fn(array $c): bool => (string) $c['email'] !== '')),
            'email_message' => $this->t('quotes.email_default_message', [
                'number' => $quote['quote_number'],
                'valid_until' => $quote['valid_until'],
                'company' => (string) ((new SettingModel())->company()['name'] ?? ''),
            ]),
        ]);
    }

    public function editForm(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $quote = $this->quoteOr404($id);
        if (!in_array($quote['status'], ['draft', 'sent'], true)) {
            $this->flashError($this->t('quotes.not_editable'));
            $this->redirect('/quotes/' . $id);
        }

        $this->pageTitle = $this->t('quotes.edit') . ': ' . $quote['quote_number'];
        $this->render('quotes/form.twig', [
            'quote' => $quote,
            'number' => $quote['quote_number'],
            'valid_until' => $quote['valid_until'],
            'partner_option' => (new PartnerModel())->labelsForIds([(int) $quote['partner_id']]),
            'prefill' => array_map(static fn(array $line): array => [
                'product_id' => (int) $line['product_id'],
                'text' => $line['product_sku'] . ' — ' . $line['product_name'],
                'quantity' => (float) $line['quantity'],
                'unit_price' => (float) $line['unit_price'],
            ], $quote['items']),
        ]);
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $quote = $this->quoteOr404($id);
        [$data, $items] = $this->input('/quotes/' . $id . '/edit');
        if (!$this->quotes->update($id, $data, $items)) {
            $this->flashError($this->t('quotes.not_editable'));
            $this->redirect('/quotes/' . $id);
        }
        AuditLog::record(AuditLog::UPDATE, 'quote', $id, (string) $quote['quote_number']);

        $this->flashSuccess($this->t('quotes.updated'));
        $this->redirect('/quotes/' . $id);
    }

    public function pdf(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_VIEW);

        $quote = $this->quoteOr404($id);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . self::pdfName($quote) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $this->renderPdf($quote);
        exit;
    }

    public function email(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $quote = $this->quoteOr404($id);
        $to = trim((string) ($_POST['to'] ?? ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->flashError($this->t('invoices.email_invalid'));
            $this->redirect('/quotes/' . $id);
        }

        $queued = Mailer::send(
            $to,
            (string) $quote['partner_name'],
            $this->t('quotes.email_subject', ['number' => $quote['quote_number']]),
            trim((string) ($_POST['message'] ?? '')),
            'quote',
            self::pdfName($quote),
            $this->renderPdf($quote)
        );
        if (!$queued) {
            $this->flashError($this->t(Mailer::isConfigured() ? 'invoices.email_unreachable' : 'email.off', ['address' => $to]));
            $this->redirect('/quotes/' . $id);
        }

        $this->quotes->markSent($id, $to);
        AuditLog::record(AuditLog::EMAILED, 'quote', $id, (string) $quote['quote_number'], ['to' => $to]);
        $this->flashSuccess($this->t('invoices.email_queued', ['address' => $to]));
        $this->redirect('/quotes/' . $id);
    }

    /** Elküldöttnek jelölés e-mail nélkül — kinyomtatták, személyesen adták át. */
    public function markSent(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $quote = $this->quoteOr404($id);
        $this->quotes->markSent($id, null);
        AuditLog::record(AuditLog::UPDATE, 'quote', $id, (string) $quote['quote_number'], ['status' => 'sent']);
        $this->redirect('/quotes/' . $id);
    }

    public function accept(int $id): void
    {
        $this->decide($id, true);
    }

    public function reject(int $id): void
    {
        $this->decide($id, false);
    }

    public function toOrder(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $quote = $this->quoteOr404($id);
        try {
            $orderId = $this->quotes->toOrder($id, Auth::id());
        } catch (\DomainException) {
            $this->flashError($this->t('quotes.not_convertible'));
            $this->redirect('/quotes/' . $id);
        }
        AuditLog::record(AuditLog::UPDATE, 'quote', $id, (string) $quote['quote_number'], ['status' => 'ordered']);

        $this->flashSuccess($this->t('quotes.ordered'));
        $this->redirect('/orders/' . $orderId);
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $quote = $this->quoteOr404($id);
        if (!$this->quotes->delete($id)) {
            $this->flashError($this->t('quotes.not_deletable'));
            $this->redirect('/quotes/' . $id);
        }
        AuditLog::record(AuditLog::DELETE, 'quote', $id, (string) $quote['quote_number']);

        $this->flashSuccess($this->t('quotes.deleted'));
        $this->redirect('/quotes');
    }

    private function decide(int $id, bool $accepted): void
    {
        $this->requirePermission(Permissions::ORDERS_MANAGE);

        $quote = $this->quoteOr404($id);
        $reason = trim(mb_substr((string) ($_POST['reason'] ?? ''), 0, 255));
        if ($this->quotes->decide($id, $accepted, $reason !== '' ? $reason : null)) {
            AuditLog::record(AuditLog::UPDATE, 'quote', $id, (string) $quote['quote_number'], ['status' => $accepted ? 'accepted' : 'rejected']);
            $this->flashSuccess($this->t($accepted ? 'quotes.accepted' : 'quotes.rejected'));
        }
        $this->redirect('/quotes/' . $id);
    }

    /** @return array{0: array{partner_id: int, quote_date: string, valid_until: string, shipping_cost: float, payment_cost: float, note: string}, 1: list<array{product_id: int, quantity: float, unit_price: float}>} */
    private function input(string $back): array
    {
        $items = [];
        foreach ((array) ($_POST['product_id'] ?? []) as $index => $productId) {
            $quantity = (float) str_replace(',', '.', (string) ($_POST['quantity'][$index] ?? '0'));
            if ((int) $productId > 0 && $quantity > 0) {
                $items[] = [
                    'product_id' => (int) $productId,
                    'quantity' => $quantity,
                    'unit_price' => (float) str_replace(',', '.', (string) ($_POST['unit_price'][$index] ?? '0')),
                ];
            }
        }

        $date = (string) ($_POST['quote_date'] ?? '') ?: date('Y-m-d');
        $valid = (string) ($_POST['valid_until'] ?? '') ?: date('Y-m-d', strtotime($date . ' +' . QuoteModel::VALID_DAYS . ' days'));
        if (empty($_POST['partner_id']) || $items === []) {
            $this->flashError($this->t('quotes.required'));
            $this->redirect($back);
        }
        if ($valid < $date) {
            $this->flashError($this->t('quotes.valid_before_date'));
            $this->redirect($back);
        }

        return [[
            'partner_id' => (int) $_POST['partner_id'],
            'quote_date' => $date,
            'valid_until' => $valid,
            'shipping_cost' => (float) str_replace(',', '.', (string) ($_POST['shipping_cost'] ?? '0')),
            'payment_cost' => (float) str_replace(',', '.', (string) ($_POST['payment_cost'] ?? '0')),
            'note' => trim(mb_substr((string) ($_POST['note'] ?? ''), 0, 4000)),
        ], $items];
    }

    private function renderPdf(array $quote): string
    {
        $html = $this->twig->render('quotes/pdf.twig', [
            'quote' => $quote,
            'company' => (new SettingModel())->company(),
            'current_locale' => Lang::locale(),
        ]);

        return Pdf::render($html, $this->t('invoices.pdf_page'));
    }

    private static function pdfName(array $quote): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $quote['quote_number']) . '.pdf';
    }

    /** @return array<string, mixed> */
    private function quoteOr404(int $id): array
    {
        $quote = $this->quotes->findById($id);
        if ($quote === null) {
            $this->redirect('/quotes');
        }

        return $quote;
    }
}
