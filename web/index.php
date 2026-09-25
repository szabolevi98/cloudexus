<?php

use Cloudexus\Controller\Api\AuthApiController;
use Cloudexus\Controller\Api\CategoryApiController;
use Cloudexus\Controller\Api\CurrencyApiController;
use Cloudexus\Controller\Api\CustomerGroupApiController;
use Cloudexus\Controller\Api\InvoiceApiController;
use Cloudexus\Controller\Api\LanguageApiController;
use Cloudexus\Controller\Api\OrderApiController;
use Cloudexus\Controller\Api\ParameterApiController;
use Cloudexus\Controller\Api\PartnerApiController;
use Cloudexus\Controller\Api\PricingApiController;
use Cloudexus\Controller\Api\ProductApiController;
use Cloudexus\Controller\Api\StockApiController;
use Cloudexus\Controller\Api\UnitApiController;
use Cloudexus\Controller\Api\WarehouseApiController;
use Cloudexus\Controller\ApiUserController;
use Cloudexus\Controller\AuditController;
use Cloudexus\Controller\CashVoucherController;
use Cloudexus\Controller\CategoryController;
use Cloudexus\Controller\CurrencyController;
use Cloudexus\Controller\CustomerGroupController;
use Cloudexus\Controller\DashboardController;
use Cloudexus\Controller\EmailController;
use Cloudexus\Controller\IncomingInvoiceController;
use Cloudexus\Controller\InvoiceController;
use Cloudexus\Controller\LanguageController;
use Cloudexus\Controller\LocaleController;
use Cloudexus\Controller\LocationController;
use Cloudexus\Controller\LoginController;
use Cloudexus\Controller\OrderController;
use Cloudexus\Controller\ParameterController;
use Cloudexus\Controller\PartnerController;
use Cloudexus\Controller\PasswordResetController;
use Cloudexus\Controller\PriceRuleController;
use Cloudexus\Controller\PricingController;
use Cloudexus\Controller\ProductController;
use Cloudexus\Controller\ProfileController;
use Cloudexus\Controller\PurchaseOrderController;
use Cloudexus\Controller\ReportController;
use Cloudexus\Controller\RoleController;
use Cloudexus\Controller\SettingsController;
use Cloudexus\Controller\StockController;
use Cloudexus\Controller\StocktakingController;
use Cloudexus\Controller\ThemeController;
use Cloudexus\Controller\TodoController;
use Cloudexus\Controller\TwoFactorController;
use Cloudexus\Controller\UnitController;
use Cloudexus\Controller\UserController;
use Cloudexus\Controller\WarehouseController;
use Cloudexus\Core\Config;
use Cloudexus\Core\Csrf;
use Cloudexus\Core\Router;
use Cloudexus\Core\Session;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::load(dirname(__DIR__) . '/config/config.ini');
date_default_timezone_set(Config::get('app.timezone', 'Europe/Budapest'));
\Cloudexus\Core\SecurityHeaders::send();

// Nyelv: a languages tábla az egyetlen forrás a felület és az adatok nyelvéhez
// is. A választást a cx_locale süti tartja; a REST API a ?language= paraméterrel
// írhatja felül (lásd ApiController). Ha a tábla még nem létezik (friss
// telepítés a migráció előtt), a Language az alapnyelvre esik vissza.
\Cloudexus\Core\Language::init($_COOKIE['cx_locale'] ?? null);
\Cloudexus\Core\Lang::init(
    \Cloudexus\Core\Language::defaultCode(),
    \Cloudexus\Core\Language::codes(),
    \Cloudexus\Core\Language::code()
);

// Never leak PHP notices/warnings into responses (they would corrupt JSON,
// CSV downloads and redirects). Everything is logged to var/log instead.
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

set_error_handler(function (int $level, string $message, string $file, int $line): bool {
    \Cloudexus\Core\Logger::error($message, ['file' => $file, 'line' => $line]);
    return true;
});

// The REST API (/api/*) authenticates with a bearer token, not the session
// cookie, so it must bypass session start and the form CSRF gate.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$scriptBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($scriptBase !== '' && str_starts_with($requestPath, $scriptBase)) {
    $requestPath = substr($requestPath, strlen($scriptBase));
}
$isApiRequest = $requestPath === '/api' || str_starts_with($requestPath, '/api/');

set_exception_handler(function (\Throwable $e) use ($isApiRequest): void {
    \Cloudexus\Core\Logger::error('Uncaught: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    http_response_code(500);
    if ($isApiRequest) {
        // API clients parse every response as JSON, errors included.
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['status' => 500, 'message' => 'Unexpected server error.']]);
        return;
    }
    echo \Cloudexus\Core\Lang::get('errors.unexpected');
});

if (!$isApiRequest) {
    Session::start();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::validate($_POST['_token'] ?? null)) {
        http_response_code(403);
        exit(\Cloudexus\Core\Lang::get('errors.invalid_session'));
    }
}

$router = new Router();

/**
 * Registers the standard list / create / edit / update / delete route set
 * for a resource controller, e.g. registerCrud($router, '/products', ProductController::class).
 */
function registerCrud(Router $router, string $basePath, string $controllerClass): void
{
    $router->get($basePath, fn() => (new $controllerClass())->list());
    $router->get($basePath . '/create', fn() => (new $controllerClass())->createForm());
    $router->post($basePath . '/create', fn() => (new $controllerClass())->create());
    $router->get($basePath . '/{id}/edit', fn($id) => (new $controllerClass())->editForm((int) $id));
    $router->post($basePath . '/{id}', fn($id) => (new $controllerClass())->update((int) $id));
    $router->post($basePath . '/{id}/delete', fn($id) => (new $controllerClass())->delete((int) $id));
}

$router->get('/', fn() => header('Location: ' . Config::get('app.base_url') . '/login'));
$router->get('/login', fn() => (new LoginController())->show());
$router->post('/login', fn() => (new LoginController())->submit());
$router->get('/login/code', fn() => (new LoginController())->showCode());
$router->post('/login/code', fn() => (new LoginController())->submitCode());
$router->get('/forgot-password', fn() => (new PasswordResetController())->show());
$router->post('/forgot-password', fn() => (new PasswordResetController())->submit());
$router->get('/reset-password/{token}', fn($token) => (new PasswordResetController())->resetForm((string) $token));
$router->post('/reset-password/{token}', fn($token) => (new PasswordResetController())->reset((string) $token));
$router->post('/logout', fn() => (new LoginController())->logout());

$router->get('/lang/{code}', fn($code) => (new LocaleController())->switch($code));
$router->get('/theme/{mode}', fn($mode) => (new ThemeController())->switch($mode));

$router->get('/dashboard', fn() => (new DashboardController())->show());

$router->get('/profile', fn() => (new ProfileController())->show());
$router->post('/profile', fn() => (new ProfileController())->update());
$router->post('/profile/digest', fn() => (new ProfileController())->digest());
$router->get('/profile/two-factor', fn() => (new TwoFactorController())->show());
$router->post('/profile/two-factor/start', fn() => (new TwoFactorController())->start());
$router->post('/profile/two-factor/confirm', fn() => (new TwoFactorController())->confirm());
$router->post('/profile/two-factor/cancel', fn() => (new TwoFactorController())->cancel());
$router->post('/profile/two-factor/recovery', fn() => (new TwoFactorController())->recoveryCodes());
$router->post('/profile/two-factor/disable', fn() => (new TwoFactorController())->disable());

$router->get('/products/export', fn() => (new ProductController())->export());
$router->get('/products/search', fn() => (new ProductController())->search());
$router->get('/partners/export', fn() => (new PartnerController())->export());
$router->get('/categories/search', fn() => (new CategoryController())->search());
$router->get('/parameters/search', fn() => (new ParameterController())->search());
$router->get('/partners/search', fn() => (new PartnerController())->search());
$router->get('/pricing/effective', fn() => (new PricingController())->effective());
registerCrud($router, '/price-rules', PriceRuleController::class);

$router->post('/products/{id}/images/{imageId}/delete', fn($id, $imageId) => (new ProductController())->deleteImage((int) $id, (int) $imageId));
$router->post('/products/{id}/images/{imageId}/primary', fn($id, $imageId) => (new ProductController())->setPrimaryImage((int) $id, (int) $imageId));

$router->post('/users/{id}/two-factor/reset', fn($id) => (new TwoFactorController())->reset((int) $id));
registerCrud($router, '/users', UserController::class);
$router->get('/roles', fn() => (new RoleController())->matrix());
$router->post('/roles/matrix', fn() => (new RoleController())->updateMatrix());
$router->get('/roles/list', fn() => (new RoleController())->list());
$router->get('/roles/create', fn() => (new RoleController())->createForm());
$router->post('/roles/create', fn() => (new RoleController())->create());
$router->get('/roles/{id}/edit', fn($id) => (new RoleController())->editForm((int) $id));
$router->post('/roles/{id}', fn($id) => (new RoleController())->update((int) $id));
$router->post('/roles/{id}/delete', fn($id) => (new RoleController())->delete((int) $id));
$router->get('/audit', fn() => (new AuditController())->list());
$router->get('/reports/aging', fn() => (new ReportController())->aging());
$router->get('/reports/aging/export', fn() => (new ReportController())->agingExport());
registerCrud($router, '/categories', CategoryController::class);
registerCrud($router, '/products', ProductController::class);
registerCrud($router, '/partners', PartnerController::class);
$router->get('/partners/{id}', fn($id) => (new PartnerController())->show((int) $id));
$router->post('/partners/{id}/activities', fn($id) => (new PartnerController())->addActivity((int) $id));
$router->post('/partners/{id}/activities/{aid}/delete', fn($id, $aid) => (new PartnerController())->deleteActivity((int) $id, (int) $aid));
$router->post('/partners/{id}/addresses', fn($id) => (new PartnerController())->addAddress((int) $id));
$router->post('/partners/{id}/addresses/{aid}', fn($id, $aid) => (new PartnerController())->updateAddress((int) $id, (int) $aid));
$router->post('/partners/{id}/addresses/{aid}/delete', fn($id, $aid) => (new PartnerController())->deleteAddress((int) $id, (int) $aid));
registerCrud($router, '/warehouses', WarehouseController::class);
registerCrud($router, '/locations', LocationController::class);

$router->get('/settings/company', fn() => (new SettingsController())->company());
$router->post('/settings/company', fn() => (new SettingsController())->companyUpdate());
$router->get('/settings/email', fn() => (new EmailController())->show());
$router->post('/settings/email/test', fn() => (new EmailController())->test());
$router->post('/settings/email/{id}/retry', fn($id) => (new EmailController())->retry((int) $id));

$router->get('/parameters', fn() => (new ParameterController())->list());
$router->post('/parameters/create', fn() => (new ParameterController())->create());
$router->post('/parameters/{id}', fn($id) => (new ParameterController())->update((int) $id));
$router->post('/parameters/{id}/delete', fn($id) => (new ParameterController())->delete((int) $id));

$router->get('/units', fn() => (new UnitController())->list());
$router->post('/units/create', fn() => (new UnitController())->create());
$router->post('/units/{id}', fn($id) => (new UnitController())->update((int) $id));
$router->post('/units/{id}/delete', fn($id) => (new UnitController())->delete((int) $id));

$router->get('/languages', fn() => (new LanguageController())->list());
$router->post('/languages/create', fn() => (new LanguageController())->create());
$router->post('/languages/{id}', fn($id) => (new LanguageController())->update((int) $id));
$router->post('/languages/{id}/default', fn($id) => (new LanguageController())->setDefault((int) $id));
$router->post('/languages/{id}/delete', fn($id) => (new LanguageController())->delete((int) $id));

$router->get('/currencies', fn() => (new CurrencyController())->list());
$router->post('/currencies/create', fn() => (new CurrencyController())->create());
$router->post('/currencies/sync', fn() => (new CurrencyController())->syncRates());
$router->post('/currencies/{id}', fn($id) => (new CurrencyController())->update((int) $id));
$router->post('/currencies/{id}/primary', fn($id) => (new CurrencyController())->setPrimary((int) $id));
$router->post('/currencies/{id}/delete', fn($id) => (new CurrencyController())->delete((int) $id));

$router->get('/customer-groups', fn() => (new CustomerGroupController())->list());
$router->post('/customer-groups/create', fn() => (new CustomerGroupController())->create());
$router->post('/customer-groups/{id}', fn($id) => (new CustomerGroupController())->update((int) $id));
$router->post('/customer-groups/{id}/delete', fn($id) => (new CustomerGroupController())->delete((int) $id));

$router->get('/api-docs', fn() => (new ApiUserController())->docs());
$router->get('/api-users', fn() => (new ApiUserController())->list());
$router->get('/api-logs', fn() => (new ApiUserController())->logs());
$router->post('/api-users/create', fn() => (new ApiUserController())->create());
$router->post('/api-users/{id}', fn($id) => (new ApiUserController())->update((int) $id));
$router->post('/api-users/{id}/toggle', fn($id) => (new ApiUserController())->toggle((int) $id));
$router->post('/api-users/{id}/regenerate', fn($id) => (new ApiUserController())->regenerate((int) $id));
$router->post('/api-users/{id}/delete', fn($id) => (new ApiUserController())->delete((int) $id));

$router->get('/stock', fn() => (new StockController())->overview());
$router->get('/stock/in', fn() => (new StockController())->inList());
$router->post('/stock/in/create', fn() => (new StockController())->inCreate());
$router->get('/stock/out', fn() => (new StockController())->outList());
$router->post('/stock/out/create', fn() => (new StockController())->outCreate());
$router->get('/stock/transfer', fn() => (new StockController())->transferForm());
$router->post('/stock/transfer', fn() => (new StockController())->transferCreate());
$router->get('/stock/barcode', fn() => (new StockController())->barcodeForm());
$router->get('/stock/barcode/lookup', fn() => (new StockController())->barcodeLookup());
$router->post('/stock/barcode', fn() => (new StockController())->barcodeSubmit());

$router->get('/stocktaking', fn() => (new StocktakingController())->list());
$router->get('/stocktaking/create', fn() => (new StocktakingController())->createForm());
$router->post('/stocktaking/create', fn() => (new StocktakingController())->create());
$router->get('/stocktaking/{id}', fn($id) => (new StocktakingController())->show((int) $id));

$router->get('/todos', fn() => (new TodoController())->list());
$router->post('/todos/create', fn() => (new TodoController())->create());
$router->post('/todos/{id}/toggle', fn($id) => (new TodoController())->toggle((int) $id));
$router->post('/todos/{id}/delete', fn($id) => (new TodoController())->delete((int) $id));

$router->get('/orders', fn() => (new OrderController())->list());
$router->get('/orders/create', fn() => (new OrderController())->createForm());
$router->post('/orders/create', fn() => (new OrderController())->create());
$router->get('/orders/{id}', fn($id) => (new OrderController())->show((int) $id));
$router->post('/orders/{id}/cancel', fn($id) => (new OrderController())->cancel((int) $id));
$router->post('/orders/{id}/delete', fn($id) => (new OrderController())->delete((int) $id));

$router->get('/invoices', fn() => (new InvoiceController())->list());
$router->get('/invoices/export', fn() => (new InvoiceController())->export());
$router->get('/invoices/create', fn() => (new InvoiceController())->createForm());
$router->post('/invoices/create', fn() => (new InvoiceController())->create());
$router->get('/invoices/{id}', fn($id) => (new InvoiceController())->show((int) $id));
$router->get('/invoices/{id}/print', fn($id) => (new InvoiceController())->printView((int) $id));
$router->get('/invoices/{id}/pdf', fn($id) => (new InvoiceController())->pdf((int) $id));
$router->post('/invoices/{id}/email', fn($id) => (new InvoiceController())->email((int) $id));
$router->post('/invoices/{id}/mark-paid', fn($id) => (new InvoiceController())->markPaid((int) $id));
$router->post('/invoices/{id}/payments', fn($id) => (new InvoiceController())->addPayment((int) $id));
$router->post('/invoices/{id}/payments/{paymentId}/delete', fn($id, $paymentId) => (new InvoiceController())->deletePayment((int) $id, (int) $paymentId));
// Kiállított számlát nem törlünk és nem érvénytelenítünk: sztornó számlával vonjuk vissza.
$router->post('/invoices/{id}/storno', fn($id) => (new InvoiceController())->storno((int) $id));

$router->get('/purchase-orders', fn() => (new PurchaseOrderController())->list());
$router->get('/purchase-orders/create', fn() => (new PurchaseOrderController())->createForm());
$router->post('/purchase-orders/create', fn() => (new PurchaseOrderController())->create());
$router->get('/purchase-orders/{id}', fn($id) => (new PurchaseOrderController())->show((int) $id));
$router->post('/purchase-orders/{id}/cancel', fn($id) => (new PurchaseOrderController())->cancel((int) $id));
$router->post('/purchase-orders/{id}/delete', fn($id) => (new PurchaseOrderController())->delete((int) $id));

$router->get('/incoming-invoices', fn() => (new IncomingInvoiceController())->list());
$router->get('/incoming-invoices/create', fn() => (new IncomingInvoiceController())->createForm());
$router->post('/incoming-invoices/create', fn() => (new IncomingInvoiceController())->create());
$router->get('/incoming-invoices/{id}', fn($id) => (new IncomingInvoiceController())->show((int) $id));
$router->post('/incoming-invoices/{id}/mark-paid', fn($id) => (new IncomingInvoiceController())->markPaid((int) $id));
$router->post('/incoming-invoices/{id}/payments', fn($id) => (new IncomingInvoiceController())->addPayment((int) $id));
$router->post('/incoming-invoices/{id}/payments/{paymentId}/delete', fn($id, $paymentId) => (new IncomingInvoiceController())->deletePayment((int) $id, (int) $paymentId));
$router->post('/incoming-invoices/{id}/cancel', fn($id) => (new IncomingInvoiceController())->cancel((int) $id));

$router->get('/cash', fn() => (new CashVoucherController())->list());
$router->get('/cash/create', fn() => (new CashVoucherController())->createForm());
$router->post('/cash/create', fn() => (new CashVoucherController())->create());
$router->post('/cash/{id}/delete', fn($id) => (new CashVoucherController())->delete((int) $id));

// ---------------------------------------------------------------------------
// REST API (/api/*) — bearer-token auth, JSON. Read-only catalog, full CRUD on
// partners and orders, per-user sign-in and stock bookings for the mobile app.
// See web/API.md for the full documentation.
// ---------------------------------------------------------------------------
$router->post('/api/auth/login', fn() => (new AuthApiController())->login());
$router->post('/api/auth/logout', fn() => (new AuthApiController())->logout());
$router->get('/api/auth/me', fn() => (new AuthApiController())->me());

$router->get('/api/products', fn() => (new ProductApiController())->index());
$router->get('/api/products/lookup', fn() => (new ProductApiController())->lookup());
$router->get('/api/products/sku/{sku}', fn($sku) => (new ProductApiController())->showBySku(rawurldecode($sku)));
$router->get('/api/products/{id}', fn($id) => (new ProductApiController())->show((int) $id));

$router->get('/api/categories', fn() => (new CategoryApiController())->index());
$router->get('/api/categories/{id}', fn($id) => (new CategoryApiController())->show((int) $id));

$router->get('/api/parameters', fn() => (new ParameterApiController())->index());
// Backwards-compatible alias so existing API consumers keep working.
$router->get('/api/parameter-names', fn() => (new ParameterApiController())->index());
$router->get('/api/units', fn() => (new UnitApiController())->index());
$router->get('/api/currencies', fn() => (new CurrencyApiController())->index());
$router->get('/api/languages', fn() => (new LanguageApiController())->index());

$router->get('/api/customer-groups', fn() => (new CustomerGroupApiController())->index());
$router->get('/api/customer-groups/{id}', fn($id) => (new CustomerGroupApiController())->show((int) $id));

$router->get('/api/warehouses', fn() => (new WarehouseApiController())->index());
$router->get('/api/warehouses/{id}/locations', fn($id) => (new WarehouseApiController())->locations((int) $id));
$router->get('/api/stock', fn() => (new StockApiController())->index());
$router->post('/api/stock/in', fn() => (new StockApiController())->in());
$router->post('/api/stock/out', fn() => (new StockApiController())->out());
$router->post('/api/stock/transfer', fn() => (new StockApiController())->transfer());
$router->get('/api/pricing/effective', fn() => (new PricingApiController())->effective());

$router->get('/api/invoices', fn() => (new InvoiceApiController())->index());
$router->get('/api/invoices/{id}', fn($id) => (new InvoiceApiController())->show((int) $id));

$router->get('/api/partners', fn() => (new PartnerApiController())->index());
$router->get('/api/partners/tax/{tax}', fn($tax) => (new PartnerApiController())->showByTax(rawurldecode($tax)));
$router->put('/api/partners/tax/{tax}', fn($tax) => (new PartnerApiController())->upsertByTax(rawurldecode($tax)));
$router->get('/api/partners/{id}', fn($id) => (new PartnerApiController())->show((int) $id));
$router->post('/api/partners', fn() => (new PartnerApiController())->create());
$router->put('/api/partners/{id}', fn($id) => (new PartnerApiController())->update((int) $id));
$router->delete('/api/partners/{id}', fn($id) => (new PartnerApiController())->delete((int) $id));

$router->get('/api/orders', fn() => (new OrderApiController())->index());
$router->get('/api/orders/{id}', fn($id) => (new OrderApiController())->show((int) $id));
$router->post('/api/orders', fn() => (new OrderApiController())->create());
$router->put('/api/orders/{id}', fn($id) => (new OrderApiController())->update((int) $id));
$router->delete('/api/orders/{id}', fn($id) => (new OrderApiController())->delete((int) $id));

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
