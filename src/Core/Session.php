<?php

namespace Cloudexus\Core;

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Alapból egy év az utolsó kattintástól (a böngészők legfeljebb 400
        // napig tartanak meg egy sütit). Egy elhagyott laptopot a profil
        // "Kijelentkezés mindenhol máshol" gombja vagy egy új jelszó zár ki.
        $lifetime = (int) Config::get('session.lifetime', 60 * 60 * 24 * 365);

        // A GC a gc_maxlifetime alapján törli a szerver oldali session fájlt.
        // Enélkül a PHP alapértelmezett ~24 perce után kidobna a rendszer.
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.cookie_lifetime', (string) $lifetime);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '1000');
        ini_set('session.use_strict_mode', '1');

        // Saját session-mappa, hogy a közös (XAMPP) tárhelyen futó más appok
        // rövid GC-je ne törölje a mi session fájljainkat (és fordítva).
        $savePath = dirname(__DIR__, 2) . '/var/sessions';
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0777, true);
        }
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        session_name(Config::get('session.name', 'cloudexus_session'));
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // A cookie lejáratát minden kérésnél megújítjuk (csúszó ablak), így az
        // aktív felhasználó gyakorlatilag korlátlanul bejelentkezve marad.
        if (!empty($_SESSION)) {
            self::refreshCookie($lifetime);
        }
    }

    /** Regenerates the session id (call after login to prevent fixation). */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $_SESSION['_flash'][$key] = $message;
            return null;
        }

        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private static function refreshCookie(int $lifetime): void
    {
        setcookie(session_name(), session_id(), [
            'expires' => time() + $lifetime,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }
}
