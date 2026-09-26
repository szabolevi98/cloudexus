<?php

/**
 * Builds docs/openapi.json: the REST API described for programs — Postman,
 * Insomnia, a webshop's client generator — the way web/API.md describes it
 * for people. OpenAPI 3.1.
 *
 * Written here as PHP rather than as the JSON itself, so that the shapes many
 * endpoints share (the meta block, an error, a page of results) are said
 * once. The output is committed, like app.css, and served at
 * /api/openapi.json with the installation's own address in it.
 * tests/Unit/OpenApiTest.php fails when an /api route is missing from it, or
 * it names one that is not there.
 *
 * Usage: php bin/openapi.php
 */

$root = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Pieces
// ---------------------------------------------------------------------------

function ref(string $name): array
{
    return ['$ref' => '#/components/schemas/' . $name];
}

/**
 * An object. Rows come from the database with more columns than are named
 * here, so every object stays open to fields not listed.
 *
 * @param array<string, array> $properties
 * @param list<string> $required
 */
function obj(array $properties, array $required = [], ?string $description = null): array
{
    $out = ['type' => 'object', 'properties' => $properties === [] ? new stdClass() : $properties];

    if ($required !== []) {
        $out['required'] = $required;
    }

    return $description === null ? $out : ['description' => $description] + $out;
}

function str(?string $description = null, array $more = []): array
{
    return ['type' => 'string'] + ($description === null ? [] : ['description' => $description]) + $more;
}

function integer(?string $description = null): array
{
    return ['type' => 'integer'] + ($description === null ? [] : ['description' => $description]);
}

function number(?string $description = null): array
{
    return ['type' => 'number'] + ($description === null ? [] : ['description' => $description]);
}

function boolean(?string $description = null): array
{
    return ['type' => 'boolean'] + ($description === null ? [] : ['description' => $description]);
}

/** A decimal as the database gives it: "89900.00". */
function decimal(?string $description = null): array
{
    return str($description, ['pattern' => '^-?[0-9]+(\.[0-9]+)?$', 'examples' => ['89900.00']]);
}

/** A flag the database gives as 0 or 1. */
function flag(?string $description = null): array
{
    return ['type' => 'integer', 'enum' => [0, 1]] + ($description === null ? [] : ['description' => $description]);
}

function stamp(): array
{
    return str(null, ['examples' => ['2026-07-24 16:44:35']]);
}

function day(?string $description = null): array
{
    return str($description, ['format' => 'date', 'examples' => ['2026-07-20']]);
}

function nullable(array $schema): array
{
    if (isset($schema['$ref'])) {
        return ['oneOf' => [$schema, ['type' => 'null']]];
    }

    if (isset($schema['enum'])) {
        $schema['enum'][] = null;
    }

    return array_merge($schema, ['type' => [$schema['type'], 'null']]);
}

function listOf(array $items): array
{
    return ['type' => 'array', 'items' => $items];
}

function json(array $schema, string $description): array
{
    return ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
}

function body(array $schema): array
{
    return ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
}

/** {"data": item, "meta": {currency, language}} */
function one(array $item): array
{
    return obj(['data' => $item, 'meta' => ref('Meta')], ['data']);
}

/** {"data": [items], "meta": {page, …, currency, language}} */
function page(array $item): array
{
    return obj(['data' => listOf($item), 'meta' => ref('PageMeta')], ['data', 'meta']);
}

/** @return array<int|string, array> */
function errors(int ...$statuses): array
{
    $names = [403 => 'Forbidden', 404 => 'NotFound', 409 => 'Conflict', 422 => 'Unprocessable', 429 => 'TooManyRequests'];
    $out = [];

    foreach ($statuses as $status) {
        $out[(string) $status] = ['$ref' => '#/components/responses/' . $names[$status]];
    }

    return $out;
}

function p(string $name): array
{
    return ['$ref' => '#/components/parameters/' . $name];
}

function query(string $name, array $schema, string $description, bool $required = false): array
{
    return ['name' => $name, 'in' => 'query', 'required' => $required, 'schema' => $schema, 'description' => $description];
}

function path(string $name, array $schema, string $description): array
{
    return ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $schema, 'description' => $description];
}

/**
 * One operation. Every one but signing in and this description needs a
 * token, meets the rate limit and may be asked for another language; a
 * change takes an Idempotency-Key.
 *
 * @param array<int|string, array> $responses
 */
function op(string $method, string $tag, string $id, string $summary, string $description, array $responses, array $parameters = [], ?array $requestBody = null, bool $public = false): array
{
    $out = ['tags' => [$tag], 'operationId' => $id, 'summary' => $summary];

    if ($description !== '') {
        $out['description'] = $description;
    }

    if (!$public) {
        $parameters[] = p('language');
        if ($method !== 'get') {
            $parameters[] = p('IdempotencyKey');
        }
    }

    if ($parameters !== []) {
        $out['parameters'] = $parameters;
    }

    if ($requestBody !== null) {
        $out['requestBody'] = $requestBody;
    }

    $out['responses'] = $responses + ($public ? [] : ['401' => ['$ref' => '#/components/responses/Unauthorized']] + errors(429));
    ksort($out['responses'], SORT_STRING);

    if ($public) {
        $out['security'] = [];
    }

    return $out;
}

/** The filters every list takes, and its page. */
function listParameters(array ...$more): array
{
    return array_merge([p('page'), p('per_page'), p('q'), p('updated_since')], $more);
}

$deleted = json(obj(['data' => obj(['deleted' => ['const' => true], 'id' => integer()], ['deleted', 'id'])], ['data']), 'Deleted.');

// ---------------------------------------------------------------------------
// Shapes
// ---------------------------------------------------------------------------

$status = static fn(string $description): array => query('status', str(null, ['enum' => ['active', 'inactive']]), $description);

$schemas = [
    'Error' => obj([
        'error' => obj([
            'status' => integer('The HTTP status again.'),
            'message' => str('In English.'),
            'details' => ['description' => 'For a client to act on: which lines are short of stock, two_factor_required.'],
        ], ['status', 'message']),
    ], ['error']),
    'Currency' => obj(['code' => str(null, ['examples' => ['HUF']]), 'symbol' => str(), 'title' => str()], ['code', 'symbol', 'title'], 'The primary currency every amount is in. Amounts are never converted.'),
    'Language' => obj(['code' => str(null, ['examples' => ['en']]), 'name' => str(), 'default' => str('The default language\'s code, which a missing translation falls back to.')], ['code', 'name', 'default']),
    'Meta' => obj(['currency' => ref('Currency'), 'language' => ref('Language')]),
    'PageMeta' => obj([
        'page' => integer(),
        'per_page' => integer(),
        'total' => integer(),
        'total_pages' => integer(),
        'currency' => ref('Currency'),
        'language' => ref('Language'),
    ], ['page', 'per_page', 'total', 'total_pages']),
    'User' => obj([
        'id' => integer(),
        'username' => str(),
        'full_name' => str(),
        'email' => str(),
        'role' => str('The older split, kept for existing clients: admin means super admin.', ['enum' => ['admin', 'user']]),
        'role_code' => nullable(str()),
        'role_name' => nullable(str()),
        'permissions' => listOf(str(null, ['examples' => ['stock.move']])),
    ], ['id', 'username', 'full_name', 'permissions']),
    'Product' => obj([
        'id' => integer(),
        'sku' => str(),
        'barcode' => nullable(str()),
        'name' => str('In the language asked for.'),
        'short_description' => nullable(str()),
        'description' => nullable(str('HTML.')),
        'category_id' => nullable(integer()),
        'category_name' => nullable(str('In lists.')),
        'unit_id' => nullable(integer()),
        'unit' => nullable(str('The unit\'s code.')),
        'unit_name' => nullable(str()),
        'price' => decimal('Net.'),
        'sale_price' => nullable(decimal('Net; when set, the active price.')),
        'vat_rate' => decimal(),
        'min_stock' => decimal(),
        'is_active' => flag(),
        'is_webshop' => flag(),
        'width_mm' => nullable(integer()),
        'height_mm' => nullable(integer()),
        'depth_mm' => nullable(integer()),
        'weight_g' => nullable(integer()),
        'stock_qty' => ['type' => ['number', 'string'], 'description' => 'Current stock, all warehouses.'],
        'created_at' => stamp(),
        'updated_at' => stamp(),
    ], ['id', 'sku', 'name', 'price', 'is_active']),
    'ProductDetail' => ['allOf' => [ref('Product'), obj([
        'images' => listOf(obj(['id' => integer(), 'path' => str('A relative upload path or a full URL.'), 'is_primary' => flag(), 'sort_order' => integer()])),
        'attributes' => listOf(obj(['id' => integer(), 'parameter_id' => integer(), 'attr_name' => str(), 'attr_value' => str(), 'sort_order' => integer()])),
        'category_ids' => listOf(integer()),
        'related_ids' => listOf(integer()),
        'substitute_ids' => listOf(integer()),
        'group_prices' => ['type' => 'object', 'description' => 'By customer_group_id.', 'additionalProperties' => obj(['customer_group_id' => integer(), 'price' => decimal(), 'sale_price' => nullable(decimal())])],
    ])]],
    'ProductLookup' => obj([
        'id' => integer(),
        'sku' => str(),
        'barcode' => nullable(str()),
        'name' => str(),
        'unit' => nullable(str()),
        'unit_name' => nullable(str()),
        'min_stock' => decimal(),
        'matched_by' => str(null, ['enum' => ['barcode', 'sku']]),
        'stock_total' => decimal(),
        'stock' => listOf(obj([
            'warehouse_id' => integer(),
            'warehouse_name' => str(),
            'location_id' => nullable(integer()),
            'location_code' => nullable(str()),
            'quantity' => decimal(),
        ])),
    ], ['id', 'sku', 'name', 'matched_by', 'stock_total', 'stock']),
    'Category' => obj([
        'id' => integer(),
        'parent_id' => nullable(integer()),
        'name' => str('In the language asked for.'),
        'description' => nullable(str()),
        'is_active' => flag(),
        'created_at' => stamp(),
        'updated_at' => stamp(),
    ], ['id', 'name']),
    'Parameter' => obj(['id' => integer(), 'name' => str(), 'created_at' => stamp(), 'updated_at' => stamp()], ['id', 'name']),
    'Unit' => obj(['id' => integer(), 'code' => str(), 'name' => str(), 'sort_order' => integer()], ['id', 'code', 'name']),
    'CurrencyRate' => obj([
        'id' => integer(),
        'title' => str(),
        'code' => str(),
        'symbol' => str(),
        'value' => number('1 primary unit is this many of this currency: amount * value.'),
        'is_primary' => boolean(),
    ], ['id', 'code', 'value', 'is_primary']),
    'LanguageRow' => obj(['id' => integer(), 'name' => str(), 'code' => str(), 'sort_order' => integer(), 'is_active' => boolean(), 'is_default' => boolean()], ['id', 'code', 'name']),
    'CustomerGroup' => obj(['id' => integer(), 'name' => str(), 'description' => nullable(str()), 'partner_count' => integer('In lists.')], ['id', 'name']),
    'Warehouse' => obj(['id' => integer(), 'name' => str(), 'address' => nullable(str()), 'is_active' => flag()], ['id', 'name']),
    'Location' => obj([
        'id' => integer(),
        'warehouse_id' => integer(),
        'code' => str('Unique within the warehouse: what a shelf label says.'),
        'name' => nullable(str()),
        'is_active' => flag(),
        'stock_qty' => decimal('All products on it.'),
        'product_count' => integer(),
    ], ['id', 'warehouse_id', 'code']),
    'StockRow' => obj([
        'warehouse_id' => integer(),
        'warehouse_name' => str(),
        'location_id' => nullable(integer()),
        'location_code' => nullable(str()),
        'product_id' => integer(),
        'sku' => str(),
        'product_name' => str(),
        'unit' => nullable(str()),
        'quantity' => decimal('Stock in less stock out.'),
    ], ['warehouse_id', 'product_id', 'quantity']),
    'Invoice' => obj([
        'id' => integer(),
        'invoice_number' => str(),
        'invoice_type' => str(null, ['enum' => ['normal', 'storno']]),
        'storno_of_id' => nullable(integer()),
        'order_id' => nullable(integer()),
        'partner_id' => integer(),
        'partner_name' => str(),
        'status' => str(null, ['enum' => ['unpaid', 'paid', 'cancelled', 'storno']]),
        'issue_date' => day(),
        'fulfilment_date' => day(),
        'due_date' => day(),
        'payment_method' => str(null, ['enum' => ['transfer', 'cash', 'card', 'cod']]),
        'net_total' => decimal(),
        'vat_total' => decimal(),
        'total_amount' => decimal('Gross.'),
        'paid_amount' => decimal('What has been received; the balance is total_amount - paid_amount.'),
        'shipping_cost' => decimal(),
        'payment_cost' => decimal(),
        'extra_vat_rate' => decimal(),
        'seller_name' => str(),
        'seller_tax_number' => nullable(str()),
        'created_at' => stamp(),
        'updated_at' => stamp(),
    ], ['id', 'invoice_number', 'status', 'total_amount']),
    'InvoiceDetail' => ['allOf' => [ref('Invoice'), obj([
        'storno_by_id' => nullable(integer()),
        'items' => listOf(obj([
            'id' => integer(),
            'product_id' => nullable(integer()),
            'sku' => nullable(str()),
            'product_name' => str(),
            'unit' => nullable(str()),
            'quantity' => decimal(),
            'unit_price' => decimal('Net.'),
            'vat_rate' => decimal(),
            'net_amount' => decimal(),
            'vat_amount' => decimal(),
            'gross_amount' => decimal(),
        ])),
        'vat_summary' => listOf(obj(['rate' => number(), 'net' => number(), 'vat' => number(), 'gross' => number()])),
    ], ['items'])]],
    'EffectivePrice' => obj([
        'price' => number('The net unit price: the lowest of the list price, the sale price and the price rules that fit.'),
        'is_sale' => boolean(),
        'list_price' => number(),
        'rule' => nullable(obj(['id' => integer(), 'name' => str()], ['id', 'name'])),
    ], ['price', 'is_sale', 'list_price']),
    'Address' => obj([
        'id' => integer('In answers only.'),
        'country' => str(null, ['default' => 'Magyarország']),
        'postal_code' => str(),
        'city' => str(),
        'street' => str(),
        'note' => nullable(str()),
    ], ['postal_code', 'city', 'street']),
    'Partner' => obj([
        'id' => integer(),
        'type' => str(null, ['enum' => ['customer', 'supplier', 'both']]),
        'customer_group_id' => nullable(integer()),
        'customer_group_name' => nullable(str()),
        'name' => str(),
        'tax_number' => nullable(str()),
        'email' => nullable(str()),
        'phone' => nullable(str()),
        'is_active' => flag(),
        'created_at' => stamp(),
        'updated_at' => stamp(),
    ], ['id', 'type', 'name', 'is_active']),
    'PartnerDetail' => ['allOf' => [ref('Partner'), obj(['addresses' => listOf(ref('Address'))], ['addresses'])]],
    'PartnerInput' => obj([
        'name' => str('Required on POST.'),
        'type' => str(null, ['enum' => ['customer', 'supplier', 'both'], 'default' => 'customer']),
        'tax_number' => str(),
        'email' => str(),
        'phone' => str(),
        'customer_group_id' => integer('0 or none: no group.'),
        'is_active' => boolean(null) + ['default' => true],
        'addresses' => listOf(ref('Address')) + ['description' => 'On PUT, given: replaces every address; left out: they stay. One without city, postal_code and street is not saved.'],
    ]),
    'Order' => obj([
        'id' => integer(),
        'order_number' => str(),
        'partner_id' => integer(),
        'partner_name' => str(),
        'status' => str(null, ['enum' => ['draft', 'confirmed', 'invoiced', 'cancelled']]),
        'order_date' => day(),
        'shipping_address_id' => nullable(integer()),
        'billing_address_id' => nullable(integer()),
        'shipping_cost' => decimal(),
        'payment_cost' => decimal(),
        'total_amount' => decimal('Items + shipping_cost + payment_cost.'),
        'created_at' => stamp(),
        'updated_at' => stamp(),
    ], ['id', 'order_number', 'partner_id', 'status', 'total_amount']),
    'OrderDetail' => ['allOf' => [ref('Order'), obj([
        'shipping_country' => nullable(str()), 'shipping_postal_code' => nullable(str()), 'shipping_city' => nullable(str()), 'shipping_street' => nullable(str()), 'shipping_note' => nullable(str()),
        'billing_country' => nullable(str()), 'billing_postal_code' => nullable(str()), 'billing_city' => nullable(str()), 'billing_street' => nullable(str()), 'billing_note' => nullable(str()),
        'items' => listOf(obj([
            'id' => integer(),
            'product_id' => integer(),
            'sku' => nullable(str()),
            'product_name' => str(),
            'unit' => nullable(str()),
            'quantity' => decimal(),
            'unit_price' => decimal('Net.'),
            'line_total' => decimal(),
        ])),
    ], ['items'])]],
    'OrderInput' => obj([
        'partner_id' => integer('Required on POST: an existing partner.'),
        'order_date' => day('Today when not given.'),
        'status' => str('invoiced is set by issuing the invoice, and cannot be sent.', ['enum' => ['draft', 'confirmed', 'cancelled'], 'default' => 'confirmed']),
        'shipping_address_id' => integer('One of the partner\'s addresses; 0 or none: none.'),
        'billing_address_id' => integer(),
        'shipping_cost' => number('Net.'),
        'payment_cost' => number('Net.'),
        'items' => listOf(obj(['product_id' => integer(), 'quantity' => number(), 'unit_price' => number('Net, in the primary currency.')], ['product_id', 'quantity', 'unit_price'])) + ['description' => 'Required on POST, at least one line. On PUT, given: replaces the lines; left out: they stay.'],
    ]),
    'BookingItem' => obj([
        'product_id' => integer(),
        'quantity' => ['type' => ['number', 'string'], 'description' => 'Positive, at most 3 decimals.'],
        'location_id' => nullable(integer('Overrides the request\'s default location.')),
    ], ['product_id', 'quantity']),
    'StockBooking' => obj([
        'warehouse_id' => integer('An active warehouse.'),
        'location_id' => nullable(integer('The default location of every line.')),
        'note' => str('At most 200 characters; "Mobil app" when not given.'),
        'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => ref('BookingItem')],
    ], ['warehouse_id', 'items']),
    'StockTransfer' => obj([
        'from_warehouse_id' => integer(),
        'from_location_id' => nullable(integer()),
        'to_warehouse_id' => integer('Another warehouse than the source.'),
        'to_location_id' => nullable(integer()),
        'note' => str(),
        'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => ref('BookingItem')],
    ], ['from_warehouse_id', 'to_warehouse_id', 'items']),
    'Booked' => obj([
        'type' => str(null, ['enum' => ['in', 'out', 'transfer']]),
        'warehouse' => obj(['id' => integer(), 'name' => str()]),
        'note' => str(),
        'created_by' => obj(['id' => integer(), 'full_name' => str()], ['id', 'full_name']),
        'movements' => listOf(obj([
            'id' => integer('In and out bookings.'),
            'out_movement_id' => integer('Transfers.'),
            'in_movement_id' => integer('Transfers.'),
            'product_id' => integer(),
            'sku' => str(),
            'product_name' => str(),
            'unit' => nullable(str()),
            'location_id' => nullable(integer()),
            'location_code' => nullable(str()),
            'quantity' => decimal(),
        ])),
    ], ['type', 'created_by', 'movements']),
];

$errorResponse = static fn(string $description): array => json(ref('Error'), $description);

$components = [
    'securitySchemes' => [
        'token' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An integration token (API → API users) or a user token from POST /auth/login (cxu_…).'],
        'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key', 'description' => 'The same token, for servers that strip the Authorization header.'],
    ],
    'parameters' => [
        'id' => path('id', ['type' => 'integer'], 'The record\'s id.'),
        'page' => ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
        'per_page' => ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
        'q' => query('q', str(), 'Free-text search; the fields searched differ per list.'),
        'updated_since' => query('updated_since', str(null, ['examples' => ['2026-07-19 00:00:00']]), 'Only records changed since then: for a delta sync.'),
        'language' => query('language', str(null, ['examples' => ['en']]), 'The language translatable text comes in (see GET /languages); a missing translation falls back to the default. An unknown code is 422.'),
        'IdempotencyKey' => [
            'name' => 'Idempotency-Key',
            'in' => 'header',
            'required' => false,
            'schema' => str(null, ['pattern' => '^[A-Za-z0-9_-]{8,64}$']),
            'description' => 'Makes a resend safe: the same key with the same request, from the same token\'s owner within 7 days, gets the first answer again (with Idempotent-Replayed: true) and is not done again. 409 while the first is still being worked on; 422 for the same key with another request.',
        ],
    ],
    'responses' => [
        'Unauthorized' => $errorResponse('Invalid or missing API token.'),
        'Forbidden' => $errorResponse('The endpoint needs a user token, or the user\'s role lacks the permission.'),
        'NotFound' => $errorResponse('Not found.'),
        'Conflict' => $errorResponse('A conflict: an invoiced order, or the same request still being worked on under the same Idempotency-Key.'),
        'Unprocessable' => $errorResponse('The request does not make sense; details may say which lines.'),
        'TooManyRequests' => $errorResponse('Rate limit exceeded: 60 requests a minute per token (per user for user tokens).'),
    ],
    'schemas' => $schemas,
];

// ---------------------------------------------------------------------------
// The operations
// ---------------------------------------------------------------------------

$paths = [];
$add = static function (string $path, string $method, array $operation) use (&$paths): void {
    $paths[$path][$method] = $operation;
};

$add('/openapi.json', 'get', op('get', 'Signing in', 'openapi', 'This description', 'No token needed.', [
    '200' => ['description' => 'The OpenAPI document, with this installation\'s address.', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
], public: true));

$add('/auth/login', 'post', op('post', 'Signing in', 'login', 'Sign a user in', 'Returns a user token (cxu_…), the only time it can be read. With two-step sign-in on, the password alone is 403 with details.two_factor_required; send the same body again with code. 10 failures from one IP in 15 minutes: 429.', [
    '201' => json(obj(['data' => obj(['token' => str(), 'expires_at' => stamp(), 'user' => ref('User')], ['token', 'expires_at', 'user'])], ['data']), 'Signed in.'),
    '401' => $errorResponse('Wrong username or password, or a wrong code.'),
    '403' => $errorResponse('Two-step sign-in is on: send code.'),
] + errors(422, 429), [], body(obj([
    'username' => str('The username or the email address.'),
    'password' => str(),
    'device_name' => str('Tells the user\'s devices apart.'),
    'code' => str('The two-step code, or a recovery code.'),
], ['username', 'password'])), public: true));
$add('/auth/me', 'get', op('get', 'Signing in', 'me', 'The user behind a user token', 'For an app to check its stored token at start-up; 403 with an integration token.', [
    '200' => json(obj(['data' => obj(['user' => ref('User'), 'expires_at' => stamp()], ['user', 'expires_at'])], ['data']), 'The user.'),
] + errors(403)));
$add('/auth/logout', 'post', op('post', 'Signing in', 'logout', 'Revoke the user token', 'This device only; 403 with an integration token.', [
    '200' => json(obj(['data' => obj(['signed_out' => ['const' => true]], ['signed_out'])], ['data']), 'Signed out.'),
] + errors(403)));

$add('/products', 'get', op('get', 'Catalog', 'products', 'Products', 'q searches SKU, name and barcode.', ['200' => json(page(ref('Product')), 'A page.')] + errors(422), listParameters(
    query('category_id', ['type' => 'integer'], 'One category.'),
    $status('Active or inactive only.'),
)));
$add('/products/lookup', 'get', op('get', 'Catalog', 'lookupProduct', 'A scanned code', 'Matched against the barcode first, the SKU second; active products only. A query parameter, as scanned codes can contain a slash.', [
    '200' => json(obj(['data' => ref('ProductLookup')], ['data']), 'The product, and where its stock is.'),
] + errors(404, 422), [query('code', str(), 'What the scanner read.', true)]));
$add('/products/sku/{sku}', 'get', op('get', 'Catalog', 'productBySku', 'A product by SKU', '', ['200' => json(one(ref('ProductDetail')), 'The product.')] + errors(404), [path('sku', str(), 'The SKU.')]));
$add('/products/{id}', 'get', op('get', 'Catalog', 'product', 'A product', '', ['200' => json(one(ref('ProductDetail')), 'The product.')] + errors(404), [p('id')]));

$add('/categories', 'get', op('get', 'Catalog', 'categories', 'Categories', 'A tree by parent_id, ordered by the translated name.', ['200' => json(page(ref('Category')), 'A page.')] + errors(422), listParameters()));
$add('/categories/{id}', 'get', op('get', 'Catalog', 'category', 'A category', '', ['200' => json(one(ref('Category')), 'The category.')] + errors(404), [p('id')]));
$add('/parameters', 'get', op('get', 'Catalog', 'parameters', 'Parameters', 'The master list product parameters take their name from.', ['200' => json(page(ref('Parameter')), 'A page.')] + errors(422), listParameters()));
$add('/parameter-names', 'get', op('get', 'Catalog', 'parameterNames', 'Parameters (old name)', 'Deprecated: the same as GET /parameters.', ['200' => json(page(ref('Parameter')), 'A page.')] + errors(422), listParameters()) + ['deprecated' => true]);
$add('/units', 'get', op('get', 'Catalog', 'units', 'Units of measure', '', ['200' => json(page(ref('Unit')), 'A page.')] + errors(422), listParameters()));
$add('/currencies', 'get', op('get', 'Master data', 'currencies', 'Currencies and exchange rates', '', ['200' => json(page(ref('CurrencyRate')), 'A page.')] + errors(422), listParameters()));
$add('/languages', 'get', op('get', 'Master data', 'languages', 'Languages', '', ['200' => json(page(ref('LanguageRow')), 'A page.')] + errors(422), listParameters()));
$add('/customer-groups', 'get', op('get', 'Master data', 'customerGroups', 'Customer groups', '', ['200' => json(page(ref('CustomerGroup')), 'A page.')] + errors(422), listParameters()));
$add('/customer-groups/{id}', 'get', op('get', 'Master data', 'customerGroup', 'A customer group', '', ['200' => json(one(ref('CustomerGroup')), 'The group.')] + errors(404), [p('id')]));

$add('/warehouses', 'get', op('get', 'Stock', 'warehouses', 'Warehouses', '', ['200' => json(page(ref('Warehouse')), 'A page.')] + errors(422), listParameters($status('Active or inactive only.'))));
$add('/warehouses/{id}/locations', 'get', op('get', 'Stock', 'locations', 'A warehouse\'s storage locations', 'For what is on a shelf, GET /stock?location_id=.', ['200' => json(page(ref('Location')), 'A page.')] + errors(404, 422), [
    p('id'), p('page'), p('per_page'), p('q'),
    query('code', str(), 'Exactly this code: a scanned shelf label.'),
    $status('Active or inactive only.'),
]));
$add('/stock', 'get', op('get', 'Stock', 'stock', 'Current stock', 'By warehouse, location and product.', ['200' => json(page(ref('StockRow')), 'A page.')] + errors(422), [
    p('page'), p('per_page'), p('q'),
    query('warehouse_id', ['type' => 'integer'], 'One warehouse.'),
    query('location_id', ['type' => 'integer'], 'One location.'),
    query('product_id', ['type' => 'integer'], 'One product.'),
]));
foreach (['in' => 'Book stock in', 'out' => 'Book stock out'] as $type => $summary) {
    $add('/stock/' . $type, 'post', op('post', 'Stock', 'stock' . ucfirst($type), $summary, 'Needs a user token whose role has stock.move. All or nothing' . ($type === 'out' ? '; checked against the warehouse\'s stock, one booking after the other' : '') . '.', [
        '201' => json(obj(['data' => ref('Booked')], ['data']), 'Booked.'),
    ] + errors(403, 422), [], body(ref('StockBooking'))));
}
$add('/stock/transfer', 'post', op('post', 'Stock', 'stockTransfer', 'Move stock between warehouses', 'Needs a user token whose role has stock.move. Each line is an out movement and an in movement. All or nothing.', [
    '201' => json(obj(['data' => ref('Booked')], ['data']), 'Booked.'),
] + errors(403, 422), [], body(ref('StockTransfer'))));
$add('/pricing/effective', 'get', op('get', 'Sales', 'effectivePrice', 'The net unit price for a sales line', 'Group price, sale price and price rules.', ['200' => json(one(ref('EffectivePrice')), 'The price.')] + errors(404, 422), [
    query('product_id', ['type' => 'integer'], 'The product.', true),
    query('partner_id', ['type' => 'integer'], 'Without it only the product\'s own price and the rules for everyone apply.'),
    query('quantity', ['type' => 'number', 'default' => 1], 'Positive.'),
    query('date', day(), 'The document\'s date; today when not given.'),
]));

$add('/invoices', 'get', op('get', 'Sales', 'invoices', 'Invoices', 'Read only.', ['200' => json(page(ref('Invoice')), 'A page.')] + errors(422), listParameters(
    query('partner_id', ['type' => 'integer'], 'One partner.'),
    query('status', str(null, ['enum' => ['unpaid', 'paid', 'cancelled', 'storno']]), 'One status.'),
    query('date_from', day(), 'Issued on or after.'),
    query('date_to', day(), 'Issued on or before.'),
)));
$add('/invoices/{id}', 'get', op('get', 'Sales', 'invoice', 'An invoice, with its lines', '', ['200' => json(one(ref('InvoiceDetail')), 'The invoice.')] + errors(404), [p('id')]));

$tax = path('tax', str(null, ['examples' => ['12345678-2-42']]), 'The tax number.');
$add('/partners', 'get', op('get', 'Partners', 'partners', 'Partners', '', ['200' => json(page(ref('Partner')), 'A page.')] + errors(422), listParameters(
    query('type', str(null, ['enum' => ['customer', 'supplier', 'both']]), 'One type.'),
    $status('Active or inactive only.'),
    query('customer_group_id', ['type' => 'integer'], 'One customer group.'),
)));
$add('/partners', 'post', op('post', 'Partners', 'createPartner', 'Create a partner', '', ['201' => json(obj(['data' => ref('PartnerDetail')], ['data']), 'Created.')] + errors(403, 409, 422), [], body(ref('PartnerInput'))));
$add('/partners/tax/{tax}', 'get', op('get', 'Partners', 'partnerByTax', 'A partner by tax number', '', ['200' => json(obj(['data' => ref('PartnerDetail')], ['data']), 'The partner.')] + errors(404), [$tax]));
$add('/partners/tax/{tax}', 'put', op('put', 'Partners', 'upsertPartnerByTax', 'Update or create by tax number', 'Updated if the tax number exists (200), created otherwise (201).', [
    '200' => json(obj(['data' => ref('PartnerDetail')], ['data']), 'Updated.'),
    '201' => json(obj(['data' => ref('PartnerDetail')], ['data']), 'Created.'),
] + errors(403, 409, 422), [$tax], body(ref('PartnerInput'))));
$add('/partners/{id}', 'get', op('get', 'Partners', 'partner', 'A partner, with its addresses', '', ['200' => json(obj(['data' => ref('PartnerDetail')], ['data']), 'The partner.')] + errors(404), [p('id')]));
$add('/partners/{id}', 'put', op('put', 'Partners', 'updatePartner', 'Update a partner', '', ['200' => json(obj(['data' => ref('PartnerDetail')], ['data']), 'Updated.')] + errors(403, 404, 409, 422), [p('id')], body(ref('PartnerInput'))));
$add('/partners/{id}', 'delete', op('delete', 'Partners', 'deletePartner', 'Delete a partner', '', ['200' => $deleted] + errors(403, 404, 409), [p('id')]));

$add('/orders', 'get', op('get', 'Sales', 'orders', 'Orders', '', ['200' => json(page(ref('Order')), 'A page.')] + errors(422), listParameters(
    query('partner_id', ['type' => 'integer'], 'One partner.'),
    query('status', str(null, ['enum' => ['draft', 'confirmed', 'invoiced', 'cancelled']]), 'One status.'),
    query('date_from', day(), 'Ordered on or after.'),
    query('date_to', day(), 'Ordered on or before.'),
)));
$add('/orders', 'post', op('post', 'Sales', 'createOrder', 'Place an order', 'The order number is made by the server, and so is the total.', ['201' => json(one(ref('OrderDetail')), 'Placed.')] + errors(403, 409, 422), [], body(ref('OrderInput'))));
$add('/orders/{id}', 'get', op('get', 'Sales', 'order', 'An order, with its lines and addresses', '', ['200' => json(one(ref('OrderDetail')), 'The order.')] + errors(404), [p('id')]));
$add('/orders/{id}', 'put', op('put', 'Sales', 'updateOrder', 'Update an order', 'An invoiced order is read only: 409.', ['200' => json(one(ref('OrderDetail')), 'Updated.')] + errors(403, 404, 409, 422), [p('id')], body(ref('OrderInput'))));
$add('/orders/{id}', 'delete', op('delete', 'Sales', 'deleteOrder', 'Delete an order', 'Only a draft or cancelled order that was never invoiced; otherwise 409.', ['200' => $deleted] + errors(403, 404, 409), [p('id')]));

ksort($paths);

$document = [
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'Cloudexus API',
        'version' => '1',
        'summary' => 'The catalog, stock, prices, invoices, partners and orders, and the warehouse app\'s bookings.',
        'description' => 'JSON in and out; every message in English. Amounts are in the primary currency (meta.currency); translatable text comes in the language asked for (meta.language). The whole description, for people, is web/API.md — and inside the application under API → API documentation.',
        'license' => ['name' => 'AGPL-3.0-only', 'identifier' => 'AGPL-3.0-only'],
    ],
    'servers' => [['url' => 'https://cloudexus.example/api']],
    'security' => [['token' => []], ['apiKey' => []]],
    'tags' => array_map(static fn(string $t): array => ['name' => $t], ['Signing in', 'Catalog', 'Master data', 'Stock', 'Sales', 'Partners']),
    'paths' => $paths,
    'components' => $components,
];

file_put_contents($root . '/docs/openapi.json', json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

printf('Built docs/openapi.json: %d paths, %d operations, %d schemas.%s', count($paths), array_sum(array_map('count', $paths)), count($schemas), PHP_EOL);
