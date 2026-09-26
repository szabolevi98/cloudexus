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
