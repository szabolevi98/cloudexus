<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Config;
use Cloudexus\Core\Theme;

/**
 * The theme: the header's switch flips between light and dark, and the
 * profile — or an address — can choose the system's too. The choice is kept
 * with the signed-in user and in a cookie (see Theme), then the page comes
 * back, so it needs no script.
 */
class ThemeController
{
    /** /theme/{light|dark|system}: that one. */
    public function switch(string $mode): void
    {
        Theme::choose($mode);
        $this->back();
    }

    /**
     * The header's switch: the other one of light and dark from what the page
     * showed. With the system's chosen only the browser knows which that was,
     * so the page says where to go ("to"); without it, the other one from the
     * choice kept.
     */
    public function toggle(): void
    {
        $to = (string) ($_POST['to'] ?? '');
        if (!in_array($to, ['light', 'dark'], true)) {
            $to = Theme::mode() === 'dark' ? 'light' : 'dark';
        }

        Theme::choose($to);
        $this->back();
    }

    private function back(): never
    {
        $base = (string) Config::get('app.base_url');
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $target = ($referer !== '' && str_starts_with($referer, $base)) ? $referer : $base . '/dashboard';

        header('Location: ' . $target);
        exit;
    }
}
