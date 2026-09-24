<?php

namespace Cloudexus\Core;

use Cloudexus\Model\Account\RoleModel;

/**
 * Mit tehet a bejelentkezett felhasználó. A szerepköre jogai a
 * role_permissions táblából jönnek, kérésenként egyszer betöltve.
 *
 * A szuper admin definíció szerint mindent elér: nála nem kérdezzük a
 * táblát, így egy elrontott mátrix sem zárhatja ki a rendszerből.
 *
 * A controller a valódi kapu (requirePermission); a Twig can() csak a
 * gombokat és a menüpontokat rejti el.
 *
 *   Acl::can(Permissions::INVOICES_ISSUE)    // bool
 *   {% if can('invoices.issue') %} … {% endif %}
 */
final class Acl
{
    /** @var list<string>|null */
    private static ?array $granted = null;

    /** Belépés, kilépés vagy mátrix-mentés után a gyorsítótár eldobása. */
    public static function flush(): void
    {
        self::$granted = null;
    }

    public static function can(string $permission): bool
    {
        if (!Auth::check()) {
            return false;
        }

        if (Auth::isSuperAdmin()) {
            return true;
        }

        return in_array($permission, self::granted(), true);
    }

    /** Igaz, ha a felsoroltak közül legalább egy megvan. */
    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Egy tetszőleges felhasználó joga — a mobil API-hoz, ahol a kérést egy
     * felhasználói token azonosítja, nem a munkamenet.
     */
    public static function userCan(int $userId, string $permission): bool
    {
        $role = (new RoleModel())->forUser($userId);

        if ($role === null) {
            return false;
        }

        return $role['code'] === RoleCode::SUPER_ADMIN
            || in_array($permission, (new RoleModel())->permissions((int) $role['id']), true);
    }

    /**
     * Kényszerítő ellenőrzés a controllerben: belépés nélkül a login oldalra
     * irányít, jogosultság nélkül naplóz és 403-mal megáll.
     */
    public static function require(string $permission): void
    {
        if (!Auth::check()) {
            header('Location: ' . Config::get('app.base_url') . '/login');
            exit;
        }

        if (self::can($permission)) {
            return;
        }

        AuditLog::record(AuditLog::DENIED, 'permission', null, $permission);

        http_response_code(403);
        echo Lang::get('errors.forbidden');
        exit;
    }

    /** @return list<string> a bejelentkezett felhasználó jogai */
    public static function granted(): array
    {
        if (self::$granted === null) {
            $roleId = Auth::roleId();
            self::$granted = $roleId === null ? [] : (new RoleModel())->permissions($roleId);
        }

        return self::$granted;
    }
}
