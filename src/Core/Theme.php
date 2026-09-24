<?php

namespace Cloudexus\Core;

/**
 * Light or dark: the choice is kept in a long-lived cookie, like the language,
 * so it holds across sessions and on the login page too. "system" follows the
 * operating system — the page asks the browser before it is first drawn, so it
 * never flashes light on a dark machine.
 */
final class Theme
{
    public const COOKIE = 'cx_theme';
    public const MODES = ['light', 'dark', 'system'];

    /** The chosen mode; "system" until somebody chooses. */
    public static function mode(): string
    {
        $chosen = (string) ($_COOKIE[self::COOKIE] ?? '');

        return in_array($chosen, self::MODES, true) ? $chosen : 'system';
    }
}
