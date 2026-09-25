<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Core\Acl;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Permissions;
use Cloudexus\Core\PermissionSeeder;
use Cloudexus\Model\Account\RoleModel;

final class AccessTest extends DatabaseTestCase
{
    public function testTheSuperAdminCanDoEverythingWithoutAnyRows(): void
    {
        $this->signIn($this->user('super_admin', 'root'));

        foreach (Permissions::all() as $key) {
            self::assertTrue(Acl::can($key), $key);
        }
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM role_permissions p JOIN roles r ON r.id = p.role_id WHERE r.code = 'super_admin'"));
    }

    public function testAViewerSeesButDoesNotChange(): void
    {
        $this->signIn($this->user('viewer'));

        self::assertTrue(Acl::can(Permissions::INVOICES_VIEW));
        self::assertFalse(Acl::can(Permissions::INVOICES_ISSUE));
        self::assertFalse(Acl::can(Permissions::USERS_MANAGE));
        self::assertTrue(Acl::canAny([Permissions::INVOICES_ISSUE, Permissions::STOCK_VIEW]));
    }

    public function testNobodySignedInCanDoNothing(): void
    {
        self::assertFalse(Acl::can(Permissions::DASHBOARD_VIEW));
    }

    public function testAMatrixChangeAppliesOnTheNextRequestAndReportsTheDifference(): void
    {
        $userId = $this->user('warehouse');
        $this->signIn($userId);
        self::assertTrue(Acl::can(Permissions::STOCK_MOVE));

        $roles = new RoleModel();
        $role = $roles->findByCode('warehouse');
        $keep = array_values(array_diff($roles->permissions((int) $role['id']), [Permissions::STOCK_MOVE]));
        $diff = $roles->setPermissions((int) $role['id'], [...$keep, 'made.up']);

        self::assertSame([Permissions::STOCK_MOVE], $diff['removed']);
        self::assertSame([], $diff['added'], 'an unknown key is not stored');

        Auth::forget(); // the next request
        self::assertFalse(Acl::can(Permissions::STOCK_MOVE));
        self::assertFalse(Acl::userCan($userId, Permissions::STOCK_MOVE), 'the API check agrees');
    }

    public function testTheSuperAdminRowsCannotBeWritten(): void
    {
        $roles = new RoleModel();
        $superAdmin = $roles->findByCode('super_admin');

        self::assertSame(['added' => [], 'removed' => []], $roles->setPermissions((int) $superAdmin['id'], [Permissions::DASHBOARD_VIEW]));
        self::assertSame([], $roles->permissions((int) $superAdmin['id']));
    }

    public function testADeactivatedUserIsSignedOutOnTheNextRequest(): void
    {
        $userId = $this->user('manager');
        $this->signIn($userId);
        self::assertTrue(Auth::check());

        $this->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = :id')->execute(['id' => $userId]);
        Auth::forget();

        self::assertFalse(Auth::check());
    }

    public function testTheSeederGrantsANewKeyOnceAndDoesNotGiveBackOneTakenAway(): void
    {
        $roles = new RoleModel();
        $manager = (int) $roles->findByCode('manager')['id'];

        // Pretend pricing.manage is new: forget it was ever seen, and take it from the manager.
        $known = json_decode((string) $this->scalar("SELECT setting_value FROM settings WHERE setting_key = 'permissions.known'"), true);
        $this->pdo()->prepare("UPDATE settings SET setting_value = :v WHERE setting_key = 'permissions.known'")
            ->execute(['v' => json_encode(array_values(array_diff($known, [Permissions::PRICING_MANAGE])))]);
        $this->pdo()->prepare('DELETE FROM role_permissions WHERE role_id = :r AND permission = :p')
            ->execute(['r' => $manager, 'p' => Permissions::PRICING_MANAGE]);

        self::assertSame(1, PermissionSeeder::run()['granted']);
        self::assertContains(Permissions::PRICING_MANAGE, $roles->permissions($manager));

        // Now it is known: taking it away on the matrix sticks through the next migration.
        $roles->setPermissions($manager, array_values(array_diff($roles->permissions($manager), [Permissions::PRICING_MANAGE])));
        self::assertSame(0, PermissionSeeder::run()['granted']);
        self::assertNotContains(Permissions::PRICING_MANAGE, $roles->permissions($manager));
    }
}
