# Changelog

All notable changes to the Payvia (Payments) extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned
- Additional gateway drivers (Stripe, Flutterwave, etc.)
- Webhook helpers and signatures verification utilities

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
