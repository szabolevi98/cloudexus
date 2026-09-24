<?php

namespace Cloudexus\Core;

use Cloudexus\Model\Account\UserModel;

/**
 * Ki van bejelentkezve. A munkamenet csak a felhasználó azonosítóját
 * őrzi; a felhasználót és a szerepkörét minden kérés frissen olvassa be,
 * így egy letiltott felhasználó a következő kattintásánál kint van, és egy
 * szerepkör-váltás is azonnal érvényes — nem csak a munkamenet lejártakor.
 */
class Auth
{
    /** @var array<string, mixed>|null */
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function attempt(string $username, string $password): bool
    {
        $user = (new UserModel())->findByUsernameOrEmail($username);

        if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
            AuditLog::record(AuditLog::LOGIN_FAILED, 'user', $user ? (int) $user['id'] : null, $username, null,
                ['id' => $user ? (int) $user['id'] : null, 'name' => $user['full_name'] ?? null]);

            return false;
        }

        // Session fixation ellen: új session id a sikeres belépéskor.
        Session::regenerate();

        Session::set('user_id', (int) $user['id']);
        Session::set('logged_in_at', time());
        Session::set('user_name', $user['full_name']);

        self::forget();
        (new UserModel())->touchLastLogin((int) $user['id']);
        AuditLog::record(AuditLog::LOGIN, 'user', (int) $user['id'], (string) $user['username']);

        return true;
    }

    public static function logout(): void
    {
        if (self::check()) {
            AuditLog::record(AuditLog::LOGOUT, 'user', self::id(), (string) (self::user()['username'] ?? ''));
        }

        Session::destroy();
        self::forget();
    }

    /**
     * A bejelentkezett, aktív felhasználó a szerepkörével — vagy null. Ha a
     * munkamenet olyan felhasználóra mutat, aki már nincs vagy le van tiltva,
     * a munkamenet megszűnik.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = Session::get('user_id');

            if ($id !== null) {
                self::$user = (new UserModel())->findActiveWithRole((int) $id);

                if (self::$user === null) {
                    Session::destroy();
                }
            }
        }

        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function name(): ?string
    {
        return self::user()['full_name'] ?? null;
    }

    public static function roleId(): ?int
    {
        $user = self::user();

        return $user === null || $user['role_id'] === null ? null : (int) $user['role_id'];
    }

    /** A szerepkör kódja (super_admin, manager, …). */
    public static function role(): ?string
    {
        return self::user()['role_code'] ?? null;
    }

    public static function isSuperAdmin(): bool
    {
        return self::role() === RoleCode::SUPER_ADMIN;
    }

    /** @deprecated a régi admin / user felosztásból; a szuper admint jelenti. */
    public static function isAdmin(): bool
    {
        return self::isSuperAdmin();
    }

    /** A kérésen belüli gyorsítótár eldobása (belépés, kilépés, szerepkör-váltás után). */
    public static function forget(): void
    {
        self::$user = null;
        self::$loaded = false;
        Acl::flush();
    }
}
