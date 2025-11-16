# Changelog

All notable changes to the Payvia (Payments) extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned
- Additional gateway drivers (Stripe, Flutterwave, etc.)
- Webhook helpers and signatures verification utilities
- Payment listing and query endpoints

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
