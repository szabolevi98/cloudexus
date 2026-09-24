<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Permissions;
use Cloudexus\Core\RoleCode;
use Cloudexus\Model\Account\RoleModel;
use Cloudexus\Model\Account\UserModel;

class UserController extends BaseController
{
    private UserModel $users;

    public function __construct()
    {
        parent::__construct();
        $this->users = new UserModel();
        $this->activeMenu = 'users';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::USERS_MANAGE);

        $filters = ['q' => trim($_GET['q'] ?? '')];
        $pager = new \Cloudexus\Core\Paginator(25);

        $this->pageTitle = $this->t('users.list_title');
        $this->render('users/list.twig', [
            'users' => $this->users->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::USERS_MANAGE);

        $this->pageTitle = $this->t('users.new');
        $this->render('users/form.twig', ['user' => null, 'roles' => $this->assignableRoles()]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::USERS_MANAGE);

        $data = $this->collectInput();
        $errors = $this->validate($data, null);

        if ($errors) {
            $this->flashError(implode(' ', $errors));
            $this->redirect('/users/create');
        }

        $id = $this->users->create($data);
        AuditLog::record(AuditLog::CREATE, 'user', $id, $data['username'], ['role' => $this->roleName((int) $data['role_id'])]);
        $this->flashSuccess($this->t('users.created'));
        $this->redirect('/users');
    }

    public function editForm(int $id): void
    {
        $this->requirePermission(Permissions::USERS_MANAGE);

        $user = $this->users->findById($id);
        if (!$user) {
            $this->redirect('/users');
        }

        $this->pageTitle = $this->t('users.edit');
        $this->render('users/form.twig', ['user' => $user, 'roles' => $this->assignableRoles()]);
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::USERS_MANAGE);

        $data = $this->collectInput();
        $errors = $this->validate($data, $id);

        if ($errors) {
            $this->flashError(implode(' ', $errors));
            $this->redirect('/users/' . $id . '/edit');
        }

        $before = $this->users->findById($id);

        // Az utolsó aktív szuper admin nem veszítheti el a szerepkörét, és
        // nem tiltható le: a rendszert valakinek kezelnie kell.
        if ($this->wouldOrphanSuperAdmin($id, (int) $data['role_id'], (int) $data['is_active'])) {
            $this->flashError($this->t('users.last_super_admin'));
            $this->redirect('/users/' . $id . '/edit');
        }

        $this->users->update($id, $data);

        $changes = [];
        if ($before && (int) $before['role_id'] !== (int) $data['role_id']) {
            $changes['role'] = [$this->roleName((int) $before['role_id']), $this->roleName((int) $data['role_id'])];
        }
        if ($before && (int) $before['is_active'] !== (int) $data['is_active']) {
            $changes['active'] = [(bool) $before['is_active'], (bool) $data['is_active']];
        }
        if ($data['password'] !== '') {
            $changes['password'] = true;
        }
        AuditLog::record(AuditLog::UPDATE, 'user', $id, $data['username'], $changes ?: null);
        $this->flashSuccess($this->t('users.updated'));
        $this->redirect('/users');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::USERS_MANAGE);

        if ($id === Auth::id()) {
            $this->flashError($this->t('users.cannot_delete_self'));
            $this->redirect('/users');
        }

        $user = $this->users->findById($id);
        if ($user && $this->wouldOrphanSuperAdmin($id, 0, 0)) {
            $this->flashError($this->t('users.last_super_admin'));
            $this->redirect('/users');
        }
        if ($user && $this->isSuperAdminRole((int) $user['role_id']) && !Auth::isSuperAdmin()) {
            $this->flashError($this->t('users.super_admin_only'));
            $this->redirect('/users');
        }

        $this->users->delete($id);
        AuditLog::record(AuditLog::DELETE, 'user', $id, $user['username'] ?? null);
        $this->flashSuccess($this->t('users.deleted'));
        $this->redirect('/users');
    }

    private function collectInput(): array
    {
        return [
            'username' => trim($_POST['username'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'full_name' => trim($_POST['full_name'] ?? ''),
            'role_id' => (int) ($_POST['role_id'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'password' => $_POST['password'] ?? '',
        ];
    }

    private function validate(array $data, ?int $excludeId): array
    {
        $errors = [];

        if ($data['username'] === '' || $data['email'] === '' || $data['full_name'] === '') {
            $errors[] = $this->t('users.required_fields');
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->t('users.invalid_email');
        }

        if ($excludeId === null && $data['password'] === '') {
            $errors[] = $this->t('users.password_required');
        }

        if (!$errors && $this->users->usernameOrEmailExists($data['username'], $data['email'], $excludeId)) {
            $errors[] = $this->t('users.username_email_taken');
        }

        $roles = array_column($this->assignableRoles(), 'id');
        if (!in_array($data['role_id'], array_map('intval', $roles), true)) {
            $errors[] = $this->t('users.role_required');
        }

        // Szuper admint csak szuper admin nevezhet ki — különben a felhasználó-
        // kezelés joga csendben mindenhez hozzáférést adna.
        if ($excludeId !== null) {
            $current = $this->users->findById($excludeId);
            if ($current && $this->isSuperAdminRole((int) $current['role_id']) && !Auth::isSuperAdmin()) {
                $errors[] = $this->t('users.super_admin_only');
            }
        }

        return $errors;
    }

    /** @return list<array<string, mixed>> a kiosztható szerepkörök: a szuper admint csak szuper admin adhatja */
    private function assignableRoles(): array
    {
        $roles = (new RoleModel())->all();

        return Auth::isSuperAdmin()
            ? $roles
            : array_values(array_filter($roles, static fn(array $r): bool => $r['code'] !== RoleCode::SUPER_ADMIN));
    }

    private function isSuperAdminRole(int $roleId): bool
    {
        return ((new RoleModel())->findById($roleId)['code'] ?? null) === RoleCode::SUPER_ADMIN;
    }

    private function roleName(int $roleId): string
    {
        return (string) ((new RoleModel())->findById($roleId)['name'] ?? '—');
    }

    /** Az utolsó aktív szuper admin elvesztené-e a helyét ettől a változtatástól. */
    private function wouldOrphanSuperAdmin(int $userId, int $newRoleId, int $active): bool
    {
        $user = $this->users->findById($userId);
        if (!$user || !$user['is_active'] || !$this->isSuperAdminRole((int) $user['role_id'])) {
            return false;
        }

        $staysSuperAdmin = $active === 1 && $this->isSuperAdminRole($newRoleId);

        return !$staysSuperAdmin && (new RoleModel())->otherActiveSuperAdmins($userId) === 0;
    }
}
