<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\ParameterModel;

class ParameterController extends BaseController
{
    private ParameterModel $names;

    public function __construct()
    {
        parent::__construct();
        $this->names = new ParameterModel();
        $this->activeMenu = 'parameters';
    }

    /** Select2 AJAX endpoint (any authenticated user). */
    public function search(): void
    {
        $this->requireAuth();
        $this->json($this->names->search(trim($_GET['q'] ?? ''), (int) ($_GET['page'] ?? 1)));
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::CATALOG_MANAGE);

        $filters = ['q' => trim($_GET['q'] ?? '')];
        $pager = new Paginator(30);

        $this->pageTitle = $this->t('parameters.list_title');
        $this->render('parameters/list.twig', [
            'names' => $this->withTranslations($this->names->paginate($filters, $pager)),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::CATALOG_MANAGE);

        $name = $this->localized('name');
        if ($this->defaultText($name) === '') {
            $this->flashError($this->t('parameters.name_required'));
        } elseif ($this->names->exists($this->defaultText($name))) {
            $this->flashError($this->t('parameters.name_exists'));
        } else {
            $this->names->create($name);
            $this->flashSuccess($this->t('parameters.created'));
        }
        $this->redirect('/parameters');
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::CATALOG_MANAGE);

        $name = $this->localized('name');
        if ($this->defaultText($name) === '') {
            $this->flashError($this->t('parameters.name_required'));
        } elseif ($this->names->exists($this->defaultText($name), $id)) {
            $this->flashError($this->t('parameters.name_exists'));
        } else {
            $this->names->update($id, $name);
            $this->flashSuccess($this->t('parameters.updated'));
        }
        $this->redirect('/parameters');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::CATALOG_MANAGE);

        $this->names->delete($id);
        $this->flashSuccess($this->t('parameters.deleted'));
        $this->redirect('/parameters');
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
            $row['names'] = $this->names->descriptions((int) $row['id']);
        }
        unset($row);

        return $rows;
    }
}
