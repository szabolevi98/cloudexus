<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\LocationModel;
use Cloudexus\Model\Core\WarehouseModel;

class LocationController extends BaseController
{
    private LocationModel $locations;
    private WarehouseModel $warehouses;

    public function __construct()
    {
        parent::__construct();
        $this->locations = new LocationModel();
        $this->warehouses = new WarehouseModel();
        $this->activeMenu = 'locations';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::STOCK_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'warehouse_id' => (int) ($_GET['warehouse_id'] ?? 0),
            'status' => $_GET['status'] ?? '',
        ];
        $pager = new Paginator(30);

        $this->pageTitle = $this->t('locations.list_title');
        $this->render('locations/list.twig', [
            'locations' => $this->locations->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'warehouses' => $this->warehouses->all(),
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::WAREHOUSES_MANAGE);

        $this->pageTitle = $this->t('locations.new');
        $this->render('locations/form.twig', [
            'location' => null,
            'warehouses' => $this->warehouses->activeList(),
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::WAREHOUSES_MANAGE);

        $data = $this->collectInput();
        if ($error = $this->validate($data, null)) {
            $this->flashError($error);
            $this->redirect('/locations/create');
        }

        $this->locations->create($data);
        $this->flashSuccess($this->t('locations.created'));
        $this->redirect('/locations');
    }

    public function editForm(int $id): void
    {
        $this->requirePermission(Permissions::WAREHOUSES_MANAGE);

        $location = $this->locations->findById($id);
        if (!$location) {
            $this->redirect('/locations');
        }

        $this->pageTitle = $this->t('locations.edit_title');
        $this->render('locations/form.twig', [
            'location' => $location,
            'warehouses' => $this->warehouses->activeList(),
        ]);
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::WAREHOUSES_MANAGE);

        $data = $this->collectInput();
        if ($error = $this->validate($data, $id)) {
            $this->flashError($error);
            $this->redirect('/locations/' . $id . '/edit');
        }

        $this->locations->update($id, $data);
        $this->flashSuccess($this->t('locations.updated'));
        $this->redirect('/locations');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::WAREHOUSES_MANAGE);

        $this->locations->delete($id);
        $this->flashSuccess($this->t('locations.deleted'));
        $this->redirect('/locations');
    }

    private function collectInput(): array
    {
        return [
            'warehouse_id' => (int) ($_POST['warehouse_id'] ?? 0),
            'code' => trim($_POST['code'] ?? ''),
            'name' => trim($_POST['name'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
    }

    private function validate(array $data, ?int $excludeId): ?string
    {
        if ($data['warehouse_id'] <= 0 || $data['code'] === '') {
            return $this->t('locations.required');
        }
        if ($this->locations->codeExists($data['warehouse_id'], $data['code'], $excludeId)) {
            return $this->t('locations.code_taken');
        }
        return null;
    }
}
