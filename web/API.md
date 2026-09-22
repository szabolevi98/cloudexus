# Cloudexus REST API

REST API for external integrations (e.g. webshop sync) and the mobile / PDA warehouse app.
JSON in and out, token-based authentication.

## Basics

- **Base URL:** `https://<domain>/api` (e.g. `https://cloudexus.levente.net/api`)
- **Format:** every response is JSON (`Content-Type: application/json; charset=utf-8`)
- **Encoding:** UTF-8

## Authentication

Every request requires an API token in the `Authorization` header:

```
Authorization: Bearer <token>
```

There are two kinds of token:

- **Integration token** — for a system such as a webshop. Managed in the admin UI under
  **API → API users** (create, regenerate, enable/disable, delete).
- **User token** — for a person, e.g. a warehouse worker signed in to the mobile / PDA app.
  Issued by `POST /api/auth/login` with the user's own username and password (see
  [Authentication endpoints](#authentication-endpoints)). User tokens start with `cxu_`.

Either kind grants full access to the endpoints below (there is no per-token permission
level), except that **stock movements can only be booked with a user token**, so every
movement names the person who made it. If the server strips the standard `Authorization`
header, the `X-Api-Key: <token>` header can be used instead.

A missing or invalid token returns `401`:

```json
{ "error": { "status": 401, "message": "Invalid or missing API token." } }
```

### Authentication endpoints

| Method | Path | Description |
|---|---|---|
| POST | `/api/auth/login` | Sign in with username (or e-mail) and password; returns a user token. No token needed |
| GET | `/api/auth/me` | The user behind the current user token |
| POST | `/api/auth/logout` | Revoke the current user token (this device only) |

**Login body:**

```json
{ "username": "kovacs.anna", "password": "••••••••", "device_name": "Zebra TC21 #3" }
```

`device_name` is optional and only helps tell a user's devices apart. Response (`201`):

```json
{
  "data": {
    "token": "cxu_3f9c…",
    "expires_at": "2026-12-21 08:00:00",
    "user": { "id": 7, "username": "kovacs.anna", "full_name": "Kovács Anna", "email": "anna@example.com", "role": "user" }
  }
}
```

- The raw token is returned **only here**; the server stores just its hash. Keep it in the
  device's secure storage.
- The token expires after 90 days **without use**: every request moves `expires_at` forward.
- A wrong username or password returns `401` (the message does not say which was wrong).
  After 10 failed attempts from one IP address within 15 minutes, further attempts return
  `429` until the window passes.
- A user token stops working as soon as its user is deactivated, and changing the user's
  password signs them out on every device.
- `GET /api/auth/me` and `POST /api/auth/logout` return `403` when called with an
  integration token.

## Rate limiting

Each API token is limited to 60 requests per rolling minute (for user tokens, the limit is
per user, shared across their devices). Every response carries:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 47
```

Exceeding the limit returns `429`:

```json
{ "error": { "status": 429, "message": "Rate limit exceeded. Try again later." } }
```

## Pagination

List endpoints use offset-based pagination:

- `?page=1` — page number (default: 1)
- `?per_page=50` — page size (default: 50, maximum: 200)

The response has a `data` + `meta` shape:

```json
{
  "data": [ { "...": "..." } ],
  "meta": {
    "page": 1, "per_page": 50, "total": 8423, "total_pages": 169,
    "currency": { "code": "HUF", "symbol": "Ft", "title": "Forint" }
  }
}
```

A single-item response is `{ "data": { ... }, "meta": { "currency": { ... } } }`.

## Language

Translatable text (product name and descriptions, category, unit and parameter names,
parameter values) is stored per language. Pick one with the optional `language`
parameter on any endpoint:

- `?language=en` — return translatable text in that language
- omitted or empty — the installation's default language
- an unknown code returns `422`

A record with no translation in the requested language falls back to the **default**
language, so a product is never returned nameless. `meta.language` reports what you got:

```json
{ "meta": { "language": { "code": "en", "name": "English", "default": "hu" } } }
```

Available languages come from [`GET /api/languages`](#other-master-data).

**Document lines are not translated.** Order and invoice lines store the product name,
SKU and unit as they were when the line was saved, in the default language, so an issued
document never changes because of a language switch or a later rename. `?language=` has
no effect on them.

Partner and order data is not translatable at all.

## Currency

Every monetary amount in every response is expressed in the installation's **primary
currency** — amounts are never converted, and no request parameter changes them. The
`meta.currency` block tells you which currency that is:

```json
{ "meta": { "currency": { "code": "HUF", "symbol": "Ft", "title": "Forint" } } }
```

It is present on all list responses, and on the single-item responses that carry amounts
(products, invoices, orders, pricing). To convert on your side, read the rates from
[`GET /api/currencies`](#other-master-data).

## Filters

Available on every list endpoint:

- `?q=` — free-text search (searched fields vary per endpoint)
- `?updated_since=YYYY-MM-DD HH:MM:SS` — only records changed since the given time
  (for delta sync; every record has both a `created_at` and an `updated_at` field, maintained
  automatically by the database)
- `?language=<code>` — the language translatable text is returned in (see
  [Language](#language)); empty or omitted means the default language

## Error format

All API messages (including error messages) are in **English**:

```json
{ "error": { "status": 404, "message": "Product not found." } }
```

Status codes used: `200` OK, `201` created, `400/422` bad request,
`401` authentication missing/invalid, `403` the endpoint needs a user token,
`404` resource or endpoint not found, `429` rate limit exceeded, `500` unexpected server error.
Every error, including an unknown endpoint and a server error, has this JSON shape.

---

# Endpoints

## Read-only resources (GET)

These resources are **read-only** via the API; they can be created/edited/deleted in the
admin UI.

### Products

| Method | Path | Description |
|---|---|---|
| GET | `/api/products` | Product list. Filters: `q` (SKU/name/barcode), `category_id`, `status` (`active`/`inactive`), `updated_since` |
| GET | `/api/products/{id}` | Single product, full data (by ID) |
| GET | `/api/products/sku/{sku}` | Single product, full data (by SKU) |
| GET | `/api/products/lookup?code=` | Scanned code → active product with its stock per warehouse/location (see [Product lookup](#product-lookup-scanning)) |

Example response (`GET /api/products/1`) — all fields of the detailed product:

```json
{
  "data": {
    "id": 1,
    "sku": "PRD-0001",
    "barcode": "5991269759143",
    "name": "Városi kerékpár 26\"",
    "short_description": "Short product description.",
    "description": "Detailed (HTML) product description.",
    "category_id": 1,
    "unit_id": 2,
    "unit": "doboz",
    "unit_name": "doboz",
    "price": "89900.00",
    "sale_price": null,
    "vat_rate": "27.00",
    "min_stock": "237.000",
    "is_active": 1,
    "is_webshop": 1,
    "width_mm": 512,
    "height_mm": 756,
    "depth_mm": 320,
    "weight_g": 17460,
    "created_at": "2026-07-24 16:44:35",
    "updated_at": "2026-07-24 16:44:35",
    "stock_qty": 220,
    "images": [
      { "id": 10, "product_id": 1, "path": "assets/uploads/products/abc.jpg", "is_primary": 1, "sort_order": 0 }
    ],
    "attributes": [
      { "id": 1886, "product_id": 1, "parameter_id": 1, "attr_name": "Gyártó", "attr_value": "Generic", "sort_order": 0 },
      { "id": 1887, "product_id": 1, "parameter_id": 3, "attr_name": "Garancia", "attr_value": "36 hónap", "sort_order": 1 }
    ],
    "category_ids": [1, 5],
    "related_ids": [4, 6],
    "substitute_ids": [2],
    "group_prices": {
      "1": { "customer_group_id": 1, "price": "3650.00", "sale_price": null },
      "3": { "customer_group_id": 3, "price": "3600.00", "sale_price": "3060.00" }
    }
  },
  "meta": {
    "currency": { "code": "HUF", "symbol": "Ft", "title": "Forint" }
  }
}
```

Fields: `price`/`sale_price` are net prices (if `sale_price` is set, that is the active price);
`stock_qty` is the aggregated current stock; `images` are the product images (`path` may be a
relative upload path or a full URL); `category_ids`
lists all assigned categories; `related_ids`/`substitute_ids` are the related/substitute
products; `group_prices` are the customer-group prices keyed by `customer_group_id` (a fixed
`price` plus an optional `sale_price`). All amounts are in the primary currency named by
`meta.currency` — see [Currency](#currency). Note: text values that are actual data (product
name, description, attribute values) are returned as stored and are not translated.

**Master-data references.** The unit of measure and the product parameters are foreign keys to
their own master tables, and the response carries both the id and the resolved text:

- `unit_id` points at [`/api/units`](#other-master-data); `unit` is that unit's code and
  `unit_name` its full name. `unit_id` may be `null` if no unit is set.
- Inside `attributes`, `parameter_id` points at [`/api/parameters`](#other-master-data) and
  `attr_name` is that parameter's name; `attr_value` is the free-text value. A product can
  carry a given parameter only once.

The `unit`, `attr_name` and `attr_value` keys are unchanged from earlier versions, so existing
integrations keep working — `unit_id` and `parameter_id` are additions.

`name`, `short_description`, `description`, `unit_name` and the `attributes[].attr_name` /
`attr_value` values are returned in the language selected by `?language=` (see
[Language](#language)), falling back to the default language where a translation is missing.

#### Product lookup (scanning)

`GET /api/products/lookup?code=5995323785398` resolves what a barcode scanner read. The code
is matched against the **barcode** first and the **SKU** second (if one product's barcode
equals another's SKU, the barcode wins); only active products are found. The code is a query
parameter rather than part of the path because scanned codes can contain `/`. Response:

```json
{
  "data": {
    "id": 12,
    "sku": "PRD-0012",
    "barcode": "5995323785398",
    "name": "24\" monitor",
    "unit": "szett",
    "unit_name": "szett",
    "min_stock": "0.000",
    "matched_by": "barcode",
    "stock_total": "243.000",
    "stock": [
      { "warehouse_id": 1, "warehouse_name": "Központi raktár", "location_id": 24, "location_code": "B-03-04", "quantity": "134.000" },
      { "warehouse_id": 1, "warehouse_name": "Központi raktár", "location_id": null, "location_code": null, "quantity": "42.000" }
    ]
  }
}
```

- `matched_by` is `barcode` or `sku`.
- `stock` lists every warehouse+location with non-zero stock; stock booked without a
  location is under `location_id: null`. `stock_total` is their sum.
- No match returns `404`; a missing `code` returns `422`.

### Categories

| Method | Path | Description |
|---|---|---|
| GET | `/api/categories` | Category list (tree, `parent_id`). Filters: `q`, `updated_since` |
| GET | `/api/categories/{id}` | Single category |

A category carries a translated `name` and `description`, a `parent_id` (`null` at the top
level) and an `is_active` flag. Example response (`GET /api/categories/1?language=en`):

```json
{
  "data": {
    "id": 1,
    "parent_id": null,
    "name": "Bicycle",
    "description": "Products in the Bicycle category.",
    "is_active": 1,
    "created_at": "2026-07-26 07:53:20",
    "updated_at": "2026-07-26 07:53:20"
  },
  "meta": {
    "currency": { "code": "HUF", "symbol": "Ft", "title": "Forint" },
    "language": { "code": "en", "name": "English", "default": "hu" }
  }
}
```

Category lists are ordered by the **translated** name, so the order differs per language.

### Other master data

| Method | Path | Description |
|---|---|---|
| GET | `/api/parameters` | Parameters (the master list product parameters pick their name from). Filters: `q`, `updated_since` |
| GET | `/api/parameter-names` | Deprecated alias of `/api/parameters`, kept so existing integrations keep working |
| GET | `/api/units` | Units of measure. Filters: `q`, `updated_since` |
| GET | `/api/currencies` | Currencies and exchange rates. Filters: `q`, `updated_since` |
| GET | `/api/languages` | Languages, with `is_active` and `is_default`. Filters: `q`, `updated_since` |
| GET | `/api/customer-groups` | Customer groups. Filters: `q`, `updated_since` |
| GET | `/api/customer-groups/{id}` | Single customer group |
| GET | `/api/warehouses` | Warehouses. Filters: `q`, `status`, `updated_since` |
| GET | `/api/warehouses/{id}/locations` | The warehouse's storage locations (shelves). Filters: `q` (code/name), `code` (exact match, e.g. a scanned shelf label), `status` |

A location row carries its `code` (unique within the warehouse), an optional descriptive
`name`, `is_active`, and the stock held on it: `stock_qty` (sum over all products) and
`product_count`. For what exactly is on a shelf, use `GET /api/stock?location_id=`.

Example response (`GET /api/currencies`):

```json
{
  "data": [
    {
      "id": 1,
      "title": "Forint",
      "code": "HUF",
      "symbol": "Ft",
      "value": 1,
      "created_at": "2026-07-25 21:33:16",
      "updated_at": "2026-07-25 21:33:16",
      "is_primary": true
    },
    {
      "id": 2,
      "title": "Euró",
      "code": "EUR",
      "symbol": "€",
      "value": 0.00275687,
      "created_at": "2026-07-25 21:33:16",
      "updated_at": "2026-07-25 21:35:24",
      "is_primary": false
    }
  ],
  "meta": {
    "page": 1, "per_page": 50, "total": 2, "total_pages": 1,
    "currency": { "code": "HUF", "symbol": "Ft", "title": "Forint" }
  }
}
```

`value` is the multiplier from the primary currency: **1 primary unit equals `value` of this
currency**, so converting is `amount * value`. The primary currency always has `value: 1` and
`is_primary: true`. Rates are maintained in the admin UI, or refreshed from the MNB (Hungarian
National Bank) mid-rates by the `bin/sync_currency_rates.php` cron script.

### Stock

| Method | Path | Description |
|---|---|---|
| GET | `/api/stock` | Current stock, broken down by warehouse/location/product. Filters: `q`, `warehouse_id`, `location_id`, `product_id` |

Example response (`GET /api/stock`):

```json
{
  "data": [
    {
      "warehouse_id": 2,
      "warehouse_name": "Debreceni telephely",
      "location_id": 14,
      "location_code": "B-02-03",
      "product_id": 31,
      "sku": "PRD-0031",
      "product_name": "Irodai szék",
      "unit": "karton",
      "quantity": "42.000"
    }
  ],
  "meta": {
    "page": 1, "per_page": 50, "total": 298, "total_pages": 6,
    "currency": { "code": "HUF", "symbol": "Ft", "title": "Forint" }
  }
}
```

Movements without a location appear under `location_id`/`location_code` = `null`. `quantity`
is the current stock of that warehouse+location+product (stock in − stock out).

### Invoices

| Method | Path | Description |
|---|---|---|
| GET | `/api/invoices` | Invoice list. Filters: `q`, `partner_id`, `status`, `date_from`, `date_to`, `updated_since` |
| GET | `/api/invoices/{id}` | Single invoice with its line items |

Example response (`GET /api/invoices/2`):

```json
{
  "data": {
    "id": 2,
    "invoice_number": "SZLA-2026-0002",
    "order_id": 3,
    "partner_id": 10,
    "partner_name": "Kelemen Kereskedés",
    "tax_number": "33206217-1-06",
    "warehouse_id": 1,
    "warehouse_name": "Központi raktár",
    "status": "paid",
    "issue_date": "2026-06-30",
    "due_date": "2026-07-08",
    "shipping_cost": "990.00",
    "payment_cost": "890.00",
    "total_amount": "508140.00",
    "created_at": "2026-07-24 16:44:35",
    "updated_at": "2026-07-24 16:44:35",
    "items": [
      { "id": 5, "invoice_id": 2, "product_id": 26, "quantity": "6.000", "unit_price": "6850.00", "line_total": "41100.00", "sku": "PRD-0026", "product_name": "Jóga szőnyeg", "unit": "csomag" }
    ]
  },
  "meta": {
    "currency": { "code": "HUF", "symbol": "Ft", "title": "Forint" }
  }
}
```

`status`: `unpaid` / `paid` / `cancelled`. Invoices are read-only via the API. All amounts are
in the currency named by `meta.currency`.

### Pricing

| Method | Path | Description |
|---|---|---|
| GET | `/api/pricing/effective?product_id=&partner_id=` | The effective (partner/customer-group specific) price of a product |

Example response (`GET /api/pricing/effective?product_id=1&partner_id=12`):

```json
{
  "data": { "price": 80910, "is_sale": false },
  "meta": { "currency": { "code": "HUF", "symbol": "Ft", "title": "Forint" } }
}
```

`price` is the net unit price actually applicable to the partner (the partner's customer-group
fixed/sale price if any, otherwise the product's own price/sale price); `is_sale` is `true` if
that price is a sale price. `partner_id` is optional — without it the product's list price is
returned.

## Full CRUD resources

### Partners

| Method | Path | Description |
|---|---|---|
| GET | `/api/partners` | Partner list. Filters: `q`, `type` (`customer`/`supplier`/`both`), `status`, `customer_group_id`, `updated_since` |
| GET | `/api/partners/{id}` | Single partner with its addresses |
| GET | `/api/partners/tax/{taxNumber}` | Single partner by tax number |
| POST | `/api/partners` | Create a partner |
| PUT | `/api/partners/{id}` | Update a partner |
| PUT | `/api/partners/tax/{taxNumber}` | Upsert: update if the tax number exists, otherwise create |
| DELETE | `/api/partners/{id}` | Delete a partner |

**Partner body (POST/PUT):**

```json
{
  "name": "Example Ltd.",
  "type": "customer",
  "tax_number": "12345678-2-42",
  "email": "info@example.com",
  "phone": "+36 30 123 4567",
  "customer_group_id": 2,
  "is_active": true,
  "addresses": [
    { "country": "Magyarország", "postal_code": "1111", "city": "Budapest", "street": "Példa utca 1.", "note": "2nd floor, door 3" }
  ]
}
```

Fields:

- `name` — **required**.
- `type` — `customer` / `supplier` / `both` (default: `customer`).
- `tax_number`, `email`, `phone` — optional.
- `customer_group_id` — customer group id (0 or omitted = none).
- `is_active` — `true`/`false` (default: `true`).
- `addresses` — optional address list. On PUT, if provided, the partner's addresses are
  **fully replaced** with the given list; if omitted, existing addresses are left unchanged.
  An address is saved only if it has `city`, `postal_code` and `street` (`country` defaults to
  "Magyarország", `note` is optional).

Example response (`GET /api/partners/1`):

```json
{
  "data": {
    "id": 1,
    "type": "customer",
    "customer_group_id": 2,
    "customer_group_name": "VIP",
    "name": "Zöldkert Kft.",
    "tax_number": "81211777-1-33",
    "email": "info@zoldkert.hu",
    "phone": "+36 30 808 6329",
    "is_active": 1,
    "created_at": "2026-07-24 16:44:35",
    "updated_at": "2026-07-24 16:44:35",
    "addresses": [
      { "id": 1, "partner_id": 1, "country": "Magyarország", "postal_code": "1011", "city": "Budapest", "street": "Rákóczi utca 55.", "note": "2nd floor, door 14", "created_at": "2026-07-24 16:44:35", "updated_at": "2026-07-24 16:44:35" },
      { "id": 2, "partner_id": 1, "country": "Magyarország", "postal_code": "4400", "city": "Nyíregyháza", "street": "Petőfi Sándor utca 5.", "note": null, "created_at": "2026-07-24 16:44:35", "updated_at": "2026-07-24 16:44:35" }
    ]
  }
}
```

(`customer_group_name` and the address `id`s appear only in the response; they need not be
supplied on create/update. On delete the response is `{ "data": { "deleted": true, "id": 1 } }`.)

### Orders

| Method | Path | Description |
|---|---|---|
| GET | `/api/orders` | Order list. Filters: `q`, `partner_id`, `status`, `date_from`, `date_to`, `updated_since` |
| GET | `/api/orders/{id}` | Single order with its line items and addresses |
| POST | `/api/orders` | Create an order |
| PUT | `/api/orders/{id}` | Update an order |
| DELETE | `/api/orders/{id}` | Delete an order |

**Order body (POST/PUT):**

```json
{
  "partner_id": 12,
  "order_date": "2026-07-20",
  "status": "confirmed",
  "shipping_address_id": 5,
  "billing_address_id": 5,
  "shipping_cost": 1490,
  "payment_cost": 0,
  "items": [
    { "product_id": 1, "quantity": 3, "unit_price": 59990 }
  ]
}
```

Fields:

- `partner_id` — **required**, an existing partner.
- `items` — **required** (at least one line), each with `product_id`, `quantity`, `unit_price`.
- `order_date` — `YYYY-MM-DD` (default: today).
- `status` — `draft` / `confirmed` / `invoiced` / `cancelled` (default: `confirmed`).
- `shipping_address_id`, `billing_address_id` — the id of one of the partner's addresses
  (optional; 0 or omitted = none).
- `shipping_cost`, `payment_cost` — arbitrary net amounts (default: 0).
- `unit_price` and the cost fields are interpreted in the primary currency (see
  [Currency](#currency)); the API does not convert between currencies.
- The order number is generated automatically; `total_amount` is computed by the server
  (items + `shipping_cost` + `payment_cost`).
- On PUT, `items` is only replaced if provided; otherwise the line items are kept.

Example response (`GET /api/orders/19`) — the address fields are resolved from the chosen addresses:

```json
{
  "data": {
    "id": 19,
    "order_number": "REND-2026-0019",
    "partner_id": 1,
    "partner_name": "Zöldkert Kft.",
    "status": "confirmed",
    "order_date": "2026-07-17",
    "shipping_address_id": 1,
    "billing_address_id": 1,
    "shipping_cost": "990.00",
    "payment_cost": "0.00",
    "total_amount": "183315.00",
    "created_at": "2026-07-24 16:44:35",
    "updated_at": "2026-07-24 16:44:35",
    "shipping_country": "Magyarország", "shipping_postal_code": "1011", "shipping_city": "Budapest", "shipping_street": "Rákóczi utca 55.", "shipping_note": "2nd floor, door 14",
    "billing_country": "Magyarország", "billing_postal_code": "1011", "billing_city": "Budapest", "billing_street": "Rákóczi utca 55.", "billing_note": "2nd floor, door 14",
    "items": [
      { "id": 53, "order_id": 19, "product_id": 47, "quantity": "1.000", "unit_price": "2415.00", "line_total": "2415.00", "sku": "PRD-0047", "product_name": "Öntözőkanna 10L", "unit": "karton" },
      { "id": 54, "order_id": 19, "product_id": 35, "quantity": "9.000", "unit_price": "19990.00", "line_total": "179910.00", "sku": "PRD-0035", "product_name": "Éjjeliszekrény", "unit": "szett" }
    ]
  },
  "meta": {
    "currency": { "code": "HUF", "symbol": "Ft", "title": "Forint" }
  }
}
```

(`partner_name`, the resolved `shipping_*`/`billing_*` address fields, and the line items'
`sku`/`product_name`/`unit`/`line_total` appear only in the response. On delete the response is
`{ "data": { "deleted": true, "id": 19 } }`.)

---

# Examples (curl)

Sign in as a user (the mobile app does this once, then sends the returned token):

```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{"username":"kovacs.anna","password":"<password>","device_name":"Zebra TC21 #3"}' \
  "https://cloudexus.levente.net/api/auth/login"
```

Product list (page 2, 100 per page):

```bash
curl -H "Authorization: Bearer <token>" \
  "https://cloudexus.levente.net/api/products?page=2&per_page=100"
```

Only products changed since yesterday:

```bash
curl -H "Authorization: Bearer <token>" \
  "https://cloudexus.levente.net/api/products?updated_since=2026-07-19%2000:00:00"
```

Products in English (translatable text falls back to the default language where a
translation is missing):

```bash
curl -H "Authorization: Bearer <token>"   "https://cloudexus.levente.net/api/products?language=en"
```

The configured languages, with which one is the default:

```bash
curl -H "Authorization: Bearer <token>"   "https://cloudexus.levente.net/api/languages"
```

Currencies and rates (to convert the amounts on your side):

```bash
curl -H "Authorization: Bearer <token>" \
  "https://cloudexus.levente.net/api/currencies"
```

Create a partner:

```bash
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"name":"New Customer Ltd.","type":"customer","tax_number":"11111111-2-11"}' \
  "https://cloudexus.levente.net/api/partners"
```

Create an order:

```bash
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"partner_id":12,"items":[{"product_id":1,"quantity":2,"unit_price":59990}],"shipping_cost":1490}' \
  "https://cloudexus.levente.net/api/orders"
```
