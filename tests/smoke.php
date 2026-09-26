<?php

/*
 * Walks a running installation over HTTP, the way a person would: signs in
 * through the form, opens every main page, checks the CSV export and the
 * permission gate, then signs out. Any page that is not 200, or that shows a
 * PHP or Twig error, fails the run.
 *
 *   php tests/smoke.php --url=http://127.0.0.1:8080 --user=admin --password=secret
 *
 * CI runs it against a freshly migrated and seeded database.
 */

$options = getopt('', ['url:', 'user:', 'password:']);
$base = rtrim((string) ($options['url'] ?? 'http://127.0.0.1:8080'), '/');
$user = (string) ($options['user'] ?? 'admin');
$password = (string) ($options['password'] ?? '');

$jar = tempnam(sys_get_temp_dir(), 'cx-smoke');
$failures = 0;

/** @return array{int, string, string} status, body, content type */
function request(string $url, string $jar, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [$status, $body, $type];
}

function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

function token(string $html): string
{
    preg_match('/name="_token" value="([^"]+)"/', $html, $m) || preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

// Sign in through the form.
[, $loginPage] = request("$base/login", $jar);
[$status] = request("$base/login", $jar, ['_token' => token($loginPage), 'username' => $user, 'password' => $password]);
check($status === 302, 'sign-in redirects');
[$status, $body] = request("$base/dashboard", $jar);
check($status === 200 && str_contains($body, 'cx-sidebar'), 'signed in: the dashboard opens');

$pages = [
    '/dashboard', '/products', '/products/create', '/categories', '/partners', '/partners/create', '/customer-groups',
    '/price-rules', '/price-rules/create', '/warehouses', '/locations', '/stock', '/stock/in', '/stock/out',
    '/stock/transfer', '/stock/barcode', '/stocktaking', '/stocktaking/create', '/orders', '/orders/create',
    '/invoices', '/invoices/create', '/purchase-orders', '/purchase-orders/create', '/incoming-invoices',
    '/incoming-invoices/create', '/cash', '/cash/create', '/reports/aging', '/reports/aging?type=payables',
    '/todos', '/todos/week', '/deals', '/deals/create', '/reports/dormant', '/reports/crm', '/users', '/users/create', '/roles', '/roles/list', '/audit', '/settings/company', '/parameters',
    '/units', '/currencies', '/languages', '/api-users', '/api-logs', '/api-docs', '/profile',
];
foreach ($pages as $page) {
    [$status, $body] = request($base . $page, $jar);
    $error = preg_match('/Fatal error|Warning: |Notice: |Deprecated: |Twig\\\\Error|Uncaught/', $body, $m) ? ' — ' . $m[0] : '';
    check($status === 200 && $error === '', sprintf('%-28s %d%s', $page, $status, $error));
}

// The first invoice and incoming invoice, if the data has them.
foreach (['/invoices/1', '/invoices/1/print', '/incoming-invoices/1', '/orders/1', '/partners/1'] as $page) {
    [$status] = request($base . $page, $jar);
    check(in_array($status, [200, 302], true), sprintf('%-28s %d', $page, $status));
}

[$status, $csv, $type] = request("$base/reports/aging/export", $jar);
check($status === 200 && str_starts_with($type, 'text/csv') && substr_count($csv, "\n") >= 1, 'aging CSV export');

[$status] = request("$base/api/products", $jar);
check($status === 401, 'the API wants a token, not the session');

[$status, $openapi] = request("$base/api/openapi.json", $jar);
$described = json_decode($openapi, true);
check($status === 200 && ($described['servers'][0]['url'] ?? '') === "$base/api" && isset($described['paths']['/products']), "the OpenAPI description, with this installation's address");

// Sign out with the form's POST; afterwards the pages send back to sign-in.
[, $dashboard] = request("$base/dashboard", $jar);
[$status] = request("$base/logout", $jar, ['_token' => token($dashboard)]);
check($status === 302, 'sign-out');
[$status] = request("$base/dashboard", $jar);
check($status === 302, 'signed out: the dashboard sends to sign-in');

@unlink($jar);
echo $failures === 0 ? "\nAll good.\n" : "\n$failures failure(s).\n";
exit($failures === 0 ? 0 : 1);
