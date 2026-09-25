<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Import\CsvReader;
use Cloudexus\Core\Import\Import;
use Cloudexus\Core\Import\PartnerImport;
use Cloudexus\Core\Import\ProductImport;
use Cloudexus\Core\Permissions;
use Cloudexus\Core\Session;

/**
 * CSV-import termékekhez és partnerekhez: feltöltés, előnézet soronként —
 * mi lesz új, mi frissül, mi marad, mi hibás és miért —, és csak a
 * jóváhagyás után írás. A terv a munkamenetben vár a jóváhagyásra; egy
 * másik fájl feltöltése felülírja.
 */
class ImportController extends BaseController
{
    private const KINDS = [
        'products' => ['permission' => Permissions::PRODUCTS_MANAGE, 'entity' => 'product', 'menu' => 'products', 'export' => '/products/export'],
        'partners' => ['permission' => Permissions::PARTNERS_MANAGE, 'entity' => 'partner', 'menu' => 'partners', 'export' => '/partners/export'],
    ];

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function form(string $kind): void
    {
        $config = $this->kind($kind);
        Session::remove($this->sessionKey($kind));

        $this->render('import/form.twig', [
            'kind' => $kind,
            'fields' => $this->importer($kind)->fields(),
            'export_url' => $config['export'],
        ]);
    }

    public function preview(string $kind): void
    {
        $this->kind($kind);

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $this->flashError($this->t('import.no_file'));
            $this->redirect('/import/' . $kind);
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            $this->flashError($this->t('import.too_big'));
            $this->redirect('/import/' . $kind);
        }

        try {
            $csv = CsvReader::read((string) $file['tmp_name']);
        } catch (\RuntimeException $e) {
            $this->flashError($this->t($e->getMessage() === 'too_long' ? 'import.too_long' : 'import.empty', ['max' => CsvReader::MAX_ROWS]));
            $this->redirect('/import/' . $kind);
        }

        $plan = $this->importer($kind)->plan($csv['headers'], $csv['rows']);
        Session::set($this->sessionKey($kind), ['rows' => $plan['rows'], 'file' => (string) $file['name']]);

        $this->render('import/preview.twig', [
            'kind' => $kind,
            'plan' => $plan,
            'file_name' => (string) $file['name'],
            'field_labels' => array_combine($plan['columns'], array_map(fn(string $f): string => $this->t('import.field_' . $f), $plan['columns'])) ?: [],
        ]);
    }

    public function confirm(string $kind): void
    {
        $config = $this->kind($kind);

        $stored = Session::get($this->sessionKey($kind));
        Session::remove($this->sessionKey($kind));
        if (!is_array($stored) || !isset($stored['rows'])) {
            $this->flashError($this->t('import.expired'));
            $this->redirect('/import/' . $kind);
        }

        $done = $this->importer($kind)->apply($stored['rows']);
        AuditLog::record(AuditLog::IMPORT, $config['entity'], null, (string) $stored['file'], ['created' => $done['create'], 'updated' => $done['update']]);

        $this->flashSuccess($this->t('import.done', ['created' => $done['create'], 'updated' => $done['update']]));
        $this->redirect('/' . $kind);
    }

    /** @return array{permission: string, entity: string, menu: string, export: string} */
    private function kind(string $kind): array
    {
        if (!isset(self::KINDS[$kind])) {
            $this->redirect('/dashboard');
        }
        $this->requirePermission(self::KINDS[$kind]['permission']);
        $this->activeMenu = self::KINDS[$kind]['menu'];
        $this->pageTitle = $this->t('import.title_' . $kind);

        return self::KINDS[$kind];
    }

    private function importer(string $kind): Import
    {
        return $kind === 'products' ? new ProductImport() : new PartnerImport();
    }

    private function sessionKey(string $kind): string
    {
        return 'import_plan_' . $kind;
    }
}
