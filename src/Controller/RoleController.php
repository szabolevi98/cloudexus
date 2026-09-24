<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Acl;
use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Permissions;
use Cloudexus\Core\RoleCode;
use Cloudexus\Model\Account\RoleModel;

/**
 * Szerepkörök és a jogosultság-mátrix.
 *
 * A kulcsok katalógusa kódban él (Core/Permissions.php), a hozzárendelés
 * adatbázisban — így a mátrix fejlesztő nélkül hangolható. A szuper admin
 * oszlopa nem szerkeszthető: nála a teljes hozzáférés a kódból jön.
 */
class RoleController extends BaseController
{
    private RoleModel $roles;

    public function __construct()
    {
        parent::__construct();
        $this->roles = new RoleModel();
        $this->activeMenu = 'roles';
    }

    /** A mátrix: szerepkörök oszlopban, jogosultságok sorban, csoportonként. */
    public function matrix(): void
    {
        $this->requirePermission(Permissions::ROLES_MANAGE);

        $this->pageTitle = $this->t('roles.matrix_title');
        $this->render('roles/matrix.twig', [
            'roles' => $this->roles->allWithCounts(),
            'groups' => Permissions::groups(),
            'matrix' => $this->roles->matrix(),
            'super_admin_code' => RoleCode::SUPER_ADMIN,
        ]);
    }

    /**
     * A teljes mátrix mentése. A beérkező kulcsokat a modell a katalógushoz
     * szűri; a naplóba szerepkörönként a hozzáadott és az elvett jogok kerülnek.
     */
    public function updateMatrix(): void
    {
        $this->requirePermission(Permissions::ROLES_MANAGE);

        $changed = 0;
        foreach ($this->roles->all() as $role) {
            if ($role['code'] === RoleCode::SUPER_ADMIN) {
                continue;
            }

            $submitted = array_map('strval', (array) ($_POST['matrix'][$role['id']] ?? []));
            $diff = $this->roles->setPermissions((int) $role['id'], $submitted);

            if ($diff['added'] || $diff['removed']) {
                AuditLog::record(AuditLog::PERMISSIONS, 'role', (int) $role['id'], $role['name'], $diff);
                $changed++;
            }
        }

        Acl::flush();
        $this->flashSuccess($changed
            ? $this->t('roles.matrix_saved', ['count' => $changed])
            : $this->t('roles.matrix_unchanged'));
        $this->redirect('/roles');
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::ROLES_MANAGE);

        $this->pageTitle = $this->t('roles.list_title');
        $this->render('roles/list.twig', [
            'roles' => $this->roles->allWithCounts(),
            'super_admin_code' => RoleCode::SUPER_ADMIN,
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::ROLES_MANAGE);

        $this->pageTitle = $this->t('roles.new');
        $this->render('roles/form.twig', ['role' => null]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::ROLES_MANAGE);

        $data = $this->collectInput();
        $errors = $this->validate($data, null);
        if ($errors) {
            $this->flashError(implode(' ', $errors));
            $this->redirect('/roles/create');
        }

        $id = $this->roles->create($data);
        AuditLog::record(AuditLog::CREATE, 'role', $id, $data['name']);

        $this->flashSuccess($this->t('roles.created'));
        $this->redirect('/roles');
    }

    public function editForm(int $id): void
    {
        $this->requirePermission(Permissions::ROLES_MANAGE);

        $role = $this->roles->findById($id);
        if (!$role) {
            $this->redirect('/roles/list');
        }

        $this->pageTitle = $this->t('roles.edit');
        $this->render('roles/form.twig', ['role' => $role]);
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::ROLES_MANAGE);

        $role = $this->roles->findById($id);
        if (!$role) {
            $this->redirect('/roles/list');
        }

        $data = $this->collectInput();
        // Egy beépített szerepkör kódjára a kód hivatkozik, ezért az nem változik.
        if ($role['is_system']) {
            $data['code'] = $role['code'];
        }

        $errors = $this->validate($data, $id);
        if ($errors) {
            $this->flashError(implode(' ', $errors));
            $this->redirect('/roles/' . $id . '/edit');
        }

        $this->roles->update($id, $data);
        AuditLog::record(AuditLog::UPDATE, 'role', $id, $data['name']);

        $this->flashSuccess($this->t('roles.updated'));
        $this->redirect('/roles/list');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::ROLES_MANAGE);

        $role = $this->roles->findById($id);
        if (!$role) {
            $this->redirect('/roles/list');
        }

        if ($role['is_system']) {
            $this->flashError($this->t('roles.cannot_delete_system'));
            $this->redirect('/roles/list');
        }

        if (!$this->roles->delete($id)) {
            $this->flashError($this->t('roles.cannot_delete_in_use'));
            $this->redirect('/roles/list');
        }

        AuditLog::record(AuditLog::DELETE, 'role', $id, $role['name']);
        $this->flashSuccess($this->t('roles.deleted'));
        $this->redirect('/roles/list');
    }

    private function collectInput(): array
    {
        return [
            'code' => strtolower(trim($_POST['code'] ?? '')),
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
    }

    private function validate(array $data, ?int $excludeId): array
    {
        $errors = [];

        if ($data['name'] === '' || $data['code'] === '') {
            $errors[] = $this->t('roles.required');
        }

        if ($data['code'] !== '' && !preg_match('/^[a-z][a-z0-9_]{1,39}$/', $data['code'])) {
            $errors[] = $this->t('roles.invalid_code');
        }

        if (!$errors && $this->roles->codeExists($data['code'], $excludeId)) {
            $errors[] = $this->t('roles.code_taken');
        }

        return $errors;
    }
}
