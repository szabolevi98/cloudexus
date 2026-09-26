<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Acl;
use Cloudexus\Core\CsvExporter;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Finance\PaymentModel;

/**
 * Nyitott tételek korosítva: a vevők tartozásai (kintlévőség) és a mi
 * tartozásaink a szállítóknak, partnerenként, a lejárat óta eltelt napok
 * szerinti kosarakban. Egy korábbi napra is lekérdezhető.
 */
class ReportController extends BaseController
{
    private const BUCKETS = ['current_amount', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    public function __construct()
    {
        parent::__construct();
        $this->activeMenu = 'aging';
    }

    public function aging(): void
    {
        [$type, $asOf] = $this->params();

        $this->pageTitle = $this->t('reports.aging_title');
        $this->render('reports/aging.twig', [
            'type' => $type,
            'as_of' => $asOf,
            'report' => (new PaymentModel())->aging($type === 'payables' ? PaymentModel::INCOMING : PaymentModel::INVOICE, $asOf),
            'buckets' => self::BUCKETS,
            'can_receivables' => Acl::can(Permissions::INVOICES_VIEW),
            'can_payables' => Acl::can(Permissions::PURCHASING_VIEW),
        ]);
    }

    /**
     * A CRM riport: folyamat és előrejelzés, lezárt üzletek, ajánlatokból
     * lett rendelések, tevékenységek, értékesítőnként is — egy időszakra.
     */
    public function crm(): void
    {
        $this->requirePermission(Permissions::CRM_VIEW);

        [$period, $from, $to] = self::period((string) ($_GET['period'] ?? 'month'), (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
        $report = new \Cloudexus\Model\Crm\CrmReportModel();

        $this->activeMenu = 'crm_report';
        $this->pageTitle = $this->t('reports.crm.title');
        $this->render('reports/crm.twig', [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'pipeline' => $report->pipeline(),
            'forecast' => $report->forecast(),
            'closed' => $report->closed($from, $to),
            'lost_reasons' => $report->lostReasons($from, $to),
            'quotes' => \Cloudexus\Core\Acl::can(Permissions::ORDERS_VIEW) ? $report->quotes($from, $to) : null,
            'activities' => $report->activities($from, $to),
            'people' => $report->bySalesperson($from, $to),
        ]);
    }

    /**
     * Az időszak: előre adott (e hónap, az elmúlt 30 nap, e negyedév, ez az
     * év) vagy két dátum között. Egy rossz vagy fordított dátumpár e hónap lesz.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function period(string $period, string $from = '', string $to = '', ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $date = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

        return match (true) {
            $period === 'custom' && $date($from) && $date($to) && $from <= $to => ['custom', $from, $to],
            $period === 'last30' => ['last30', date('Y-m-d', strtotime("$today -29 days")), $today],
            $period === 'quarter' => ['quarter', date('Y-', strtotime($today)) . sprintf('%02d', intdiv((int) date('n', strtotime($today)) - 1, 3) * 3 + 1) . '-01', $today],
            $period === 'year' => ['year', date('Y-01-01', strtotime($today)), $today],
            default => ['month', date('Y-m-01', strtotime($today)), $today],
        };
    }

    /** Az alvó ügyfelek: régóta nem vásároltak — egy kattintással hívás-teendő nekik. */
    public function dormant(): void
    {
        $this->requirePermission(Permissions::CRM_VIEW);

        $days = (int) ($_GET['days'] ?? 90);
        $days = in_array($days, \Cloudexus\Model\Crm\DormantCustomerModel::DAYS, true) ? $days : 90;
        $tagId = (int) ($_GET['tag_id'] ?? 0) ?: null;
        $tags = new \Cloudexus\Model\Crm\TagModel();
        $rows = (new \Cloudexus\Model\Crm\DormantCustomerModel())->find($days, $tagId);
        $allTags = $tags->all();

        $this->activeMenu = 'dormant';
        $this->pageTitle = $this->t('reports.dormant.title');
        $this->render('reports/dormant.twig', [
            'rows' => $rows,
            'days' => $days,
            'day_options' => \Cloudexus\Model\Crm\DormantCustomerModel::DAYS,
            'tag_id' => $tagId,
            'all_tags' => $allTags,
            'tag_ids' => array_column($allTags, 'id', 'name'),
            'partner_tags' => $tags->forPartners(array_column($rows, 'id')),
            'revenue' => array_sum(array_column($rows, 'revenue')),
            'today' => date('Y-m-d'),
        ]);
    }

    public function agingExport(): void
    {
        [$type, $asOf] = $this->params();
        $report = (new PaymentModel())->aging($type === 'payables' ? PaymentModel::INCOMING : PaymentModel::INVOICE, $asOf);

        $headers = [$this->t('common.partner'), $this->t('reports.documents')];
        foreach (self::BUCKETS as $bucket) {
            $headers[] = $this->t('reports.buckets.' . $bucket);
        }
        $headers[] = $this->t('reports.total_open');

        $rows = [];
        foreach ($report['rows'] as $row) {
            $line = [$row['partner_name'], (int) $row['document_count']];
            foreach (self::BUCKETS as $bucket) {
                $line[] = (float) $row[$bucket];
            }
            $line[] = (float) $row['total_open'];
            $rows[] = $line;
        }

        // A letöltés dátumát a CsvExporter teszi a névre; egy korábbi napi
        // állapotnál az is belekerül, melyik napé.
        $name = $type === 'payables' ? 'szallitoi-tartozasok' : 'kintlevosegek';
        CsvExporter::download($asOf === date('Y-m-d') ? $name : $name . '-allapot-' . $asOf, $headers, $rows);
    }

    /**
     * A jelentés fajtája és napja. A kintlévőséghez a számlák, a tartozásokhoz
     * a beszerzés láthatósága kell; aki csak az egyiket látja, azt kapja.
     *
     * @return array{0: string, 1: string}
     */
    private function params(): array
    {
        $this->requireAnyPermission(Permissions::INVOICES_VIEW, Permissions::PURCHASING_VIEW);

        $type = ($_GET['type'] ?? '') === 'payables' ? 'payables' : 'receivables';
        if ($type === 'receivables' && !Acl::can(Permissions::INVOICES_VIEW)) {
            $type = 'payables';
        }
        $this->requirePermission($type === 'payables' ? Permissions::PURCHASING_VIEW : Permissions::INVOICES_VIEW);

        $asOf = (string) ($_GET['as_of'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $asOf, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $asOf > date('Y-m-d')) {
            $asOf = date('Y-m-d');
        }

        return [$type, $asOf];
    }
}
