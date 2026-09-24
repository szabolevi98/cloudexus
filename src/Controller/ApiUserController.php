<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Account\ApiRequestLogModel;
use Cloudexus\Model\Account\ApiUserModel;

class ApiUserController extends BaseController
{
    private ApiUserModel $apiUsers;
    private ApiRequestLogModel $apiLogs;

    public function __construct()
    {
        parent::__construct();
        $this->apiUsers = new ApiUserModel();
        $this->apiLogs = new ApiRequestLogModel();
        $this->activeMenu = 'api-users';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $this->pageTitle = $this->t('api_users.list_title');
        $this->render('api-users/list.twig', ['api_users' => $this->apiUsers->all()]);
    }

    public function logs(): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $filters = [
            'api_user_id' => (int) ($_GET['api_user_id'] ?? 0),
            'outcome' => $_GET['outcome'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $pager = new Paginator(50);

        $this->activeMenu = 'api-logs';
        $this->pageTitle = $this->t('api_logs.list_title');
        $this->render('api-users/logs.twig', [
            'logs' => $this->apiLogs->paginate($filters, $pager),
            'summary' => $this->apiLogs->summary($filters),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'api_users' => $this->apiUsers->all(),
            'retention_days' => (int) \Cloudexus\Core\Config::get('api.log_retention_days', 14),
        ]);
    }

    public function docs(): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $this->activeMenu = 'api-docs';
        $this->pageTitle = $this->t('api_users.docs_title');
        $this->render('api-docs.twig');
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $this->flashError($this->t('api_users.name_required'));
        } else {
            $apiUserId = $this->apiUsers->create($name);
            AuditLog::record(AuditLog::CREATE, 'api_user', is_int($apiUserId) ? $apiUserId : null, $name);
            $this->flashSuccess($this->t('api_users.created'));
        }
        $this->redirect('/api-users');
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $this->flashError($this->t('api_users.name_required'));
        } else {
            $this->apiUsers->rename($id, $name);
            $this->flashSuccess($this->t('api_users.updated'));
        }
        $this->redirect('/api-users');
    }

    public function toggle(int $id): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $user = $this->apiUsers->findById($id);
        if ($user) {
            $this->apiUsers->setActive($id, !$user['is_active']);
            $this->flashSuccess($user['is_active'] ? $this->t('api_users.deactivated') : $this->t('api_users.activated'));
        }
        $this->redirect('/api-users');
    }

    public function regenerate(int $id): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        if ($apiUser = $this->apiUsers->findById($id)) {
            $this->apiUsers->regenerateToken($id);
            AuditLog::record(AuditLog::UPDATE, 'api_user', $id, $apiUser['name'], ['token' => true]);
            $this->flashSuccess($this->t('api_users.token_regenerated'));
        }
        $this->redirect('/api-users');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $apiUser = $this->apiUsers->findById($id);
        $this->apiUsers->delete($id);
        AuditLog::record(AuditLog::DELETE, 'api_user', $id, $apiUser['name'] ?? null);
        $this->flashSuccess($this->t('api_users.deleted'));
        $this->redirect('/api-users');
    }
}
