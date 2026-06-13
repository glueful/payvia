# Payvia (Payments) for Glueful

## Overview

Payvia is the official payment gateway bridge for the Glueful PHP Framework. It provides a unified, gateway‑agnostic interface for verifying and recording payments via multiple providers (Paystack, Stripe, Flutterwave _[coming soon]_, and more) into a single `payments` table.

## Features

- ✅ Generic `payments` table with:
  - `gateway`, `gateway_transaction_id`, `reference`
  - `user_uuid` and polymorphic `payable_type` / `payable_id` link
  - `metadata` JSON for app‑level context
  - `raw_payload` JSON for full provider responses
- ✅ Gateway abstraction via `PaymentGatewayInterface`
- ✅ `GatewayManager` to resolve gateways by config name (e.g. `paystack`, `stripe`)
- ✅ `PaymentService` with a single entrypoint: `confirmAndRecord()`
- ✅ Normalized provider-event outbox for webhooks and verify-origin confirmations
- ✅ Signature-verified webhook endpoint:
  - `POST /payvia/webhooks/{gateway}`
- ✅ Provider subscription projection in `gateway_subscriptions`
- ✅ HTTP endpoint for payment confirmation:
  - `POST /payvia/payments/confirm`
 - ✅ Generic billing plans (`billing_plans`) and invoices (`invoices`) with thin services

## Requirements

- PHP 8.3+
- Glueful Framework 1.50.1+
- No extra libraries required for Paystack (uses Glueful HTTP client)
- Provider‑specific SDKs are optional if you add custom gateways

## Installation

```bash
composer require glueful/payvia

# Run migrations for payments
php glueful migrate run
```

### Enabling the extension

Installing the package does **not** auto-load it — its provider must be in
`config/extensions.php`'s `enabled` allow-list.

**Development (recommended):** the CLI edits `config/extensions.php` and recompiles the
cache (validated before writing):

```bash
php glueful extensions:enable payvia
# disable with: php glueful extensions:disable payvia
```

**By hand / in production:** add the provider as a plain string FQCN (no `::class`),
then build the manifest in your deploy step:

```php
// config/extensions.php
return [
    'enabled' => [
        'Glueful\\Extensions\\Payvia\\PayviaServiceProvider',
        // other providers...
    ],
];
```

```bash
php glueful extensions:cache   # required in production
```

Payvia also auto-discovers the `payvia:relay-events` command. If your app caches command metadata during deploy, rebuild that cache after enabling or upgrading the extension.

## Verify Installation

Check discovery and provider wiring:

```bash
php glueful extensions:list
php glueful extensions:info payvia
php glueful extensions:diagnose
```

Run database migrations (if not auto‑run):

```bash
php glueful migrate run
```

## Configuration

Payvia ships with a package config file at `config/payvia.php` (inside the extension). You can override values via your app’s `.env` or by publishing / merging config.

Key environment variables:

```env
# Default gateway (must exist in payvia.gateways)
PAYVIA_DEFAULT_GATEWAY=paystack

# Paystack
PAYVIA_PAYSTACK_ENABLED=true
PAYVIA_PAYSTACK_SECRET_KEY=sk_test_xxx
PAYVIA_PAYSTACK_WEBHOOK_SECRET=sk_test_xxx
PAYVIA_PAYSTACK_BASE_URL=https://api.paystack.co
PAYVIA_PAYSTACK_TIMEOUT=15

# Stripe
PAYVIA_STRIPE_ENABLED=false
PAYVIA_STRIPE_SECRET_KEY=sk_test_xxx
PAYVIA_STRIPE_WEBHOOK_SECRET=whsec_xxx
PAYVIA_STRIPE_BASE_URL=https://api.stripe.com
PAYVIA_STRIPE_TIMEOUT=15

# Whether to store full provider payload in raw_payload column
PAYVIA_STORE_RAW_PAYLOAD=true

# Webhook processing
PAYVIA_WEBHOOKS_QUEUE=false
PAYVIA_WEBHOOKS_QUEUE_NAME=default
PAYVIA_WEBHOOKS_RELAY_STALE_SECONDS=300
```

Config structure (simplified):

```php
return [
    'default_gateway' => env('PAYVIA_DEFAULT_GATEWAY', 'paystack'),

    'gateways' => [
        'paystack' => [
            'enabled' => (bool) env('PAYVIA_PAYSTACK_ENABLED', true),
            'driver' => 'paystack',
            'secret_key' => env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null)),
            'webhook_secret' => env('PAYVIA_PAYSTACK_WEBHOOK_SECRET', env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null))),
            'base_url' => env('PAYVIA_PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'timeout' => (int) env('PAYVIA_PAYSTACK_TIMEOUT', 15),
        ],
        'stripe' => [
            'enabled' => (bool) env('PAYVIA_STRIPE_ENABLED', false),
            'driver' => 'stripe',
            'secret_key' => env('PAYVIA_STRIPE_SECRET_KEY', null),
            'webhook_secret' => env('PAYVIA_STRIPE_WEBHOOK_SECRET', null),
            'webhook_tolerance' => (int) env('PAYVIA_STRIPE_WEBHOOK_TOLERANCE', 300),
            'base_url' => env('PAYVIA_STRIPE_BASE_URL', 'https://api.stripe.com'),
            'timeout' => (int) env('PAYVIA_STRIPE_TIMEOUT', 15),
        ],
    ],

    'features' => [
        'store_raw_payload' => (bool) env('PAYVIA_STORE_RAW_PAYLOAD', true),
    ],

    'security' => [
        // Middleware applied to billing-plan and invoice write routes (admin-only by default).
        'manage_middleware' => ['auth', 'admin'],
    ],

    'webhooks' => [
        'queue' => (bool) env('PAYVIA_WEBHOOKS_QUEUE', false),
        'queue_name' => env('PAYVIA_WEBHOOKS_QUEUE_NAME', 'default'),
        'relay_stale_seconds' => (int) env('PAYVIA_WEBHOOKS_RELAY_STALE_SECONDS', 300),
    ],
];
```

## Webhooks and Provider Events

Payvia persists provider deliveries in `provider_events`, normalizes them into `ProviderEvent`, applies idempotent side effects, then dispatches `PaymentProviderEvent` through the framework event bus.

The `provider_events` table uses two event keys:

- `delivery_key` dedupes exact provider redeliveries per gateway.
- `logical_event_key` dedupes the same business fact across delivery paths, such as a manual verify confirmation and a later webhook for the same payment.

`normalized_payload` stores Payvia's gateway-agnostic event shape for replay, while `dispatch_status` powers the outbox relay.

Provider webhook endpoints:

```text
POST /payvia/webhooks/paystack
POST /payvia/webhooks/stripe
```

The webhook route intentionally has no `auth` middleware. Payvia verifies the provider signature inside the webhook pipeline before accepting the event.

`payvia:relay-events` replays processed provider events that were not dispatched yet, including crash recovery for rows stuck in `dispatching`.

## Provider Subscriptions

Payvia persists gateway-owned subscription state in `gateway_subscriptions` and exposes `GatewaySubscriptionService::reconcile($gateway, $gatewaySubscriptionId)`. It stays tenancy-agnostic: tenant ownership and entitlement decisions belong to `glueful/subscriptions`.

`gateway_subscriptions` stores provider subscription state only. It intentionally does not store tenant ownership; `glueful/subscriptions` owns the tenant-to-provider-subscription map and all entitlement decisions.

The stored `status` is **normalized** and **fails closed**: provider statuses are mapped to one of `active`, `past_due`, `canceled`, `incomplete`, `paused`, or `unknown`. Only the explicitly active provider statuses (`active`, `trialing`) become `active`; any unrecognized, future, or missing provider status is recorded as `unknown` (never silently treated as live). Consumers deciding entitlement should treat anything other than `active` as not entitled.

## Billing Plans and Entitlements

`billing_plans` is the priced-plan side of Payvia. It includes provider linkage fields:

- `gateway`
- `gateway_product_id`
- `gateway_price_id`

Use these fields to link a local priced plan to provider-side product, price, or plan objects. Paystack usually maps to `gateway_price_id`; Stripe can use both `gateway_product_id` and `gateway_price_id`.

Payvia does not store feature gates or entitlement catalogs on billing plans. Tenant plans, feature gates, and overrides belong in `glueful/subscriptions`.

## HTTP API

### Authorization

The billing **write** endpoints — creating, updating, or disabling plans, and
creating, marking-paid, or canceling invoices — require an **admin** caller by
default. They run the `auth` + `admin` middleware (the framework's
`AdminPermissionMiddleware`), so a plain authenticated end-user receives
`403 Forbidden`. Read endpoints (`GET /payvia/plans`, `GET /payvia/invoices`),
`POST /payvia/payments/confirm`, and the signature-verified webhook route are
**not** gated by `admin`.

Admin-gated write routes:

- `POST /payvia/plans`
- `POST /payvia/plans/update`
- `POST /payvia/plans/disable`
- `POST /payvia/invoices`
- `POST /payvia/invoices/mark-paid`
- `POST /payvia/invoices/cancel`

**Overriding the management middleware.** The stack applied to these write routes
is configurable via `payvia.security.manage_middleware`. Override it in your app's
`config/payvia.php` (or merged config) to swap `admin` for a custom permission
middleware, or to relax/tighten the requirement. Each route still appends its own
`rate_limit:N,60` after this stack.

```php
// config/payvia.php (application override)
return [
    'security' => [
        // e.g. require a custom 'billing.manage' permission middleware instead of admin
        'manage_middleware' => ['auth', 'permission:billing.manage'],
    ],
];
```

Default:

```php
'security' => [
    'manage_middleware' => ['auth', 'admin'],
],
```

### Confirm and record a payment

- **Endpoint:** `POST /payvia/payments/confirm`
- **Middleware:** `auth`, `rate_limit:60,60`
- **Handler:** `Glueful\Extensions\Payvia\Controllers\PaymentController::confirm`

**Request body (JSON / form / query):**

- `reference` (string, required): provider transaction reference.
- `gateway` (string, optional): gateway key from `config/payvia.php` (`payvia.gateways`).  
  If omitted, `payvia.default_gateway` is used.
- `payable_type` (string, optional): logical type of the thing being paid for  
  (e.g. `subscription`, `order`, `invoice`).
- `payable_id` (string, optional): identifier of that thing in its own domain  
  (e.g. subscription UUID, order ID).
- `metadata` (object, optional): app‑level metadata to store in the `metadata` column.
- `options` (object, optional): gateway‑specific options (e.g. override verify URL).

> **Note:** The stored `user_uuid` is always derived from the authenticated session, not
> from the request body. It is **not** caller‑settable. If a `user_uuid` is supplied and it
> differs from the authenticated user's UUID, the request is rejected with `422`. This
> prevents an authenticated caller from attributing a payment to another user.

**Response (200):**

On success, the endpoint verifies the transaction through the configured gateway and
upserts a row in the `payments` table. The JSON response follows Glueful’s standard
`Response::success` shape and includes:

- `payment_status`
- `gateway`
- `reference`
- `amount`
- `currency`
- `message`
- `verification` (normalized gateway verification payload)

### Quick cURL Example (Paystack)

```bash
API_BASE=http://localhost:8000
TOKEN="<YOUR_BEARER_TOKEN>"

curl -s -X POST "$API_BASE/payvia/payments/confirm" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "reference": "PSK_tx_ref_123456",
    "gateway": "paystack",
    "payable_type": "subscription",
    "payable_id": "sub_plan_uuid_123",
    "metadata": {
      "source": "web_checkout",
      "campaign": "black_friday"
    }
  }'
```

### Manage billing plans

#### Create a plan

- **Endpoint:** `POST /payvia/plans`
- **Middleware:** `auth`, `admin`, `rate_limit:30,60` (admin-only — see [Authorization](#authorization))
- **Handler:** `Glueful\Extensions\Payvia\Controllers\BillingPlanController::create`

**Body:**

- `name` (string, required)
- `amount` (number, required)
- `currency` (string, optional, default: `GHS`)
- `interval` (string, optional, default: `monthly`)
- `trial_days` (int, optional)
- `gateway` (string, optional)
- `gateway_product_id` (string, optional)
- `gateway_price_id` (string, optional)
- `metadata` (object, optional)
- `status` (string, optional, default: `active`)

**Example:**

```bash
curl -s -X POST "$API_BASE/payvia/plans" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Pro Monthly",
    "amount": 99.0,
    "currency": "USD",
    "interval": "monthly",
    "trial_days": 14,
    "gateway": "stripe",
    "gateway_product_id": "prod_123",
    "gateway_price_id": "price_123"
  }'
```

#### List plans

- **Endpoint:** `GET /payvia/plans`
- **Middleware:** `auth`, `rate_limit:60,60`
- **Handler:** `Glueful\Extensions\Payvia\Controllers\BillingPlanController::index`

**Query parameters:**

- `status` – filter by plan status (`active`, `inactive`)
- `interval` – filter by billing interval (`monthly`, `yearly`, `one_time`, etc.)
- `currency` – filter by currency code

**Example:**

```bash
curl -s "$API_BASE/payvia/plans?status=active&interval=monthly" \
  -H "Authorization: Bearer $TOKEN"
```

### Manage invoices

#### Create an invoice

- **Endpoint:** `POST /payvia/invoices`
- **Middleware:** `auth`, `admin`, `rate_limit:60,60` (admin-only — see [Authorization](#authorization))
- **Handler:** `Glueful\Extensions\Payvia\Controllers\InvoiceController::create`

**Body:**

- `amount` (number, required)
- `currency` (string, optional, default: `GHS`)
- `user_uuid` (string, optional)
- `billing_plan_uuid` (string, optional)
- `payable_type` (string, optional)
- `payable_id` (string, optional)
- `number` (string, optional; auto-generated if omitted)
- `due_at` (string, optional, `Y-m-d H:i:s`)
- `metadata` (object, optional)

#### List invoices (with JSON metadata filtering)

- **Endpoint:** `GET /payvia/invoices`
- **Middleware:** `auth`, `rate_limit:60,60`
- **Handler:** `Glueful\Extensions\Payvia\Controllers\InvoiceController::index`

**Query parameters:**

- `status` – `draft`, `pending`, `paid`, `canceled`, `failed`
- `user_uuid`
- `billing_plan_uuid`
- `payable_type`
- `payable_id`
- `metadata_key` – JSON key inside `metadata`
- `metadata_value` – value that `metadata_key` must contain

**Example (invoices for a user with `period=2025-01` in metadata):**

```bash
curl -s "$API_BASE/payvia/invoices?user_uuid=$USER_UUID&metadata_key=period&metadata_value=2025-01" \
  -H "Authorization: Bearer $TOKEN"
```

## PHP Usage Examples

### Payments via `PaymentService`

```php
use Glueful\Extensions\Payvia\Services\PaymentService;

/** @var PaymentService $payments */
$payments = container()->get(PaymentService::class);

$result = $payments->confirmAndRecord(
    reference: 'PSK_tx_ref_123456',
    gatewayName: 'paystack', // or null to use default
    context: [
        'user_uuid' => $userUuid,
        'payable_type' => 'subscription',
        'payable_id' => $subscriptionId,
        'metadata' => [
            'source' => 'web_checkout',
            'campaign' => 'black_friday',
        ],
    ]
);

if (($result['payment_status'] ?? '') === 'success') {
    // Start subscription, mark invoice paid, etc.
}
```

### Plans via `BillingPlanService`

```php
use Glueful\Extensions\Payvia\Services\BillingPlanService;

/** @var BillingPlanService $plans */
$plans = container()->get(BillingPlanService::class);

// Create a plan
$planUuid = $plans->create([
    'name' => 'Pro Monthly',
    'description' => 'Pro plan billed monthly',
    'amount' => 99.00,
    'currency' => 'USD',
    'interval' => 'monthly',
    'trial_days' => 14,
    'gateway' => 'stripe',
    'gateway_product_id' => 'prod_123',
    'gateway_price_id' => 'price_123',
]);

// List active monthly plans
$activePlans = $plans->list([
    'status' => 'active',
    'interval' => 'monthly',
]);
```

### Invoices via `InvoiceService`

```php
use Glueful\Extensions\Payvia\Services\InvoiceService;

/** @var InvoiceService $invoices */
$invoices = container()->get(InvoiceService::class);

// Create an invoice linked to a plan and payable entity
$invoiceUuid = $invoices->create([
    'user_uuid' => $userUuid,
    'billing_plan_uuid' => $planUuid,
    'payable_type' => 'location_subscription',
    'payable_id' => $locationUuid,
    'amount' => 99.00,
    'currency' => 'USD',
    'status' => 'pending',
    'metadata' => [
        'period' => '2025-01',
        'source' => 'subscription_renewal',
    ],
]);

// After a successful payment, mark the invoice as paid
$invoices->markPaid($invoiceUuid);

// List paid invoices for a user for a given period
$userInvoices = $invoices->list([
    'user_uuid' => $userUuid,
    'status' => 'paid',
    'metadata_contains' => [
        'key' => 'period',
        'value' => '2025-01',
    ],
]);
```

## Schema Notes

- `payable_type` / `payable_id` form a polymorphic link to “what this payment is for”, so you can attach payments to subscriptions, orders, invoices, etc. without changing the schema.
- `metadata` is intended for lightweight, queryable app context (plan UUID, billing cycle, campaign tags).
- `raw_payload` stores the full provider verification payload when `payvia.features.store_raw_payload` is enabled.

## Adding a New Gateway

To add another provider:

1. Implement `Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface`.
2. Register the gateway as a service in `PayviaServiceProvider::services()`.
3. Map a driver name to the class in `GatewayManager::$drivers`.
4. Add config under `payvia.gateways` in `config/payvia.php` (with `driver` set to your driver name).

After that, you can pass `gateway: "stripe"` to the confirm endpoint or set it as the default in config.
