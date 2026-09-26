<?php

namespace Cloudexus\Core;

/**
 * Light, dark, or the operating system's: kept with the user (see
 * 24_user_theme.sql), so the choice follows them to every browser, and in a
 * long-lived cookie too, for the login page, where nobody is signed in yet.
 * "system" follows the operating system — the page asks the browser before
 * it is first drawn, so it never flashes light on a dark machine.
 */
final class Theme
{
    public const COOKIE = 'cx_theme';
    public const MODES = ['light', 'dark', 'system'];

    /**
     * The chosen mode: the signed-in user's; failing that — nobody signed
     * in, or somebody who has not chosen since it moved to the profile — the
     * cookie's; failing that, "system".
     */
    public static function mode(): string
    {
        $chosen = (string) (Auth::user()['theme'] ?? '');

        if (!in_array($chosen, self::MODES, true)) {
            $chosen = (string) ($_COOKIE[self::COOKIE] ?? '');
        }

        return in_array($chosen, self::MODES, true) ? $chosen : 'system';
    }

    /** Keeps a choice: with the signed-in user, and in the cookie. */
    public static function choose(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            return;
        }

        if (Auth::id() !== null) {
            (new \Cloudexus\Model\Account\UserModel())->setTheme((int) Auth::id(), $mode);
            Auth::forget();
        }

        setcookie(self::COOKIE, $mode, [
            'expires' => time() + 31536000, // ~1 year
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $mode;
    }
}
