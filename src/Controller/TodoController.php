<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Auth;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Account\UserModel;
use Cloudexus\Model\Crm\TodoModel;

class TodoController extends BaseController
{
    private TodoModel $todos;

    public function __construct()
    {
        parent::__construct();
        $this->todos = new TodoModel();
        $this->activeMenu = 'todos';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::CRM_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'status' => $_GET['status'] ?? 'open',
            'assigned_to' => (int) ($_GET['assigned_to'] ?? 0),
        ];
        $pager = new Paginator(25);

        $this->pageTitle = $this->t('todos.list_title');
        $this->render('todos/list.twig', [
            'todos' => $this->todos->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'users' => (new UserModel())->all(),
            'today' => date('Y-m-d'),
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            $this->flashError($this->t('todos.title_required'));
            $this->redirect('/todos');
        }

        $this->todos->create([
            'title' => $title,
            'due_date' => $_POST['due_date'] ?? null,
            'partner_id' => $_POST['partner_id'] ?? null,
            'assigned_to' => $_POST['assigned_to'] ?? null,
            'created_by' => Auth::id(),
        ]);

        $this->flashSuccess($this->t('todos.created'));
        $this->redirect('/todos');
    }

    public function toggle(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);
        $this->todos->toggle($id);
        $this->redirect($_POST['return'] ?? '/todos');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);
        \Cloudexus\Core\Undo::capture($this->t('undo.todo'), '/todos', [['todos', 'id', $id]]);
        $this->todos->delete($id);
        $this->flashSuccess($this->t('todos.deleted'));
        $this->redirect('/todos');
    }
}
