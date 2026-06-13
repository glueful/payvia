# Changelog

All notable changes to the Payvia (Payments) extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **Payment confirmation now binds `user_uuid` to the authenticated session.** `POST /payvia/payments/confirm` previously took `user_uuid` verbatim from the request body and wrote it to the `payments` row, letting any authenticated caller attribute a payment to any other user. The stored `user_uuid` is now always derived from the authenticated identity (`BaseController::$currentUser`). If the body supplies a `user_uuid` that differs from the session (or is supplied with no resolvable session), the request is rejected with `422`; a matching or absent value is accepted. When no authenticated user is resolvable, `user_uuid` falls back to `null` rather than trusting the body. The field is no longer caller-settable and has been removed from the route/README request-body docs.
- **Billing-plan and invoice write routes now require an admin caller.** `POST /payvia/plans`, `/payvia/plans/update`, `/payvia/plans/disable`, `POST /payvia/invoices`, `/payvia/invoices/mark-paid`, and `/payvia/invoices/cancel` now run the `admin` middleware in addition to `auth` and their rate limit. Previously any authenticated end-user could create/update/disable billing plans and create/mark-paid/cancel arbitrary invoices (no ownership or role check existed). The management middleware stack is configurable via the new `payvia.security.manage_middleware` config key (default `['auth', 'admin']`), so hosts can substitute a custom permission middleware. Read, payment-confirm, and webhook routes are unchanged.
- **Removed the caller-controlled `verify_url` override in the Paystack gateway.** `PaystackGateway::verify()` now always derives the verification URL from the trusted `payvia.gateways.paystack.base_url` config, ignoring any `options['verify_url']` supplied through `POST /payvia/payments/confirm`. Previously an authenticated caller could redirect verification to an arbitrary host, leaking the live Paystack secret key and forging a successful payment (SSRF / payment forgery).

### Fixed

- **`PayviaServiceProvider::composerVersion()` now guards the `composer.json` read.** It called `file_get_contents()` unchecked — a read failure returned `false`, which `json_decode()` then warned on — and assumed the decoded value was an array. The method now falls back to `'0.0.0'` when the file cannot be read or does not decode to an array (and when `version` is not a string), while preserving the existing static caching.
- **`POST /payvia/payments/confirm` no longer reads parameters from the query string.** `PaymentController::confirm()` merged `$request->query->all()` into the request data, so a payment `reference` (and any other confirm parameter) passed via the URL was accepted — and consequently captured in web-server/proxy access logs. Confirm parameters are now taken from the JSON body and POST form fields only. The authenticated-session `user_uuid` spoof guard is unchanged for body/form input; it simply no longer evaluates query-string values, since those are no longer read at all.
- **`payment.failed` is now treated as a mutable event so repeat failures are not deduplicated away.** `EventType` listed `PAYMENT_FAILED` as immutable, giving every failure for a given entity the same logical key (`type:entityId`); a second failure for the same entity (e.g. a Stripe `payment_intent` retried and failing again) collapsed onto the first and the application never heard about it. `PAYMENT_FAILED` is now mutable, so `ProviderEvent::deriveLogicalKey()` keys it by `type:entityId:discriminator` (or a hash of the normalized state when no discriminator is supplied) and distinct failures produce distinct logical events. `invoice.payment_failed` and the other immutable lifecycle events are unchanged.
- **Auto-generated invoice numbers now use the full NanoID for entropy.** `InvoiceRepository::create()` built the fallback `number` as `INV-{date}-{last 4 chars of uuid}`, using only 4 of the 12 NanoID characters and risking a `UNIQUE(number)` collision under load. It now appends the full uppercased uuid (`INV-{date}-{UUID}`), so the generated number is as unique as the invoice's primary identifier. Caller-supplied `number` values are unaffected.
- **`ProcessWebhookJob` no longer wastes retries on unrecoverable errors.** When the job ran with no `ApplicationContext` or with a missing `provider_event_uuid` it threw a `\RuntimeException`, which the base `Glueful\Queue\Job` worker treats as a transient failure and re-queues up to `getMaxAttempts()` times — pointless work, since neither condition can be fixed by retrying. Both permanent cases now log a `[Payvia]`-prefixed message, call `$this->delete()`, and return, so the worker records the job complete instead of retrying. A genuine processing failure from `WebhookService::processStored()` still throws and is therefore retried as before.
- **API error responses no longer leak exception details.** `PaymentController::confirm`, every write/list path in `BillingPlanController` and `InvoiceController`, and the webhook 404 previously returned the raw `$e->getMessage()` (or the reflected, attacker-supplied gateway name) to the client in their `500`/`404` responses — exposing gateway HTTP errors, SQL/driver messages, and internal file paths. Each `catch (\Throwable)` now logs the exception class, message, and file/line server-side (via the PSR `LoggerInterface` resolved from the container, falling back to `error_log()`) and returns a generic per-endpoint message (e.g. `Failed to verify payment`, `Failed to create plan`, `Failed to list invoices`). The webhook gateway-not-found case now returns a static `gateway not found or unsupported` instead of reflecting the supplied gateway name; the `401 invalid signature` response and all `422` field-validation messages are unchanged.
- **Billing-plan and invoice inputs are now validated instead of trusted verbatim.** `BillingPlanController` and `InvoiceController` previously accepted free-form `status`, `interval`, and `currency`, allowed zero/negative `amount`, silently fell back to `now()` on an unparseable `paid_at`, and left `per_page` uncapped. Now: plan `status` must be `active|inactive` and invoice `status` must be `draft|pending|paid|canceled|failed`; plan `interval` must be `monthly|yearly|one_time`; `currency` is uppercased then validated against `^[A-Z]{3}$`; `amount` must be greater than 0 — each rejected with a `422` on both the create and update paths (invoice create for amount/currency). `InvoiceController::markPaid` now returns a `422` naming `paid_at` when the value cannot be parsed by `DateTimeImmutable` rather than silently using the current time, and `InvoiceController::index` caps `per_page` at 100. Lowercase currency input is still accepted (normalized to uppercase before validation), so previously valid requests keep working.
- **Provider subscription status normalization now fails closed.** `GatewaySubscriptionService::normalizeStatus()` previously mapped any unrecognized provider status to `active` via a `default => 'active'` arm, so Stripe statuses like `unpaid`, `paused`, and `incomplete_expired` (and any future/unknown status) made delinquent or paused subscriptions look live. Known statuses are now mapped explicitly (`active`/`trialing` → `active`; `unpaid` → `past_due`; `incomplete_expired` → `canceled`; `paused` → `paused`) and anything unrecognized, empty, or null normalizes to a new non-active `unknown` value. The same fail-open default in `normalizeProviderSubscription()` (used by `reconcile()`) was removed: an absent provider status no longer fabricates `active`. The `gateway_subscriptions.status` column is `VARCHAR(30)`, so the new `unknown`/`paused` values fit without a schema change.
- **`reconcile()` subscription normalization is now gateway-aware.** `GatewaySubscriptionService::reconcile()` previously normalized every provider's fetched subscription through a single Paystack-shaped path. For Stripe — whose subscription fetch returns the raw object with no `data` wrapper — this lost `current_period_start`/`current_period_end` entirely (the code only read Paystack's `next_payment_date`) and passed `canceled_at` through as a raw unix epoch into the `DATETIME` column. Normalization now dispatches on the gateway: the Stripe path reads the scalar `customer` id, the price at `items.data[0].price.id`, `metadata.billing_plan_uuid`, the real `cancel_at_period_end` boolean, and converts the three unix-timestamp fields (`current_period_start`, `current_period_end`, `canceled_at`) to `Y-m-d H:i:s` strings (null when absent/non-numeric). Status is passed through raw so the fail-closed `normalizeStatus` still applies. The Paystack/generic path is unchanged, and an unknown gateway falls back to it so custom subscription drivers keep working. No interface change — `SubscriptionCapableGateway` is untouched (no BC break for third-party drivers).
- **Payment and subscription upserts now recover from unique-violation races instead of returning 500s.** Concurrent webhook/client retries (a normal occurrence with payment providers) could lose a find-then-insert (TOCTOU) race and crash with an unhandled UNIQUE-constraint violation — `PaymentService::confirmAndRecord` on `payments.reference`, and `GatewaySubscriptionRepository::upsertByGatewayId` on `(gateway, gateway_subscription_id)`. On a unique violation during the insert, both now fall back to the update path (re-fetching the row where needed) so the data is still applied; any other exception still propagates. Detection is centralized in a shared `DetectsUniqueViolations` trait reused by `PaymentService`, `GatewaySubscriptionRepository`, and `ProviderEventRepository`.
- **Added a composite dispatch index to `provider_events` for the relay scheduler hot path.** `ProviderEventRepository::findDispatchable()` — polled on every relay tick — filters with `status = 'processed' AND (dispatch_status = ? OR (dispatch_status = ? AND dispatch_claimed_at < ?))`, but the table only carried single-column indexes on `status` and `dispatch_status`, forcing the planner toward a scan as the outbox grows. New migration `006_AddProviderEventsDispatchIndex` adds `idx_provider_events_dispatch` on `(status, dispatch_status, dispatch_claimed_at)` — equality columns first, the range column last — so the predicate can be served by an index seek/range scan. The migration is idempotent (safe to re-run), provides a guarded `down()`, and works across SQLite, MySQL, and PostgreSQL.

### Changed

- **`billing_plans.name` uniqueness is now scoped per gateway.** The original `002` migration declared a global `UNIQUE (name)`, so the same plan name could never coexist across two payment gateways. New migration `007_ScopeBillingPlanNameUniquePerGateway` replaces it with a composite `UNIQUE (gateway, name)`. Because the framework emits the original constraint INLINE in `CREATE TABLE` — undroppable on SQLite (anonymous `sqlite_autoindex_*`) and a named CONSTRAINT on PostgreSQL that the schema builder's `dropUnique()` cannot drop portably — the change is applied via a full table rebuild (create replacement, copy every row, drop original, rename into place) so it works identically on SQLite, MySQL, and PostgreSQL. The rebuilt table is index-equivalent to `002` apart from this one change (the `uuid` unique is preserved). NULL semantics: `gateway` is nullable and NULLs do not collide in a unique index on any of the three drivers, so multiple plans with no gateway (`gateway IS NULL`) may still share a name; two plans with the same non-NULL gateway may not. The migration is idempotent (a guarded no-op if the composite unique is already present) and ships a `down()` that restores the global unique — `down()` will fail if the data has come to contain the same name under two different gateways, which is expected for a uniqueness-tightening rollback.

### Planned
- Flutterwave gateway driver.
- PayPal/Braintree gateway driver.
- Adyen gateway driver.
- Checkout.com gateway driver.
- Optional marketplace/split-payment capability interfaces.
- Optional refunds/disputes capability interfaces.

## [1.0.1] - 2026-06-10 -- Framework 1.54 Compatibility

### Changed

- **Minimum framework raised to Glueful Framework 1.54.0.** `require-dev` now targets `glueful/framework ^1.54.0`, and extension metadata now requires `glueful >=1.54.0`.
- **Extension metadata version bumped to `1.0.1`.** This is a compatibility patch release for the Framework 1.54 line; Payvia's public payment/provider surface is unchanged from `1.0.0`.

## [1.0.0] - 2026-06-10 -- Stable Payment Provider Surface

### Added

- Gateway linkage fields on `billing_plans`: `gateway`, `gateway_product_id`, and `gateway_price_id`.
- Normalized provider event seam: `PaymentProviderEventInterface`, immutable `ProviderEvent`, `PaymentProviderEvent`, and `EventType`.
- Optional gateway capability interfaces: `WebhookCapableGateway` and `SubscriptionCapableGateway`.
- `provider_events` table with delivery-key ingestion dedupe, logical-key outbox dispatch, durable normalized payload, and crash-recoverable dispatch claiming.
- `POST /payvia/webhooks/{gateway}` for signature-verified provider webhooks.
- `payvia:relay-events` for replaying processed-but-undispatched provider events.
- `gateway_subscriptions` provider subscription projection plus `GatewaySubscriptionService::reconcile()`.
- Paystack webhook HMAC SHA512 verification and normalized event parsing.
- Stripe verification, webhook HMAC SHA256 verification, normalized event parsing, subscription fetch, and subscription cancellation.
- Verify-origin events from `PaymentService::confirmAndRecord()` flow through the same provider-event outbox.

### Removed

- `billing_plans.features`. Payvia billing plans are priced/provider plans only; tenant entitlements belong in `glueful/subscriptions`.

## [0.7.0] - 2026-06-05 — Framework 1.50 Compatibility

### Fixed

- **Controllers no longer fatal on instantiation against Framework 1.50.** `BaseController::__construct` now requires an `ApplicationContext`, but `PaymentController` / `InvoiceController` / `BillingPlanController` called `parent::__construct()` with no arguments ("Too few arguments" fatal). Each now accepts `ApplicationContext` and passes it through (and resolves its service via `app($context, …)`).
- **`ValidationException` API updated.** `PaymentController` used `new ValidationException('reference is required')`, but the constructor now expects an errors array — switched to the `ValidationException::forField('reference', …)` factory.

### Changed

- **Dropped cross-package FKs** from `payments.user_uuid` and `invoices.user_uuid` → `users(uuid)`. `user_uuid` is now an **indexed logical reference** (the `users` table is owned by `glueful/users`; Phase-5 decoupling disallows cross-package FKs — integrity is enforced at the service layer).
- **Migrations register at `MigrationPriority::DEPENDENT`** with source `glueful/payvia` (previously a bare `loadMigrationsFrom()` — the old FKs relied on migration ordering that was never guaranteed).
- **Minimum framework raised to `glueful/framework >=1.50.1`** (`require-dev` pinned to `^1.50.1`); previously `>=1.30.0`.

## [0.6.1] - 2026-02-09

### Fixed
- **Controller DI Registration**: `PaymentController`, `BillingPlanController`, and `InvoiceController` were not registered in `PayviaServiceProvider::services()`, causing `Service not found` errors when the router resolved controllers from the container. All controllers are now explicitly registered with their dependencies.

### Notes
- Patch release. No breaking changes.

## [0.6.0] - 2026-02-09

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.30.0 (Diphda release)
- **Exception Imports**: Migrated from deleted legacy bridge class to modern exception namespace
  - `Glueful\Exceptions\ValidationException` → `Glueful\Validation\ValidationException` in `PaymentController` and `BillingPlanController`
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.30.0`, version bumped to `0.6.0`

### Notes
- No breaking changes to extension API. Import path change is internal.
- Requires Glueful Framework 1.30.0+ due to removal of legacy exception bridge classes.

## [0.5.1] - 2026-02-06

### Changed
- **Version Management**: Version is now read from `composer.json` at runtime via `PayviaServiceProvider::composerVersion()`.
  - `getVersion()` now returns `self::composerVersion()` instead of a hardcoded string.
  - `registerMeta()` in `boot()` already used `$this->getVersion()`, so it automatically benefits.
  - Future releases only require updating `composer.json` and `CHANGELOG.md`.

### Fixed
- **Version Mismatch**: `getVersion()` was returning `0.4.0` while `composer.json` specified `0.5.0`. All version references now read from `composer.json` as single source of truth.

### Notes
- No breaking changes. Internal refactor only.

## [0.5.0] - 2026-02-05

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.28.0
  - Compatible with route caching infrastructure (Bellatrix release)
  - Routes converted from closures to `[Controller::class, 'method']` syntax for cache compatibility
- **Route Refactoring**: All 9 payment routes now use controller syntax
  - Payment confirmation: `PaymentController::confirm`
  - Billing plans: `BillingPlanController::create`, `update`, `disable`, `index`
  - Invoices: `InvoiceController::create`, `markPaid`, `cancel`, `index`
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.28.0`

### Notes
- This release enables route caching for improved performance
- All existing functionality remains unchanged
- Run `composer update` after upgrading

## [0.4.0] - 2026-01-31

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.22.0
  - Compatible with the new `ApplicationContext` dependency injection pattern
  - No code changes required in extension - framework handles context propagation
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.22.0`

### Notes
- This release ensures compatibility with Glueful Framework 1.22.0's context-based dependency injection
- All existing functionality remains unchanged
- Run `composer update` after upgrading

## [0.3.0] - 2026-01-17

### Breaking Changes
- **PHP 8.3 Required**: Minimum PHP version raised from 8.2 to 8.3.
- **Glueful 1.9.0 Required**: Minimum framework version raised to 1.9.0.

### Changed
- Updated `composer.json` PHP requirement to `^8.3`.
- Updated `extra.glueful.requires.glueful` to `>=1.9.0`.

### Notes
- Ensure your environment runs PHP 8.3 or higher before upgrading.
- Run `composer update` after upgrading.

## [0.2.0] - 2025-11-17

### Added
- **Invoice Pagination**: Added pagination support to invoice listing endpoint
  - New `paginateWithFilters()` method in `InvoiceRepository` and `InvoiceRepositoryInterface`
  - Supports `page` and `per_page` query parameters in `GET /payvia/invoices` endpoint
  - Returns paginated response with metadata (total, per_page, current_page, last_page, etc.)
  - Advanced filtering support:
    - Filter by `status`, `user_uuid`, `billing_plan_uuid`
    - Filter by polymorphic relation (`payable_type`, `payable_id`)
    - JSON metadata filtering via `metadata_contains` (key-value search)

### Changed
- **Breaking**: `InvoiceService::list()` method signature updated
  - Old: `list(array $filters = []): array`
  - New: `list(int $page = 1, int $perPage = 20, array $filters = []): array`
  - Now returns paginated result structure instead of plain array
- `InvoiceController::index()` now uses `Response::successWithMeta()` for paginated responses
  - Response structure: `{ "data": [...], "total": N, "per_page": N, ... }`

## [0.1.2] - 2025-11-16

### Fixed
- **Critical**: Fixed incorrect namespace escaping in `composer.json`
  - Corrected PSR-4 autoload mapping from `Glueful\\\\Extensions\\\\Payvia\\\\` to `Glueful\\Extensions\\Payvia\\`
  - Fixed extension provider class name from `Glueful\\\\Extensions\\\\Payvia\\\\PayviaServiceProvider` to `Glueful\\Extensions\\Payvia\\PayviaServiceProvider`
  - This bug prevented the service provider from being discovered and loaded, which meant:
    - Extension routes were not registered
    - Migration directory was not registered (migrations were invisible to `php glueful migrate:run`)
    - Extension services were not available in the container
  - **Impact**: Extension now loads correctly and migrations are properly discovered

## [0.1.1] - 2025-11-16

### Changed
- Improved Paystack gateway normalization:
  - Prefer `gateway_response` as the human-readable message when available
  - Retain full raw payload under `verification['raw']` for downstream consumers
- Enriched payment `metadata` for Paystack payments with derived fields:
  - `customer_email`, `card_last4`, `card_brand`, `card_bank`, `channel`
  - Existing caller-provided metadata is merged, not replaced

## [0.1.0] - 2024-12-14

### Added
- Initial Payvia extension scaffolding using Glueful's modern extension system
- `PayviaServiceProvider` with proper metadata (`extra.glueful`) and service registration
- Generic `payments` table migration with:
  - `gateway`, `gateway_transaction_id`, `reference`
  - `user_uuid` and polymorphic `payable_type` / `payable_id` link
  - `metadata` and `raw_payload` JSON columns
- Gateway abstraction with `PaymentGatewayInterface` and `GatewayManager`
- `PaymentRepository` for persistence against the `payments` table
- `PaymentService::confirmAndRecord()` as the primary verification entrypoint
- Paystack gateway driver implementing `PaymentGatewayInterface`
- `PaymentController::confirm()` controller and routed endpoint:
  - `POST /payvia/payments/confirm` (auth + rate limiting)
- Configuration file `config/payvia.php` with env-driven gateway settings
- Generic billing helpers:
  - `billing_plans` table + repository + service for managing plans
  - `invoices` table + repository + service for creating and updating invoices
- Documentation in `README.md` covering installation, configuration, HTTP API, and schema/schema notes
