<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Language;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\LanguageModel;
use Cloudexus\Model\Core\SettingModel;

class LanguageController extends BaseController
{
    private LanguageModel $languages;
    private SettingModel $settings;

    public function __construct()
    {
        parent::__construct();
        $this->languages = new LanguageModel();
        $this->settings = new SettingModel();
        $this->activeMenu = 'languages';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $filters = ['q' => trim($_GET['q'] ?? '')];
        $pager = new Paginator(30);

        $rows = $this->languages->paginate($filters, $pager);
        $defaultCode = Language::defaultCode();
        foreach ($rows as &$row) {
            $row['is_default'] = $row['code'] === $defaultCode;
            $row['translation_count'] = $this->languages->translationCount((int) $row['id']);
        }
        unset($row);

        $this->pageTitle = $this->t('languages.list_title');
        $this->render('languages/list.twig', [
            'rows' => $rows,
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'default_code' => $defaultCode,
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
            $this->languages->create($data);
            Language::reset();
            $this->flashSuccess($this->t('languages.created'));
        }

        $this->redirect('/languages');
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $language = $this->languages->findById($id);
        if (!$language) {
            $this->redirect('/languages');
        }

        $data = $this->collectInput();

        // Az alapnyelv nem kapcsolható ki, különben nem lenne mire visszaesni.
        if ($language['code'] === Language::defaultCode() && !$data['is_active']) {
            $this->flashError($this->t('languages.cannot_deactivate_default'));
            $this->redirect('/languages');
        }

        $error = $this->validate($data, $id);

        if ($error !== null) {
            $this->flashError($error);
        } else {
            $this->languages->update($id, $data);
            // Ha az alapnyelv kódját írták át, a beállítás is kövesse.
            if ($language['code'] === Language::defaultCode() && $language['code'] !== $data['code']) {
                $this->settings->set('language.default', $data['code']);
            }
            Language::reset();
            $this->flashSuccess($this->t('languages.updated'));
        }

        $this->redirect('/languages');
    }

    public function setDefault(int $id): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $language = $this->languages->findById($id);
        if (!$language) {
            $this->redirect('/languages');
        }

        // Az alapnyelvnek aktívnak kell lennie.
        if (!$language['is_active']) {
            $this->languages->update($id, [
                'name' => $language['name'],
                'code' => $language['code'],
                'sort_order' => $language['sort_order'],
                'is_active' => 1,
            ]);
        }

        $this->settings->set('language.default', $language['code']);
        Language::reset();

        $this->flashSuccess($this->t('languages.default_changed', ['name' => $language['name']]));
        $this->redirect('/languages');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $language = $this->languages->findById($id);
        if (!$language) {
            $this->redirect('/languages');
        }

        if ($language['code'] === Language::defaultCode()) {
            $this->flashError($this->t('languages.cannot_delete_default'));
            $this->redirect('/languages');
        }

        // A fordítás-sorokat a külső kulcsok ON DELETE CASCADE viszik magukkal.
        $this->languages->delete($id);
        Language::reset();

        $this->flashSuccess($this->t('languages.deleted'));
        $this->redirect('/languages');
    }

    private function collectInput(): array
    {
        return [
            'name' => trim($_POST['name'] ?? ''),
            'code' => strtolower(trim($_POST['code'] ?? '')),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
    }

    /** Az első hibát adja vissza, vagy null-t ha érvényes. */
    private function validate(array $data, ?int $excludeId): ?string
    {
        if ($data['name'] === '') {
            return $this->t('languages.name_required');
        }
        if (!preg_match('/^[a-z]{2}(-[a-z]{2,3})?$/', $data['code'])) {
            return $this->t('languages.code_invalid');
        }
        if ($this->languages->codeExists($data['code'], $excludeId)) {
            return $this->t('languages.code_exists');
        }

        return null;
    }
}
