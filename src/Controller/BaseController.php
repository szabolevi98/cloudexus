<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Acl;
use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Config;
use Cloudexus\Core\Currency;
use Cloudexus\Core\Lang;
use Cloudexus\Core\Permissions;
use Cloudexus\Core\Language;
use Cloudexus\Core\Session;
use Cloudexus\Core\Sort;
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
        // Mennyiség annyi tizedessel, amennyi kell: 12, 2,5, 0,125 (kg, l, m is).
        $this->twig->addFilter(new TwigFilter('qty', [\Cloudexus\Core\Quantity::class, 'format']));
        // {{ currency_symbol() }} önmagában, pl. beviteli mezők címkéihez. Twig
        // függvény és nem globális, hogy csak akkor kérdezze le a pénznemet, ha kell.
        $this->twig->addFunction(new TwigFunction('currency_symbol', [Currency::class, 'symbol']));
        // {% if can('invoices.issue') %} — csak elrejt; a kaput a controller zárja.
        $this->twig->addFunction(new TwigFunction('can', [Acl::class, 'can']));
        $this->twig->addFunction(new TwigFunction('can_any', static fn(string ...$permissions): bool => Acl::canAny($permissions)));
        // Oszloprendezés: <th {{ sort_aria('name') }}>{{ sort_link('name', t('…')) }}</th>,
        // a szűrőűrlapba {{ sort_inputs() }}. Hogy mi rendezhető, azt a model SORTS-a dönti el.
        $this->twig->addFunction(new TwigFunction('sort_link', [Sort::class, 'link'], ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('sort_aria', [Sort::class, 'aria'], ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('sort_inputs', [Sort::class, 'inputs'], ['is_safe' => ['html']]));
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
            'auth_user_name' => Auth::name(),
            'auth_role_name' => Auth::user()['role_name'] ?? null,
            'auth_is_admin' => Auth::isSuperAdmin(),
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
    /** The newest change among our own CSS and JS files, so a changed script is fetched again too. */
    private function assetVersion(): string
    {
        $assets = dirname(__DIR__, 2) . '/web/assets';
        $files = array_merge([$assets . '/css/app.css'], glob($assets . '/js/*.js') ?: []);
        $times = array_map(static fn(string $file): int => (int) @filemtime($file), $files);
        $newest = max($times);

        return $newest > 0 ? (string) $newest : '1';
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

    /**
     * Az első oldal, amit a felhasználó megnyithat — a belépés után ide
     * kerül, hogy egy vezérlőpult nélküli szerepkör se egy 403-on kezdjen.
     */
    protected function homePath(): string
    {
        $pages = [
            Permissions::DASHBOARD_VIEW => '/dashboard',
            Permissions::ORDERS_VIEW => '/orders',
            Permissions::INVOICES_VIEW => '/invoices',
            Permissions::STOCK_VIEW => '/stock',
            Permissions::PRODUCTS_VIEW => '/products',
            Permissions::PARTNERS_VIEW => '/partners',
            Permissions::PURCHASING_VIEW => '/purchase-orders',
            Permissions::CASH_VIEW => '/cash',
            Permissions::CRM_VIEW => '/todos',
            Permissions::USERS_MANAGE => '/users',
        ];
        foreach ($pages as $permission => $path) {
            if (Acl::can($permission)) {
                return $path;
            }
        }

        return '/profile';
    }

    /**
     * A jogosultság kapuja: belépés nélkül a login oldalra visz, a jog
     * hiányában naplóz, és a menüvel együtt egy 403-as oldalt mutat.
     */
    protected function requirePermission(string $permission): void
    {
        $this->requireAuth();
        if (!Acl::can($permission)) {
            $this->forbidden($permission);
        }
    }

    /** Legalább az egyik a felsoroltak közül (pl. egy több területet érintő oldal). */
    protected function requireAnyPermission(string ...$permissions): void
    {
        $this->requireAuth();
        if (!Acl::canAny($permissions)) {
            $this->forbidden(implode('|', $permissions));
        }
    }

    private function forbidden(string $permission): never
    {
        AuditLog::record(AuditLog::DENIED, 'permission', null, $permission);
        http_response_code(403);

        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        if ($isAjax) {
            $this->json(['success' => false, 'message' => $this->t('errors.forbidden')]);
        }

        $this->pageTitle = $this->t('errors.forbidden_title');
        $this->render('common/forbidden.twig');
        exit;
    }
}
