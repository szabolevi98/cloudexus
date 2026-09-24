<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Config;
use Cloudexus\Core\Theme;

/**
 * Switches between the light, the dark and the system's theme by storing the
 * choice in a long-lived cookie, then redirects back — the same way the
 * language switch works, so it needs no script.
 */
class ThemeController
{
    public function switch(string $mode): void
    {
        if (in_array($mode, Theme::MODES, true)) {
            setcookie(Theme::COOKIE, $mode, [
                'expires' => time() + 31536000, // ~1 year
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $base = (string) Config::get('app.base_url');
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $target = ($referer !== '' && str_starts_with($referer, $base)) ? $referer : $base . '/dashboard';

        header('Location: ' . $target);
        exit;
    }
}
