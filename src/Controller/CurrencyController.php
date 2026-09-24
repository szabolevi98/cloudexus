<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Currency;
use Cloudexus\Core\CurrencyRateSync;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\CurrencyModel;
use Cloudexus\Model\Core\SettingModel;

class CurrencyController extends BaseController
{
    private CurrencyModel $currencies;
    private SettingModel $settings;

    public function __construct()
    {
        parent::__construct();
        $this->currencies = new CurrencyModel();
        $this->settings = new SettingModel();
        $this->activeMenu = 'currencies';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $filters = ['q' => trim($_GET['q'] ?? '')];
        $pager = new Paginator(30);

        $rows = $this->currencies->paginate($filters, $pager);
        $primaryCode = Currency::code();
        foreach ($rows as &$row) {
            $row['is_primary'] = strtoupper((string) $row['code']) === $primaryCode;
        }
        unset($row);

        $this->pageTitle = $this->t('currencies.list_title');
        $this->render('currencies/list.twig', [
            'currencies' => $rows,
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'primary_code' => $primaryCode,
            'primary_symbol' => Currency::symbol(),
            'last_sync' => $this->settings->get('currency.mnb_synced_at'),
            'rate_date' => $this->settings->get('currency.mnb_rate_date'),
            'cron_command' => $this->cronCommand(),
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $data = $this->collectInput();
        $error = $this->validate($data, null);

        if ($error !== null) {
            $this->flashError($error);
        } else {
            $this->currencies->create($data);
            $this->flashSuccess($this->t('currencies.created'));
        }

        $this->redirect('/currencies');
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $currency = $this->currencies->findById($id);
        if (!$currency) {
            $this->redirect('/currencies');
        }

        $data = $this->collectInput();

        // Az elsődleges pénznem váltószáma definíció szerint 1, a mezőt a
        // felület le is tiltja — de a POST-ban se lehessen elállítani.
        if (strtoupper((string) $currency['code']) === Currency::code()) {
            $data['value'] = 1.0;
        }

        $error = $this->validate($data, $id);

        if ($error !== null) {
            $this->flashError($error);
        } else {
            $this->currencies->update($id, $data);
            $this->syncPrimarySetting($currency, $data['code']);
            $this->flashSuccess($this->t('currencies.updated'));
        }

        $this->redirect('/currencies');
    }

    /** Kijelöli az elsődleges pénznemet, és 1-re állítja a váltószámát. */
    public function setPrimary(int $id): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $currency = $this->currencies->findById($id);
        if (!$currency) {
            $this->redirect('/currencies');
        }

        $code = strtoupper((string) $currency['code']);
        $this->settings->set('currency.primary', $code);
        $this->currencies->updateValueByCode($code, 1.0);
        Currency::reset();

        $this->flashSuccess($this->t('currencies.primary_changed', ['code' => $code]));
        $this->redirect('/currencies');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $currency = $this->currencies->findById($id);
        if (!$currency) {
            $this->redirect('/currencies');
        }

        if (strtoupper((string) $currency['code']) === Currency::code()) {
            $this->flashError($this->t('currencies.cannot_delete_primary'));
            $this->redirect('/currencies');
        }

        $this->currencies->delete($id);
        $this->flashSuccess($this->t('currencies.deleted'));
        $this->redirect('/currencies');
    }

    /** "MNB közép árfolyam lekérése" gomb. */
    public function syncRates(): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $result = CurrencyRateSync::run();

        if (!$result['ok']) {
            $this->flashError($result['error'] === 'primary_not_quoted'
                ? $this->t('currencies.sync_primary_not_quoted', ['code' => Currency::code()])
                : $this->t('currencies.sync_failed'));
            $this->redirect('/currencies');
        }

        $updated = $result['updated'] ?? [];
        $missing = $result['missing'] ?? [];

        if (!$updated && !$missing) {
            $this->flashSuccess($this->t('currencies.sync_nothing'));
        } elseif ($missing) {
            $this->flashSuccess($this->t('currencies.sync_ok_missing', [
                'count' => count($updated),
                'missing' => implode(', ', $missing),
            ]));
        } else {
            $this->flashSuccess($this->t('currencies.sync_ok', [
                'count' => count($updated),
                'date' => $result['date'] ?: '—',
            ]));
        }

        $this->redirect('/currencies');
    }

    private function collectInput(): array
    {
        return [
            'title' => trim($_POST['title'] ?? ''),
            'code' => strtoupper(trim($_POST['code'] ?? '')),
            'symbol' => trim($_POST['symbol'] ?? ''),
            'value' => (float) str_replace(',', '.', (string) ($_POST['value'] ?? '0')),
        ];
    }

    /** Az első hibát adja vissza, vagy null-t ha érvényes. */
    private function validate(array $data, ?int $excludeId): ?string
    {
        if ($data['title'] === '') {
            return $this->t('currencies.title_required');
        }
        if (!preg_match('/^[A-Z]{3}$/', $data['code'])) {
            return $this->t('currencies.code_invalid');
        }
        if ($this->currencies->codeExists($data['code'], $excludeId)) {
            return $this->t('currencies.code_exists');
        }
        if ($data['value'] <= 0) {
            return $this->t('currencies.value_invalid');
        }

        return null;
    }

    /** Ha az elsődleges pénznem kódját írták át, a beállítás is kövesse. */
    private function syncPrimarySetting(array $currency, string $newCode): void
    {
        $oldCode = strtoupper((string) $currency['code']);
        if ($oldCode === Currency::code() && $oldCode !== $newCode) {
            $this->settings->set('currency.primary', $newCode);
            Currency::reset();
        }
    }

    /** Bemásolható crontab sor a tényleges telepítési útvonallal. */
    private function cronCommand(): string
    {
        $script = dirname(__DIR__, 2) . '/bin/sync_currency_rates.php';
        return '10 7 * * 1-5 php ' . str_replace('\\', '/', $script) . ' --quiet';
    }
}
