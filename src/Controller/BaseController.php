<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Auth;
use Cloudexus\Core\Config;
use Cloudexus\Core\Currency;
use Cloudexus\Core\Lang;
use Cloudexus\Core\Language;
use Cloudexus\Core\Session;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

abstract class BaseController
{
    protected Environment $twig;
    protected string $activeMenu = '';
    protected string $pageTitle = '';

    public function __construct()
    {
        $loader = new FilesystemLoader(dirname(__DIR__) . '/View/Twig');
        $this->twig = new Environment($loader, [
            'cache' => Config::get('app.debug') ? false : dirname(__DIR__, 2) . '/var/cache/twig',
        ]);
        // {{ t('domain.key') }} translation helper, with optional {placeholders}.
        $this->twig->addFunction(new TwigFunction('t', [Lang::class, 'get']));
        // {{ amount|money }} formázás az elsődleges pénznemben, pl. "89 900 Ft".
        $this->twig->addFilter(new TwigFilter('money', [Currency::class, 'format']));
        // {{ currency_symbol() }} önmagában, pl. beviteli mezők címkéihez. Twig
        // függvény és nem globális, hogy csak akkor kérdezze le a pénznemet, ha kell.
        $this->twig->addFunction(new TwigFunction('currency_symbol', [Currency::class, 'symbol']));
    }

    /** Translate a key (controller-side: flash messages, page titles, …). */
    protected function t(string $key, array $replace = []): string
    {
        return Lang::get($key, $replace);
    }

    protected function render(string $template, array $data = []): void
    {
        $flashes = array_filter([
            'success' => Session::flash('success'),
            'error' => Session::flash('error'),
        ]);

        echo $this->twig->render($template, array_merge([
            'auth_user_id' => Auth::id(),
            'auth_user_name' => Auth::check() ? Session::get('user_name') : null,
            'auth_is_admin' => Auth::isAdmin(),
            'base_url' => Config::get('app.base_url'),
            'asset_version' => $this->assetVersion(),
            'csrf_token' => \Cloudexus\Core\Csrf::token(),
            'active_menu' => $this->activeMenu,
            'page_title' => $this->pageTitle,
            'current_locale' => Lang::locale(),
            'theme_mode' => \Cloudexus\Core\Theme::mode(),
            'available_locales' => Lang::available(),
            // A nyelvváltó a languages táblából töltődik, hogy a saját nevén
            // jelenjen meg minden nyelv (Magyar, English, …).
            'languages' => Language::all(),
            'flashes' => $flashes,
        ], $data));
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . Config::get('app.base_url') . $path);
        exit;
    }

    /** Cache-busting token for static assets: the built CSS file's mtime. */
    private function assetVersion(): string
    {
        $cssFile = dirname(__DIR__, 2) . '/web/assets/css/app.css';
        return is_file($cssFile) ? (string) filemtime($cssFile) : '1';
    }

    protected function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function flashSuccess(string $message): void
    {
        Session::flash('success', $message);
    }

    protected function flashError(string $message): void
    {
        Session::flash('error', $message);
    }

    protected function requireAuth(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
        }
    }

    protected function requireAdmin(): void
    {
        $this->requireAuth();
        if (!Auth::isAdmin()) {
            http_response_code(403);
            echo $this->t('errors.forbidden');
            exit;
        }
    }
}
