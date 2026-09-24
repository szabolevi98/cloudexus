<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\UnitModel;

class UnitController extends BaseController
{
    private UnitModel $units;

    public function __construct()
    {
        parent::__construct();
        $this->units = new UnitModel();
        $this->activeMenu = 'units';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::CATALOG_MANAGE);

        $filters = ['q' => trim($_GET['q'] ?? '')];
        $pager = new Paginator(30);

        $this->pageTitle = $this->t('units.list_title');
        $this->render('units/list.twig', [
            'units' => $this->withTranslations($this->units->paginate($filters, $pager)),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::CATALOG_MANAGE);

        $data = $this->collectInput();
        if ($data['code'] === '' || $this->defaultText($data['name']) === '') {
            $this->flashError($this->t('units.code_name_required'));
        } elseif ($this->units->codeExists($data['code'])) {
            $this->flashError($this->t('units.code_exists'));
        } else {
            $this->units->create($data);
            $this->flashSuccess($this->t('units.created'));
        }
        $this->redirect('/units');
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::CATALOG_MANAGE);

        $data = $this->collectInput();
        if ($data['code'] === '' || $this->defaultText($data['name']) === '') {
            $this->flashError($this->t('units.code_name_required'));
        } elseif ($this->units->codeExists($data['code'], $id)) {
            $this->flashError($this->t('units.code_exists'));
        } else {
            $this->units->update($id, $data);
            $this->flashSuccess($this->t('units.updated'));
        }
        $this->redirect('/units');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::CATALOG_MANAGE);

        $this->units->delete($id);
        $this->flashSuccess($this->t('units.deleted'));
        $this->redirect('/units');
    }

    private function collectInput(): array
    {
        return [
            'code' => trim($_POST['code'] ?? ''),
            'name' => $this->localized('name'),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
    }

    /**
     * Nyelvenkénti szövegek a POST-ból: name[<nyelv id>]. Sima stringet is
     * elfogad, azt az alapnyelv sorába teszi.
     *
     * @return array<int, string>
     */
    private function localized(string $field): array
    {
        $raw = $_POST[$field] ?? [];
        if (!is_array($raw)) {
            $raw = trim((string) $raw) !== '' ? [\Cloudexus\Core\Language::defaultId() => $raw] : [];
        }

        $out = [];
        foreach ($raw as $languageId => $value) {
            $languageId = (int) $languageId;
            if ($languageId > 0) {
                $out[$languageId] = trim((string) $value);
            }
        }

        return $out;
    }

    /** A szöveg az alapnyelven — ez a kötelező kitöltés feltétele. */
    private function defaultText(array $localized): string
    {
        return $localized[\Cloudexus\Core\Language::defaultId()] ?? '';
    }

    /**
     * Minden sorhoz hozzáteszi a nyelvenkénti megnevezéseket (names[nyelv id]),
     * hogy a lista soronként minden nyelvet szerkeszthetően kiírhasson.
     */
    private function withTranslations(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['names'] = $this->units->descriptions((int) $row['id']);
        }
        unset($row);

        return $rows;
    }
}
