<?php

namespace Cloudexus\Tests\Unit;

use Cloudexus\Core\Permissions;
use Cloudexus\Core\RoleCode;
use PHPUnit\Framework\TestCase;

/** The permission catalog and the default matrix stay consistent with each other. */
final class PermissionsTest extends TestCase
{
    public function testEveryKeyIsInExactlyOneGroup(): void
    {
        $all = Permissions::all();

        self::assertSame(count($all), count(array_unique($all)), 'a key appears in two groups');
        foreach ($all as $key) {
            self::assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $key);
            self::assertTrue(Permissions::exists($key));
        }
        self::assertFalse(Permissions::exists('nope.nothing'));
    }

    public function testDefaultsOnlyUseCatalogKeys(): void
    {
        foreach (Permissions::defaults() as $role => $keys) {
            self::assertSame([], array_values(array_diff($keys, Permissions::all())), "$role has keys outside the catalog");
            self::assertSame(count($keys), count(array_unique($keys)), "$role lists a key twice");
        }
    }

    public function testSuperAdminHasNoDefaultRowsAndEveryOtherBuiltInRoleHasSome(): void
    {
        $defaults = Permissions::defaults();

        // The super admin's access comes from code, not from rows.
        self::assertArrayNotHasKey(RoleCode::SUPER_ADMIN, $defaults);
        foreach ([RoleCode::MANAGER, RoleCode::FINANCE, RoleCode::SALES, RoleCode::WAREHOUSE, RoleCode::VIEWER] as $role) {
            self::assertNotEmpty($defaults[$role] ?? [], "$role has no default permissions");
        }
    }

    public function testManagerGetsEverythingButTheSystemAreaExceptTheAuditLog(): void
    {
        $manager = Permissions::defaults()[RoleCode::MANAGER];
        $system = Permissions::groups()['system'];

        self::assertContains(Permissions::AUDIT_VIEW, $manager);
        foreach (array_diff($system, [Permissions::AUDIT_VIEW]) as $key) {
            self::assertNotContains($key, $manager);
        }
        self::assertSame([], array_values(array_diff(array_diff(Permissions::all(), $system), $manager)));
    }

    public function testTheViewerCanOnlyLook(): void
    {
        foreach (Permissions::defaults()[RoleCode::VIEWER] as $key) {
            self::assertStringEndsWith('.view', $key);
        }
    }
}
