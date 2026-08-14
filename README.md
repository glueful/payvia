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

Payvia also auto-discovers the `payvia:relay-events` and `payvia:intents:sweep-stale` commands. If your app caches command metadata during deploy, rebuild that cache after enabling or upgrading the extension.

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

# How long a provider-CONFIRMED "session still live" answer is trusted before ensure-live
# asks the provider again. 0 always probes.
PAYVIA_SESSION_LIVENESS_COOLDOWN_SECONDS=30

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

    // How long a PROVIDER-CONFIRMED "this hosted session is still live" answer is trusted
    // before ensure-live asks the provider again. Only a confirmed-live probe refreshes the
    // stamp; dead/unknown answers never do, and a brand-new attempt is never suppressed.
    // Set to 0 to always probe.
    'session_liveness_cooldown_seconds' => (int) env('PAYVIA_SESSION_LIVENESS_COOLDOWN_SECONDS', 30),

    'gateways' => [
        'paystack' => [
            'enabled' => (bool) env('PAYVIA_PAYSTACK_ENABLED', true),
            'driver' => 'paystack',
            'secret_key' => env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null)),
            'webhook_secret' => env('PAYVIA_PAYSTACK_WEBHOOK_SECRET', env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null))),
            'base_url' => env('PAYVIA_PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'timeout' => (int) env('PAYVIA_PAYSTACK_TIMEOUT', 15),
            // Hosted-redirect trust boundary: the ONLY hosts a returned `authorization_url`
            // may live on. File-config only -- not overridable via PayviaSettings.
            'checkout_hosts' => ['checkout.paystack.com'],
        ],
        'stripe' => [
            'enabled' => (bool) env('PAYVIA_STRIPE_ENABLED', false),
            'driver' => 'stripe',
            'secret_key' => env('PAYVIA_STRIPE_SECRET_KEY', null),
            'webhook_secret' => env('PAYVIA_STRIPE_WEBHOOK_SECRET', null),
            'webhook_tolerance' => (int) env('PAYVIA_STRIPE_WEBHOOK_TOLERANCE', 300),
            'base_url' => env('PAYVIA_STRIPE_BASE_URL', 'https://api.stripe.com'),
            'timeout' => (int) env('PAYVIA_STRIPE_TIMEOUT', 15),
            // Same trust boundary as paystack above, applied to the Checkout Session `url`
            // for both one-time and subscription sessions.
            'checkout_hosts' => ['checkout.stripe.com'],
        ],
    ],

    'features' => [
        'store_raw_payload' => (bool) env('PAYVIA_STORE_RAW_PAYLOAD', true),
    ],

    'security' => [
        // Three ordered profiles composed onto every /payvia/* route except the webhook
        // route (which stays signature-authenticated/tenantless). See "Middleware profiles
        // and tenancy" below.
        'auth_middleware' => ['auth'],
        'tenant_context_middleware' => [],
        'manage_middleware' => ['admin'],
    ],

    'webhooks' => [
        'queue' => (bool) env('PAYVIA_WEBHOOKS_QUEUE', false),
        'queue_name' => env('PAYVIA_WEBHOOKS_QUEUE_NAME', 'default'),
        'relay_stale_seconds' => (int) env('PAYVIA_WEBHOOKS_RELAY_STALE_SECONDS', 300),
    ],
];
```

## Hosted Payment Initiation (the payable metadata convention)

`PayviaPaymentCollector` starts hosted payment flows through `InitiationCapableGateway`
(Paystack redirect pages; Stripe Checkout Sessions). It is **payable-type-agnostic**: it never
inspects `payable_type`, and per-consumer parameters are never threaded through it. Instead,
whoever *builds* a `PayableReference` supplies three well-known `metadata` keys, and the
collector lifts them into the gateway options once:

| Metadata key   | Meaning |
|----------------|---------|
| `email`        | The payer's email (Paystack requires it; Stripe pre-fills the session). |
| `callback_url` | Absolute HTTPS URL the visitor returns to after paying (Stripe: REQUIRED — session creation throws without it; Paystack: falls back to the dashboard callback). |
| `cancel_url`   | Absolute HTTPS URL for an abandoned Stripe session; falls back to `callback_url`. |

An order flow, a subscription flow, and an invoice flow each set their own values when
constructing their payable — nothing here is order-specific. Two invariants:

- **Webhooks stay the settlement authority.** The callback/cancel URLs are browser navigation
  only; payment truth always comes from webhook verification (`verify()` / provider events).
- **Initiation exceptions propagate.** The collector has no catch — mapping failures (e.g. to an
  `init_failed` result) is the calling application's job.

Stripe session creation sends a deterministic per-**attempt** `Idempotency-Key` and validates the
response (a `cs_…` session id and an absolute HTTPS checkout URL) before any intent is persisted.

### Ensure-live: `initiate()` is fallible on every call

A call against a payable with no open intent claims a fresh attempt and creates a session, as
above. A call against a payable that already has an open intent no longer unconditionally hands
back the cached checkout URL — it asks the provider to prove the session is still live first:

| Provider answer | Behavior |
|---|---|
| confirmed live, or completed | the SAME checkout URL is returned; no new session is created. |
| confirmed live, but the payable has been REPRICED | the attempt is superseded and a NEW attempt/session is claimed at the current amount — a session created for one total is never served for another. |
| confirmed dead + renewal proof | the retired attempt is superseded (its reference stays webhook-addressable) and a NEW attempt/session is claimed. |
| unknown (unreadable answer, probe exception, ambiguous abandon result) | throws `ProviderSessionStateUnknownException`; the existing intent is left untouched. |
| dead, but the gateway cannot prove/renew | throws `SessionRenewalUnavailableException` (Paystack — see below). |
| gateway not state-capable / different gateway / no reference yet | nothing is provable, so the open intent is returned unchanged. |

**This is a behavior change from 2.5.0**, where a repeat `initiate()` against an open intent
always returned `ok` with the cached URL and never touched the provider. Callers must now treat
*every* `initiate()` call as fallible — not just the first one for a payable — and handle the two
typed exceptions above alongside the pre-existing "initiation exceptions propagate" contract.

A repeated attempt (e.g. the same visitor's browser retrying the request) reuses the SAME
provider idempotency key/reference as the original attempt, so a genuine transport timeout
resolves to one session, not two; only a NEW attempt (a fresh claim after supersession) gets a
new one.

Reuse compares the payable's **current** amount/currency against the ones the intent row stored
when its session was created — on the probe-confirmed branch *and* on the liveness-cooldown
branch, which skips the provider round trip but never the price check. A mismatch (an order
edited between two initiations), or an intent row with no stored price at all, takes the renewal
path above rather than serving a checkout URL for the old total.

### Stale-intent sweeper

An `initializing`/`open` intent whose payable is never resolved holds that payable's active
idempotency port indefinitely, and the table grows without bound. `payvia:intents:sweep-stale`
retires rows untouched (`COALESCE(updated_at, created_at)`) for longer than
`payvia.intents.stale_after_days` (`PAYVIA_INTENTS_STALE_AFTER_DAYS`, default 30, clamped to
1–365) to `failed`, through the same re-keying compare-and-swap every terminal transition uses:

```bash
php glueful payvia:intents:sweep-stale [--limit=200] [--stale-after-days=30]
```

Age is the only criterion — nothing in the table can honestly answer whether a payer is still
coming back — and that is safe because sweeping is not destructive: the row is kept (its provider
reference stays webhook-addressable, so a late settlement still resolves to it) and its port is
freed, so a payer who returns later simply gets a brand-new attempt from the create path above.
One run retires at most `--limit` rows, oldest first; concurrent runs are safe (per-row CAS), and
a sweep is scoped to the tenant resolved for the run.

### Provider liveness details

- **Stripe** reads the Checkout Session's `status`/`payment_status`: `open` is live;
  `complete` is only `completed` once `payment_status` confirms it (`paid` or
  `no_payment_required`) — an unsettled async payment method keeps a `complete` session **live**;
  `expired`/`canceled` is dead. Renewal expires the session and re-fetches, trusting only the
  re-fetch's result as proof of death.
- **Paystack** treats its own non-terminal transaction states
  (`abandoned`/`ongoing`/`pending`/`processing`/`queued`), *including* `failed`, as **live** — a
  shopper revisiting the same checkout page or trying another payment option should not be
  treated as dead. Only `reversed` is dead. Paystack does **not** implement session renewal: it
  has no provider-side proof of death to renew against, so a genuinely dead Paystack session
  surfaces `SessionRenewalUnavailableException` rather than being silently replaced.
- **Paystack verify-first recovery.** `/transaction/initialize` is not idempotent — a repeated
  reference is a permanent error — while `/transaction/verify` is. When the collector resumes an
  existing (not brand-new) attempt, it verifies before ever calling initialize again: an absent
  transaction re-initializes under the same attempt; a paid one is adopted (recorded, never
  re-created); an unpaid or reversed one fails the retired attempt and claims a new one.
- **Operational requirement:** the Paystack integration setting `payment_session_timeout` must
  stay at `0` (infinite). A non-zero value silently dead-ends a resumed checkout page while
  `/transaction/verify` still reports it `abandoned` — Payvia cannot tell that apart from live.

### Checkout URL trust boundary

Every hosted checkout URL a gateway driver returns (Stripe Checkout, Stripe subscription
Checkout, and Paystack's `authorization_url`) is validated against a per-gateway host allowlist,
`gateways.{stripe,paystack}.checkout_hosts` (see [Configuration](#configuration)), before it can
reach an intent payload: absolute HTTPS, case-normalized *exact* host match (no subdomains, no
ports, no userinfo/credentials, no trailing dot, no whitespace/control characters). A rejected
URL throws inside the gateway — nothing untrusted is ever persisted.

**This key is file-config only.** Unlike most `payvia.*` settings, `checkout_hosts` is
deliberately **not** overridable via `PayviaSettings` — it is a platform-owned trust boundary,
not a runtime-editable one. A merchant serving Stripe Checkout on their own custom domain must
extend `gateways.stripe.checkout_hosts` directly in `config/payvia.php`.

### Liveness cooldown

`payvia.session_liveness_cooldown_seconds` (default `30`; `0` disables) suppresses a repeat
provider liveness probe for that long after the last confirmed-live answer for a given intent,
so a shopper clicking "pay" repeatedly can't turn one checkout into a stream of provider round
trips. Only a confirmed-live probe extends the window; a dead/unknown answer or a brand-new
attempt is never suppressed.

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

### Reference-addressable confirmation

A payable can carry a retired (`superseded`/`closed`/`failed`) session attempt alongside its
current open one — each with its own provider reference, once a session has been renewed (see
[Ensure-live](#ensure-live-initiate-is-fallible-on-every-call) above). `ConfirmationDispatcher::dispatch()`
resolves the row to close by the confirmation's own `(gateway, reference)` via
`PaymentIntentRepository::findByReference()` — an exact lookup on the composite
`UNIQUE(tenant_uuid, gateway, reference)` index — rather than "whichever attempt is open for this
payable." A webhook confirming a superseded attempt's reference settles exactly that retired row
(`::settle()`, a CAS to `closed` from `open`/`superseded`/`failed`) and never touches a newer open
attempt for the same payable; an unmatched reference remains a no-op. `dispatch()` takes the
gateway key as a required parameter for this lookup.

### Strict Delivery and Listener Contracts

Payvia supports an **opt-in strict-delivery lane** for consumers that require guaranteed at-least-once event delivery and are willing to pay the cost of mandatory idempotency.

#### Strict Listener Interface

Implement `Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener`:

```php
namespace Glueful\Extensions\Payvia\Contracts;

interface StrictPaymentEventListener
{
    public const CONTAINER_TAG = 'payvia.strict_payment_event_listeners';

    public function supports(PaymentProviderEventInterface $event): bool;

    public function handle(PaymentProviderEventInterface $event): void;
}
```

Register your listener from your extension's **static** `services()` map, and publish the tag with the definition-level `'tags'` key on the listener's own definition:

```php
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;

public static function services(): array
{
    return [
        // ... other services ...
        MyStrictEventListener::class => [
            'class' => MyStrictEventListener::class,
            'shared' => true,
            'autowire' => true,
            'tags' => [StrictPaymentEventListener::CONTAINER_TAG],
        ],
    ];
}
```

> **`static tags()` does not work here.** `ContainerFactory::loadExtensionDefinitions()` only consults a provider's static `tags()` for typed `defs()`-based providers; for a `services()`-based (DSL) provider the tag comes exclusively from each definition's own `'tags'` key. A `services()`-based provider that publishes the tag via `tags()` is silently registered untagged, and `composeStrictLane()` never sees the listener. See the §3 correction in `docs/superpowers/specs/2026-08-02-strict-payment-event-lane-design.md`.

#### Listener Obligations

Strict listeners are invoked in a **deterministic, FQCN-sorted order** between ordinary (fault-isolated) listeners and chargebacks. **A listener exception prevents dispatch-marking and produces a retryable delivery** — both inline (non-2xx webhook response) and queued (retried job).

Implementations **MUST be idempotent** — delivery is at-least-once by design:

- **Idempotency:** a single business fact may be delivered multiple times (e.g. after a sibling listener fails, the strict lane re-runs from the start). Your handler must detect and skip duplicates or apply the same logic safely multiple times without side effects.
- **At-least-once:** a handler failure leaves the delivery marked retryable; a subsequent delivery will reinvoke all strict listeners, including those that already succeeded. Design handlers to tolerate re-execution.
- **Failure is observable:** if your handler throws, the exception propagates (after releasing the internal lease), producing a `500` response in inline mode or a retried job in queue mode. Payvia does **not** log or swallow strict listener exceptions — that's your contract: throw to signal "this delivery must be retried."

#### Chargeback Lane

The existing chargeback delivery lane (the final step) is **also strict** — exceptions prevent dispatch-marking and produce retryable delivery. Chargeback listeners already operate under the at-least-once / idempotency contract; no change is required, but the timing of re-execution has improved (immediate retry instead of waiting for stale-lease recovery).

## Provider Subscriptions

Payvia persists gateway-owned subscription state in `gateway_subscriptions` and exposes `GatewaySubscriptionService::reconcile($gateway, $gatewaySubscriptionId)`. It stays tenancy-agnostic: tenant ownership and entitlement decisions belong to `glueful/subscriptions`.

`gateway_subscriptions` stores provider subscription state only. It intentionally does not store tenant ownership; `glueful/subscriptions` owns the tenant-to-provider-subscription map and all entitlement decisions.

The stored `status` is **normalized** and **fails closed**: provider statuses are mapped to one of `active`, `past_due`, `canceled`, `incomplete`, `paused`, or `unknown`. Only the explicitly active provider statuses (`active`, `trialing`) become `active`; any unrecognized, future, or missing provider status is recorded as `unknown` (never silently treated as live). Consumers deciding entitlement should treat anything other than `active` as not entitled.

## Subscription Checkout

Payvia can start a provider-hosted checkout for a brand-new subscription — as opposed to `GatewaySubscriptionService`'s existing management of an already-existing one — through `SubscriptionCheckoutService`, backed by its own origination ledger. This is a separate, additive surface: nothing above (payments, webhooks, `gateway_subscriptions` projection) changes shape or behavior.

### Capability probing

A gateway opts in by implementing `SubscriptionInitiationCapableGateway` (required), and optionally `SubscriptionCheckoutLifecycleCapableGateway` (on-demand status/abandonment) and `SubscriptionCancellationModeProvider` (self-serve cancellation modes). Probe before use — never assume:

```php
$manager->supports('stripe', 'subscription_checkout');  // true
$manager->supports('stripe', 'cancellation_modes');     // true
```

### The `prepare()` / `initializeClaim()` seam

`SubscriptionCheckoutService` deliberately splits origination into two calls so that provider I/O never happens inside a database transaction:

- **`prepare(ApplicationContext $context, SubscriptionCheckoutRequest $request, callable $bindLocalReservation)`** owns exactly one transaction. It validates the gateway/plan identifier before any write, then claims (or — for a repeated `idempotencyKey` with a matching request fingerprint — idempotently *replays*) an origination row, claims the caller's subject guard only for a genuinely new claim, invokes `$bindLocalReservation` (your own local reservation, e.g. a pending host-side subscription record) inside the same transaction, and advances the row `preparing -> initializing`. A repeated `idempotencyKey` whose request shape has changed is a hard conflict (`IdempotencyConflictException`), never a silent overwrite. Everything after the claim rolls back together on any exception.
- **`initializeClaim(ApplicationContext $context, string $originationUuid)`** is a later, separate call. It acquires a narrow 120-second initialization lease (reclaimable if stale), calls the gateway driver *outside* of any transaction, and persists the outcome with a single atomic compare-and-swap. A concurrent second caller for the same origination never touches the provider at all — it either waits out the lease or observes the already-persisted result.

A **definitive** provider rejection (`DefinitiveSubscriptionCheckoutRejection`) marks the origination `failed` and releases the subject guard immediately, so the subject may originate another attempt. Any other failure is treated as **unknown**: only the execution lease is released — status, idempotency key, and the guard are left untouched so a retry can safely call the provider again with the same idempotency semantics.

### Ledger semantics

`subscription_checkout_originations` is the permanent correlation identity for a checkout attempt — its opaque `uuid` is stamped into provider metadata (Stripe: `subscription_data.metadata.origination_uuid`) so a webhook arriving after any terminal state still correlates back to it. `subscription_checkout_subject_guards` is a separate, narrower authority answering one question only — "may this subject originate another checkout right now?" — and is never inferred from the ledger or any client-side TTL, because a hosted checkout may complete well after a client would expect it to have expired.

`customer_email`, stored only for initialization-crash recovery, is force-cleared the instant an origination reaches a definitive (terminal) outcome.

Ownership is resolved from the ledger, never invented by a webhook: when a provider event carries a resolvable `origination_uuid`, the origination row's own `tenant_uuid` is adopted by the `gateway_subscriptions` projection (never a bare metadata hint). A webhook that lands after a *newer* origination already owns the subject is recorded as `late_settlement_conflict` — a permanently terminal, operator-visible state — rather than silently re-activating a superseded attempt.

### Acknowledgement contract for consumers

If your host requires its own durable confirmation before an origination is considered complete (e.g. a subscriptions package that must record entitlement before Payvia calls the flow finished), set `requiredProjectionConsumer` on the `SubscriptionCheckoutRequest`. Payvia then withholds the origination's `dispatched` status until your consumer calls back through the `SubscriptionProjectionAcknowledger` contract:

```php
$acknowledger->acknowledge(
    originationUuid: $originationUuid,
    consumer: 'subscriptions',
    logicalEventKey: $event->logicalEventKey(),
    outcome: 'accepted',   // or 'rejected'
    reason: null,          // required context when rejecting
);
```

- Call this **after** your projection has durably committed its own side of the subscription, in response to the correlated `subscription.created` delivery — never speculatively.
- A duplicate delivery must re-compute and re-call with the **same** outcome; Payvia treats a repeat of the identical `(originationUuid, consumer, logicalEventKey, outcome)` tuple as an idempotent no-op, not a failure. This is what makes crash recovery between "projection committed" and "acknowledgement sent" safe to simply retry.
- A **conflicting** second outcome for the same `logicalEventKey` throws — Payvia never silently overwrites a committed verdict.
- `accepted` lets Payvia's post-dispatch finalizer advance the origination `provider_observed -> dispatched` and release the subject guard, atomically. `rejected` moves the origination to operator-visible `projection_rejected` and leaves the guard held for reconciliation — the underlying provider event still finishes dispatching either way.
- If your consumer never acknowledges a delivery that required one, Payvia raises `RequiredProjectionAcknowledgementMissing` and the delivery is retried — there is no silent timeout to `dispatched`.

### Operator reconciliation

A checkout stuck at `pending` (no webhook ever arrived) or resolved to `projection_rejected` / `late_settlement_conflict` is not a dead end. `CheckoutReconciliationService::resolve()` exposes exactly two explicit outcomes — there is no generic "ignore":

- `provider_confirmed_dead` — the operator has verified externally that no payment or subscription was ever created. Only legal from `pending`; advances the origination to `abandoned` and reopens the subject guard.
- `provider_canceled_or_refunded` — the operator has already canceled/refunded on the provider side. Only legal from `projection_rejected` or `late_settlement_conflict` (both keep their own status — this only updates the audit trail) and reopens the subject guard.

Both write a bounded audit note into dedicated `reconciliation_resolution`/`reconciliation_note`/`reconciled_at` columns, never into the projection consumer's own committed `projection_reason` receipt.

### Cancellation modes

Probe `SubscriptionCancellationModeProvider::cancellationModes()` (or `GatewayManager::supports($gateway, 'cancellation_modes')`) before offering a self-serve cancel option — do not assume every gateway honors both modes over its existing `cancelSubscription()`:

- Stripe: `stop_renewal` and `immediate`.
- Paystack: `stop_renewal` only.

### Paystack: subscription checkout is unavailable

**Paystack does not support hosted subscription checkout in Payvia**, and this is a deliberate, sandbox-proven limitation rather than a gap. A 2026-08-04 sandbox run established that Paystack's API gives no non-ambiguous way to join a checkout attempt to the subscription it produces: `subscription.create` carries no transaction metadata or reference at all, and `charge.success` carries no subscription identifier. There is no field pair either event exposes that correlates the two without guessing.

Consequences:

- `PaystackGateway` does not implement `SubscriptionInitiationCapableGateway`.
- `GatewayManager::supports('paystack', 'subscription_checkout')` is `false`.
- `SubscriptionCheckoutService::prepare()` targeting `paystack` throws `CheckoutUnavailableException` before any ledger or subject-guard row is written — **there is no fallback to a one-time payment.**
- Paystack's existing webhook projection (`gateway_subscriptions`) and operator-created subscription support are completely unaffected — only *hosted checkout origination* is unavailable.

The negative proof is committed permanently as fixtures under `tests/Fixtures/paystack-checkout/` (produced by the `payvia:checkout:sandbox-proof` console command) and pinned by a regression suite.

**Revisit trigger:** this will be reconsidered only if Paystack starts propagating transaction metadata onto `subscription.create`, or starts including a subscription identifier (e.g. `subscription_code`) in `charge.success`. Until then, a consuming application must offer subscription checkout through another gateway (e.g. Stripe) for tenants on Paystack.

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

### Middleware profiles and tenancy

Every `/payvia/*` route except the webhook route (which stays
signature-authenticated/tenantless) composes three ordered, independently
configurable middleware profiles:

```php
// config/payvia.php (application override — neutral defaults shown; single-store
// installs can leave this block out entirely)
return [
    'security' => [
        // Profile 1 — authentication.
        'auth_middleware' => ['auth'],

        // Profile 2 — tenant context. Empty by default; a tenancy-enabled host sets
        // this to whatever establishes request-scoped tenant context before Payvia's
        // repositories run (Payvia never names or hardcodes host-specific aliases here).
        'tenant_context_middleware' => [],

        // Profile 3 — authorization for the management (write) routes only.
        'manage_middleware' => ['admin'],
    ],
];
```

Authenticated read/confirm routes compose profile 1 → 2; management (write) routes
compose 1 → 2 → 3. Each write route still appends its own `rate_limit:N,60` after the
composed stack. Override `manage_middleware` to swap `admin` for a custom permission
middleware (e.g. `['permission:billing.manage']`), or `tenant_context_middleware` to
plug in your tenancy resolution middleware.

**Tenancy note:** Payvia's repositories resolve the current tenant via a local
`PayviaTenantResolver` seam — consuming your app's `CurrentTenantResolver` (wrapped
fail-closed) when bound, or a sentinel when it isn't — so multi-workspace hosts get
per-tenant business keys (invoice numbers, plan names, payment-intent idempotency
keys) while single-store installs remain byte-identical.

> **Upgrading from 1.x:** if your app previously overrode
> `payvia.security.manage_middleware`, split it: move authentication entries (e.g.
> `auth`) into `auth_middleware` and leave only authorization checks in
> `manage_middleware`. See `CHANGELOG.md` for the exact before/after.

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
- `amount` (integer, minor units of `currency`, e.g. cents)
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
- `amount` (integer, required) — minor units of `currency` (e.g. cents; `9900` = $99.00)
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
    "amount": 9900,
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

- `amount` (integer, required) — minor units of `currency` (e.g. cents; `9900` = $99.00)
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
    'amount' => 9900, // minor units of `currency` (cents); $99.00 = 9900
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
    'amount' => 9900, // minor units of `currency` (cents); $99.00 = 9900
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
