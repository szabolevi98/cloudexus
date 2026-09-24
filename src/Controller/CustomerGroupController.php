<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\CustomerGroupModel;

class CustomerGroupController extends BaseController
{
    private CustomerGroupModel $groups;

    public function __construct()
    {
        parent::__construct();
        $this->groups = new CustomerGroupModel();
        $this->activeMenu = 'customer-groups';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::PARTNERS_VIEW);

        $filters = ['q' => trim($_GET['q'] ?? '')];
        $pager = new Paginator(30);

        $this->pageTitle = $this->t('customer_groups.list_title');
        $this->render('customer-groups/list.twig', [
            'groups' => $this->groups->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::CUSTOMER_GROUPS_MANAGE);

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            $this->flashError($this->t('customer_groups.name_required'));
        } elseif ($this->groups->exists($name)) {
            $this->flashError($this->t('customer_groups.name_exists'));
        } else {
            $this->groups->create(['name' => $name, 'description' => $description]);
            $this->flashSuccess($this->t('customer_groups.created'));
        }

        $this->redirect('/customer-groups');
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::CUSTOMER_GROUPS_MANAGE);

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            $this->flashError($this->t('customer_groups.name_required'));
        } elseif ($this->groups->exists($name, $id)) {
            $this->flashError($this->t('customer_groups.name_exists'));
        } else {
            $this->groups->update($id, ['name' => $name, 'description' => $description]);
            $this->flashSuccess($this->t('customer_groups.updated'));
        }

        $this->redirect('/customer-groups');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::CUSTOMER_GROUPS_MANAGE);

        $this->groups->delete($id);
        $this->flashSuccess($this->t('customer_groups.deleted'));
        $this->redirect('/customer-groups');
    }
}
