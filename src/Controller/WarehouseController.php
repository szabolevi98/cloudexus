<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\WarehouseModel;

class WarehouseController extends BaseController
{
    private WarehouseModel $warehouses;

    public function __construct()
    {
        parent::__construct();
        $this->warehouses = new WarehouseModel();
        $this->activeMenu = 'warehouses';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::STOCK_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'status' => $_GET['status'] ?? '',
        ];
        $pager = new Paginator(30);

        $this->pageTitle = $this->t('warehouses.list_title');
        $this->render('warehouses/list.twig', [
            'warehouses' => $this->warehouses->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::WAREHOUSES_MANAGE);

        $this->pageTitle = $this->t('warehouses.new');
        $this->render('warehouses/form.twig', ['warehouse' => null]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::WAREHOUSES_MANAGE);

        $data = $this->collectInput();

        if ($data['name'] === '') {
            $this->flashError($this->t('warehouses.name_required'));
            $this->redirect('/warehouses/create');
        }

        $this->warehouses->create($data);
        $this->flashSuccess($this->t('warehouses.created'));
        $this->redirect('/warehouses');
    }

    public function editForm(int $id): void
    {
        $this->requirePermission(Permissions::WAREHOUSES_MANAGE);

        $warehouse = $this->warehouses->findById($id);
        if (!$warehouse) {
            $this->redirect('/warehouses');
        }

        $this->pageTitle = $this->t('warehouses.edit_title');
        $this->render('warehouses/form.twig', ['warehouse' => $warehouse]);
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::WAREHOUSES_MANAGE);

        $data = $this->collectInput();

        if ($data['name'] === '') {
            $this->flashError($this->t('warehouses.name_required'));
            $this->redirect('/warehouses/' . $id . '/edit');
        }

        $this->warehouses->update($id, $data);
        $this->flashSuccess($this->t('warehouses.updated'));
        $this->redirect('/warehouses');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::WAREHOUSES_MANAGE);

        $this->warehouses->delete($id);
        $this->flashSuccess($this->t('warehouses.deleted'));
        $this->redirect('/warehouses');
    }

    private function collectInput(): array
    {
        return [
            'name' => trim($_POST['name'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
    }
}
