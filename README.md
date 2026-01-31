# Payvia (Payments) for Glueful

## Overview

Payvia is the official payment gateway bridge for the Glueful PHP Framework. It provides a unified, gateway‑agnostic interface for verifying and recording payments via multiple providers (Paystack, Stripe _[coming soon]_, Flutterwave _[coming soon]_, and more) into a single `payments` table.

## Features

- ✅ Generic `payments` table with:
  - `gateway`, `gateway_transaction_id`, `reference`
  - `user_uuid` and polymorphic `payable_type` / `payable_id` link
  - `metadata` JSON for app‑level context
  - `raw_payload` JSON for full provider responses
- ✅ Gateway abstraction via `PaymentGatewayInterface`
- ✅ `GatewayManager` to resolve gateways by config name (e.g. `paystack`, `stripe`)
- ✅ `PaymentService` with a single entrypoint: `confirmAndRecord()`
- ✅ HTTP endpoint for payment confirmation:
  - `POST /payvia/payments/confirm`
 - ✅ Generic billing plans (`billing_plans`) and invoices (`invoices`) with thin services

## Requirements

- PHP 8.3+
- Glueful Framework 1.22.0+
- No extra libraries required for Paystack (uses Glueful HTTP client)
- Provider‑specific SDKs are optional if you add custom gateways

## Installation

```bash
composer require glueful/payvia

# Rebuild extension cache
php glueful extensions:cache

# Run migrations for payments
php glueful migrate run
```

### Enabling the extension

There are two ways to enable this extension:

1) Manual (recommended; works in all environments)

Edit your project's `config/extensions.php` and add the provider class to the `enabled` list:

```php
// config/extensions.php
return [
    'enabled' => [
        Glueful\Extensions\Payvia\PayviaServiceProvider::class,
        // other providers...
    ],
    // ...
];
```

2) CLI (convenient for local development)

```bash
php glueful extensions:enable Payvia
```

## Verify Installation

Check discovery and provider wiring:

```bash
php glueful extensions:list
php glueful extensions:info Payvia
php glueful extensions:why Glueful\\Extensions\\Payvia\\PayviaServiceProvider
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
PAYVIA_PAYSTACK_BASE_URL=https://api.paystack.co
PAYVIA_PAYSTACK_TIMEOUT=15

# Stripe (example)
PAYVIA_STRIPE_ENABLED=false
PAYVIA_STRIPE_SECRET_KEY=sk_test_xxx

# Flutterwave (example)
PAYVIA_FLUTTERWAVE_ENABLED=false
PAYVIA_FLUTTERWAVE_SECRET_KEY=flw_test_xxx

# Whether to store full provider payload in raw_payload column
PAYVIA_STORE_RAW_PAYLOAD=true
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
            'base_url' => env('PAYVIA_PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'timeout' => (int) env('PAYVIA_PAYSTACK_TIMEOUT', 15),
        ],
        // 'stripe' => [...],
        // 'flutterwave' => [...],
    ],

    'features' => [
        'store_raw_payload' => (bool) env('PAYVIA_STORE_RAW_PAYLOAD', true),
    ],
];
```

## HTTP API

### Confirm and record a payment

- **Endpoint:** `POST /payvia/payments/confirm`
- **Middleware:** `auth`, `rate_limit:60,60`
- **Handler:** `Glueful\Extensions\Payvia\Controllers\PaymentController::confirm`

**Request body (JSON / form / query):**

- `reference` (string, required): provider transaction reference.
- `gateway` (string, optional): gateway key from `config/payvia.php` (`payvia.gateways`).  
  If omitted, `payvia.default_gateway` is used.
- `user_uuid` (string, optional): UUID of the paying user.
- `payable_type` (string, optional): logical type of the thing being paid for  
  (e.g. `subscription`, `order`, `invoice`).
- `payable_id` (string, optional): identifier of that thing in its own domain  
  (e.g. subscription UUID, order ID).
- `metadata` (object, optional): app‑level metadata to store in the `metadata` column.
- `options` (object, optional): gateway‑specific options (e.g. override verify URL).

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
    "user_uuid": "user_nanoid_123",
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
- **Middleware:** `auth`, `rate_limit:30,60`
- **Handler:** `Glueful\Extensions\Payvia\Controllers\BillingPlanController::create`

**Body:**

- `name` (string, required)
- `amount` (number, required)
- `currency` (string, optional, default: `GHS`)
- `interval` (string, optional, default: `monthly`)
- `trial_days` (int, optional)
- `features` (object, optional) – JSON feature flags / limits
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
    "features": {
      "locations": 3,
      "users": 10
    }
  }'
```

#### List plans (with JSON feature filtering)

- **Endpoint:** `GET /payvia/plans`
- **Middleware:** `auth`, `rate_limit:60,60`
- **Handler:** `Glueful\Extensions\Payvia\Controllers\BillingPlanController::index`

**Query parameters:**

- `status` – filter by plan status (`active`, `inactive`)
- `interval` – filter by billing interval (`monthly`, `yearly`, `one_time`, etc.)
- `currency` – filter by currency code
- `features_key` – JSON key inside `features`
- `features_value` – value that `features_key` must contain

**Example (plans that include `locations` >= 1):**

```bash
curl -s "$API_BASE/payvia/plans?status=active&features_key=locations&features_value=1" \
  -H "Authorization: Bearer $TOKEN"
```

### Manage invoices

#### Create an invoice

- **Endpoint:** `POST /payvia/invoices`
- **Middleware:** `auth`, `rate_limit:60,60`
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
    'features' => [
        'locations' => 3,
        'users' => 10,
    ],
]);

// List active monthly plans with a feature flag
$activePlans = $plans->list([
    'status' => 'active',
    'interval' => 'monthly',
    'features_contains' => [
        'key' => 'locations',
        'value' => '1', // uses whereJsonContains under the hood
    ],
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

To add another provider (e.g. Stripe):

1. Implement `Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface` (e.g. `StripeGateway`).
2. Register the gateway as a service in `PayviaServiceProvider::services()`.
3. Map a driver name to the class in `GatewayManager::$drivers`.
4. Add config under `payvia.gateways` in `config/payvia.php` (with `driver` set to your driver name).

After that, you can pass `gateway: "stripe"` to the confirm endpoint or set it as the default in config.
