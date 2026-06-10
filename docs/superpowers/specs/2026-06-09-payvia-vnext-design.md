# Payvia v-next -- Boundary, Webhooks & Provider Subscriptions -- Design Note

**Status:** Design locked; ready for implementation planning. All decisions D1-D11 resolved (see Decisions). No code yet.
**Date:** 2026-06-09
**Repo:** `glueful/payvia` (standalone). This spec is self-contained.
**Companion:** `glueful/subscriptions` v1 (separate repo). The cross-cutting parent is the framework boundary note (`framework/docs/superpowers/specs/2026-06-08-subscriptions-payvia-boundary-design.md`); this spec restates the payvia-side constraints it needs so it stands alone.

---

## Why this spec exists

`glueful/subscriptions` will own tenant entitlement plans and app-facing subscription state, but it must consume *provider* state (a card was charged, a recurring subscription went past-due, an invoice was paid) from somewhere. Payvia is that somewhere. Today Payvia cannot supply it: it can only **verify a single payment reference on demand**. It has no way to *receive* asynchronous provider state (webhooks), no normalized representation of provider events, and no concept of a provider-side recurring subscription.

So Subscriptions cannot be specified cleanly until Payvia grows three things -- **webhook ingestion**, a **normalized provider-event seam**, and **persisted provider-subscription objects** -- and resolves one boundary smell: billing plans were carrying entitlement semantics that belong to Subscriptions.

This release grows Payvia's provider-event and subscription surface. Billing plans are priced/provider plans only; entitlements belong to `glueful/subscriptions`.

## Goals

1. Reframe `billing_plans` explicitly as **priced/gateway plans** and add gateway linkage fields.
2. Remove billing-plan entitlement fields from Payvia's schema and docs.
3. Add **webhook ingestion** with per-gateway signature verification and idempotency.
4. Add a **normalized provider-event seam** so downstream consumers never parse raw Stripe/Paystack/Flutterwave payloads.
5. Add **persisted provider-subscription objects** (durable, queryable, reconcilable) plus the events that keep them current.
6. Keep Payvia focused on payment/provider state and move entitlement semantics out of Payvia docs.

## Non-goals

- Tenant entitlements, quota policy, feature gates, `EntitlementCheckerInterface` -- all owned by `glueful/subscriptions`.
- Pulling `invoices` or priced plans *out* of Payvia (the boundary note explicitly rejects fragmentation for purity's sake).
- Implementing Flutterwave drivers. v-next defines the **capability seams**; Paystack and Stripe are concrete drivers.
- Usage metering / counters (a Subscriptions v1.1+ concern).
- A plan-management UI/CMS.

---

## Current surface (what stays / changes / is added)

| Surface today | v-next disposition |
|---|---|
| `PaymentGatewayInterface::verify()` | **Stays**, unchanged. |
| `GatewayManager` (driver map, only `paystack`) | **Grows** -- resolves optional capability interfaces (webhook, subscription) per driver. |
| `PaystackGateway` (verify only) | **Grows** -- implements the new webhook + subscription capabilities. |
| `payments` table + `PaymentService` | **Stays**. Webhook-driven payment upserts reuse it. |
| `invoices` table + `InvoiceService` | **Stays**. Webhook events may update invoice status. |
| `billing_plans` as a generic plan catalog | **Reframed** as priced/gateway plans; gains gateway linkage columns. |
| Billing-plan entitlement fields | **Removed** -- Payvia plans are priced/provider plans only. |
| Routes under `/payvia` (payments, plans, invoices) | **Stays**; new webhook (+ optional subscription read) routes added. |
| -- (no webhooks) | **Add** `POST /payvia/webhooks/{gateway}`. |
| -- (no normalized events) | **Add** `ProviderEvent` VO + `PaymentProviderEventInterface` + dispatch on the framework event bus. |
| -- (no provider subscriptions) | **Add** `gateway_subscriptions` table + service/repo/contract. |
| -- (no event idempotency) | **Add** `provider_events` table -- two-key (`delivery_key` + `logical_event_key`), status-aware, outbox dispatch. |

---

## Workstream 1 -- `billing_plans` as priced plans

### Reframe as priced/gateway plans

`billing_plans` keeps its identity as the **money side** of a plan and gains explicit gateway linkage so a priced plan can map to a provider product/price:

New (additive, all nullable) columns on `billing_plans`:

| Column | Type | Purpose |
|---|---|---|
| `gateway` | `string(50)` nullable | Which gateway this priced plan lives on (`paystack`, `stripe`, ...). Null = not yet linked / app-managed. |
| `gateway_product_id` | `string(100)` nullable | Provider product identifier. |
| `gateway_price_id` | `string(100)` nullable | Provider price/plan identifier (Paystack plan code, Stripe price id). |

Existing pricing fields (`amount`, `currency`, `interval`, `trial_days`, `status`) are exactly the priced-plan shape and stay.

### Remove entitlement fields

Billing-plan feature gates and usage limits are **entitlements** -- they belong to `glueful/subscriptions`. Payvia removes that surface before 1.0:

1. Do not create a `billing_plans.features` column.
2. Do not accept or return a `features` field in plan create/update/list flows.
3. Do not expose `features_key`/`features_value` filters.
4. Document in the README that tenant plans, feature gates, and overrides belong in `glueful/subscriptions`.

> **Decision D1 -- billing-plan entitlements.**
> - **(a) Remove `billing_plans.features` before 1.0 (resolved).** Cleanest boundary; Payvia owns priced/provider plans, Subscriptions owns entitlements.
> - (b) Rename to `legacy_features`. Signals intent in the schema, but creates churn and still keeps entitlement-shaped data in Payvia.
> - (c) Keep as metadata. Avoids a schema edit but preserves the ambiguity that caused this boundary work.
> Resolution: **(a)**.

> **Decision D2 -- gateway linkage granularity.** Do priced plans need their own `gateway_product_id` *and* `gateway_price_id` (two fields, Stripe-shaped), or is a single `gateway_plan_id` enough for v-next given Paystack is the only driver (Paystack has plan codes, no separate product)? Recommendation: ship **both nullable** now (cheap, future-proofs Stripe) but only populate `gateway_price_id`/plan-code for Paystack.

---

## Workstream 2 -- Webhook ingestion + idempotency

### Endpoint

```
POST /payvia/webhooks/{gateway}
```

- **No `auth` middleware** (providers can't carry a user session). Authenticity is established by **signature verification**, not the auth pipeline.
- Rate-limited generously (providers retry); never reject a *valid-signature* event for rate reasons -- see queueing below.
- `{gateway}` selects the driver (`paystack`, ...). Unknown/disabled gateway -> 404.

### Processing pipeline

```
raw request
  -> resolve driver for {gateway}
  -> driver.verifyWebhookSignature(rawBody, headers)         // reject 401 on failure
  -> driver.parseWebhookEvent(rawBody, headers): ProviderEvent
  -> compute delivery_key (provider delivery idempotency) + logical_event_key (business-event dedupe)

  -- INGEST (delivery idempotency) --
  -> upsert provider_events row on unique (gateway, delivery_key), branch on stored status:
        new                        -> insert status `received`, continue
        existing `processed`       -> exact redelivery -> re-run DISPATCH stage (outbox), then 200
        existing `received`/`processing` (in-flight or stale crash) -> resume / requeue, do not double-apply
        existing `failed`          -> increment attempts, reprocess

  -- APPLY (side effects) --
  -> mark `processing`; apply side effects via idempotent upserts (payment / invoice / gateway_subscription)
  -> mark `processed`   (side effects now durable; NOT yet dispatched)

  -- DISPATCH (outbox, atomic logical claim) --
  -> if any provider_events row with this (gateway, logical_event_key) is already `dispatched`
        -> already-delivered duplicate: mark own row dispatched, do NOT emit
     else atomically claim:
        UPDATE provider_events SET dispatch_status='dispatching', dispatch_claimed_at=:now
          WHERE gateway=:g AND logical_event_key=:k AND dispatch_status='pending'
        -> affected >= 1 (won)  -> emit PaymentProviderEvent; mark logical group `dispatched`, dispatched_at = now
        -> affected == 0 (lost) -> another worker is dispatching/dispatched -> skip emit
  (relay reclaim of stale `dispatching`: same UPDATE keyed on dispatch_claimed_at < now-timeout;
   advancing dispatch_claimed_at is a real change -> reliable affected-rows + removes the row from the stale predicate)
  -> 200

  (side-effect error  -> mark `failed`, record error + attempts, return 5xx so the provider retries)
  (crash after claim, before emit -> row left in `dispatching`; the relay re-claims stale `dispatching`/`pending` rows and emits exactly once -- never lost)
```

### Idempotency & audit -- `provider_events` table

Providers deliver at-least-once and retry, and the **same business fact can also arrive via on-demand `verify()`** (D6). These are two *different* idempotency problems, so the table carries **two keys**:

- **`delivery_key`** -- *provider-delivery* idempotency: did we already ingest **this exact provider message**? Native provider event id when present (Stripe `evt_...`), else a per-delivery synthetic (hash of the raw signed body). Unique; the ingest boundary.
- **`logical_event_key`** -- *business-event* idempotency: have we already dispatched **this logical fact** to listeners, regardless of path or delivery? Type-specific derivation (below). Gates the dispatch (outbox) stage so a fact is delivered to listeners once.

A single `event_key` cannot do both: native ids differ across paths (verify->`txn_123`, Stripe webhook->`evt_abc`) so cross-path dedupe needs the *logical* key; and a coarse `{type}:{entityId}` would wrongly collapse genuinely distinct **mutable** events (two real `subscription.updated`s for one sub) so delivery dedupe needs the *delivery* key.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk autoincrement | |
| `uuid` | `string(12)` unique | |
| `gateway` | `string(50)` | |
| `source` | `string(20)` | `webhook` or `verify` -- which path produced this row. |
| `provider_event_id` | `string(191)` **nullable** | Native provider event id when the gateway supplies one (Stripe `evt_...`). Nullable -- Paystack signs with `x-paystack-signature` but documents **no** stable event id. |
| `delivery_key` | `string(191)` | Provider-delivery dedupe key: native `provider_event_id`, else per-delivery synthetic (hash of raw signed body). **Unique** per gateway. |
| `logical_event_key` | `string(191)` | Business-event dedupe key (type-specific, below). **Indexed, not unique** -- many deliveries can map to one logical fact. |
| `type` | `string(100)` | Normalized event type (see Workstream 3). |
| `status` | `string(20)` | Side-effect lifecycle: `received` -> `processing` -> `processed` / `failed`. |
| `dispatch_status` | `string(20)` | Outbox: `pending` -> `dispatching` -> `dispatched`. Separate from `status` so a post-processing crash is replayable; `dispatching` is the in-flight atomic claim (see below). |
| `dispatched_at` | timestamp nullable | When the business event was emitted to the bus. |
| `dispatch_claimed_at` | timestamp nullable | When a worker last claimed the logical group for dispatch. Advanced on **every** claim (fresh and stale-reclaim), so the claim `UPDATE` always changes a column -- making affected-rows a reliable ownership signal even on drivers that report *changed* (not *matched*) rows (e.g. MySQL default). The stale-reclaim predicate filters on `dispatch_claimed_at < now - timeout`. |
| `attempts` | int default 0 | Processing attempts; incremented on retry/reprocess. |
| `signature_valid` | bool | Audit (webhook source). |
| `normalized_payload` | json nullable | Payvia's normalized event shape. **Not gated** -- always written -- so queued/relay replay can reconstruct the event without the provider raw body. |
| `raw_payload` | json nullable | Gated by `payvia.features.store_raw_payload` (provider escape hatch). |
| `error` | `string(255)` nullable | Last processing error. |
| `received_at` / `processed_at` | timestamps | |

- **Unique index `(gateway, delivery_key)`** is the ingest boundary -- the DB constraint (not an app-level read) wins races between concurrent provider retries. An insert conflict hands control to the **status-aware** branch in the pipeline: `processed` re-runs the dispatch stage (outbox), `received`/`processing` resumes, `failed` retries with `attempts`. A crash between `received` and `processed` is *recoverable*, not silently lost.
- **Outbox dispatch (`dispatch_status` + `dispatched_at`), atomically claimed.** `processed` means *side effects are durable* -- it does **not** mean listeners were notified. Dispatch is a separate stage gated by an **atomic claim** on `logical_event_key`:
  - **Fresh claim:** `UPDATE provider_events SET dispatch_status='dispatching', dispatch_claimed_at=:now WHERE gateway=:g AND logical_event_key=:k AND dispatch_status='pending'`. The `pending -> dispatching` transition genuinely changes the row, so affected-rows is reliable. affected >= 1 -> won -> emit `PaymentProviderEvent`, then mark the logical group `dispatched`; affected == 0 -> another worker already claimed/dispatched -> skip emit (concurrent workers serialize on row locks, so **exactly one** emits). A row already `dispatched` is an already-delivered duplicate (mark own row dispatched, no emit).
  - **Stale reclaim (crash recovery):** the relay (`payvia:relay-events`, inline after processing + periodic sweep) re-claims rows stuck in `dispatching` whose `dispatch_claimed_at < now - timeout` (a crash between claim and emit) with `UPDATE ... SET dispatch_status='dispatching', dispatch_claimed_at=:now WHERE ... dispatch_status='dispatching' AND dispatch_claimed_at < :cutoff`. Advancing `dispatch_claimed_at` is a real column change (reliable affected-rows even where the driver reports *changed* not *matched* rows) **and** moves the row out of the stale predicate so only one relay worker wins. Plus `pending` rows via the fresh claim. Each logical fact is emitted **exactly once and never lost**.
  - DB-portable across MySQL/Postgres/SQLite (plain `UPDATE ... WHERE` on indexed columns; the changing `dispatch_claimed_at` avoids the changed-vs-matched-rows pitfall).
- **`logical_event_key` derivation is type-specific** -- coarse for immutable facts, fine-grained for mutable ones:
  - **Immutable / point-in-time** (`payment.succeeded`, `payment.failed`, `invoice.paid`, `subscription.created`, `subscription.canceled`): `"{type}:{entityId}"` -- transaction id/reference for `payment.*`, provider invoice id for `invoice.*`, subscription id for the create/cancel terminal events. A `verify()`-origin `payment.succeeded` for `R` and a webhook charge-success for the same `R` collapse to one logical event -> dispatched once (closes the cross-path gap).
  - **Mutable / repeatable** (`subscription.updated`, `subscription.past_due`): `"{type}:{subId}:{discriminator}"`, where `discriminator` is the provider's version/sequence/`updated_at` (or, absent that, a stable hash of the normalized state). Two genuinely different updates produce different keys (not collapsed); an exact redelivery of the *same* update produces the same key (deduped). (D6)

> **Decision D3 -- sync vs queued processing.**
> - **(a) Verify + persist `received` synchronously, process side effects on the queue (recommended for prod).** Returns `200` fast (providers have tight timeouts), survives slow downstream work, retriable. Requires `glueful` queue.
> - (b) Fully synchronous. Simplest, no queue dependency, but a slow side effect can hit provider timeout -> provider retries -> more load.
> Recommendation: **support both**, controlled by `payvia.webhooks.queue` (default sync for zero-infra installs; queued when a queue connection is configured). Persist-then-process either way so a crash can't lose a verified event.

> **Decision D4 -- signature secret config.** Each gateway needs a webhook signing secret distinct from its API secret (Stripe `whsec_...`, Paystack uses the secret key + HMAC SHA512 of the body). Add `payvia.gateways.{gw}.webhook_secret`. Confirm naming: `PAYVIA_PAYSTACK_WEBHOOK_SECRET` (falls back to the API secret for Paystack, which signs with it).

---

## Workstream 3 -- Normalized provider events

Downstream consumers (Subscriptions, app code) must **never** parse raw provider payloads. Payvia normalizes every ingested webhook (and could normalize on-demand `verify()` results) into one shape.

### The value object + contract

```php
namespace Glueful\Extensions\Payvia\Contracts;

interface PaymentProviderEventInterface
{
    public function gateway(): string;                 // 'paystack'
    public function type(): string;                    // normalized type, see vocabulary
    public function providerEventId(): ?string;        // native provider event id, or null if the gateway has none
    public function deliveryKey(): string;             // provider-delivery idempotency (native id, else per-delivery hash)
    public function logicalEventKey(): string;         // business-event dedupe (type-specific; cross-path safe)
    public function occurredAt(): \DateTimeImmutable;
    /** Normalized, gateway-agnostic subset (ids, amounts, status, period). */
    public function normalized(): array;               // array<string,mixed>
    /** Untouched provider payload for escape-hatch consumers. */
    public function raw(): array;                      // array<string,mixed>
}
```

A concrete immutable `ProviderEvent` VO implements it. The boundary note's original sketch had `payload()`; this splits it into `normalized()` (the gateway-agnostic contract consumers should depend on) and `raw()` (escape hatch), so a consumer that sticks to `normalized()` is portable across gateways. `providerEventId()` is **nullable** -- not every gateway exposes a stable event id (Paystack documents only `x-paystack-signature`). The two key methods separate the two idempotency concerns: `deliveryKey()` dedupes an exact provider redelivery (native id, else a per-delivery body hash), while `logicalEventKey()` dedupes a business fact across paths and deliveries (type-specific -- coarse for immutable facts so verify+webhook collapse, fine-grained with a version/sequence discriminator for mutable `subscription.updated` so distinct updates don't). The driver computes both; the `provider_events` table (Workstream 2) keys ingest on `deliveryKey()` and gates dispatch on `logicalEventKey()`.

### Normalized event-type vocabulary (initial)

Gateway-agnostic, namespaced by domain:

| Type | Meaning |
|---|---|
| `payment.succeeded` | A one-off or subscription charge succeeded. |
| `payment.failed` | A charge failed. |
| `invoice.paid` | A provider invoice was paid. |
| `invoice.payment_failed` | A provider invoice attempt failed. |
| `subscription.created` | Provider recurring subscription created. |
| `subscription.updated` | Plan/quantity/period/status changed. |
| `subscription.past_due` | Entered dunning. |
| `subscription.canceled` | Canceled (immediate or end-of-period). |

Each gateway driver maps its own event names onto this set. Unmapped provider events are still persisted to `provider_events` (type `unknown`, raw kept) but dispatch no domain event.

### Delivery: framework event bus

Per Glueful conventions, normalized events are dispatched as a `BaseEvent` on the PSR-14 bus:

```php
final class PaymentProviderEvent extends \Glueful\Events\Contracts\BaseEvent
{
    public function __construct(public readonly PaymentProviderEventInterface $event) { parent::__construct(); }
}
```

Subscriptions (or app code) registers a listener in `config/events.php` and switches on `$e->event->type()`. **Persisted, not events-only:** the durable record lives in `provider_events` + `gateway_subscriptions`, so a consumer that missed a dispatch (was down, deployed) recovers by reading state or replaying -- see reconciliation (Workstream 4).

> **Decision D5 -- one event class vs a class hierarchy.**
> - **(a) Single `PaymentProviderEvent` carrying a typed VO; consumers switch on `type()` (recommended).** One subscription point, trivially extensible to new types, no event-class explosion. Matches the "consume normalized events" framing.
> - (b) One `BaseEvent` subclass per type (`ProviderSubscriptionCanceledEvent`, ...). More discoverable / IDE-friendly listener signatures, but a new class per provider concept and N listener registrations.
> Recommendation: **(a)** for v-next; revisit if listener ergonomics demand (b).

> **Decision D6 -- also emit normalized events from on-demand `verify()`?** When `POST /payvia/payments/confirm` verifies a reference, should it dispatch `payment.succeeded` too (so consumers have one event path)? Recommendation: **yes -- but route verify-origin events through the *same* `provider_events` outbox**, deduping on `logical_event_key`, so a manual confirmation followed by the provider's webhook for the same transaction dispatches **once**, not twice. Both paths get their own `delivery_key` row (full audit) but share one `logical_event_key`, so listeners (e.g. Subscriptions activating a plan) fire exactly once. This is what closes the cross-path double-emit hazard.

---

## Workstream 4 -- Persisted provider subscriptions

A provider-side recurring subscription is a durable object, not just an event. Subscriptions needs to *read* it and *reconcile* against it.

### `gateway_subscriptions` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk autoincrement | |
| `uuid` | `string(12)` unique | Payvia-side id. |
| `gateway` | `string(50)` | |
| `gateway_subscription_id` | `string(191)` | Provider subscription id. |
| `gateway_customer_id` | `string(191)` nullable | Provider customer id. |
| `billing_plan_uuid` | `string(12)` nullable | FK-less link to `billing_plans` (priced plan). |
| `gateway_price_id` | `string(100)` nullable | Provider price/plan code at time of sync. |
| `status` | `string(20)` | **Normalized** status: `active`, `trialing`, `past_due`, `canceled`, `incomplete`, `paused`. |
| `current_period_start` | timestamp nullable | |
| `current_period_end` | timestamp nullable | |
| `cancel_at_period_end` | bool default false | |
| `canceled_at` | timestamp nullable | |
| `metadata` | json nullable | App/provider metadata (may carry the app's `tenant_uuid`). |
| `raw` | json nullable | Last raw provider object. **Stored only when `payvia.features.store_raw_payload` is enabled** (same gating as `provider_events.raw_payload`); otherwise null. When retained, treat as sensitive (customer/payment detail) -- redact or encrypt per app policy. |
| `created_at` / `updated_at` | timestamps | |

- **Unique `(gateway, gateway_subscription_id)`.**
- **No `tenant_uuid` column owned here.** Payvia is tenancy-agnostic. The provider subscription carries whatever correlation id the app set in provider `metadata` (typically the app's tenant uuid); **mapping provider subscription -> tenant subscription is owned by `glueful/subscriptions`**, not Payvia. This keeps Payvia free of a tenancy dependency.

> **Decision D7 -- does Payvia store the tenant link at all?** Two clean options:
> - **(a) No tenant column in Payvia (recommended).** Payvia stays tenancy-agnostic; Subscriptions keeps the `gateway_subscription_id <-> tenant_uuid` map on its side. Strongest boundary.
> - (b) An opaque, FK-less `correlation_id` column Payvia copies from provider metadata, purely as a passthrough Subscriptions can match on. Convenience vs a little leakage.
> Recommendation: **(a)**. If matching ergonomics hurt, add (b) later -- it's additive.

### Reconciliation seam

Webhooks can be missed or arrive out of order, so `gateway_subscriptions.status` is an **eventually-consistent projection**. v-next adds the *seam* for pulling authoritative state; the *cadence/orchestration* lives downstream:

- A capability method on subscription-capable gateways to fetch current provider state by id (see gateway evolution).
- A Payvia service method `reconcile(string $gateway, string $gatewaySubscriptionId)` that pulls and upserts.
- **Out of v-next scope:** a periodic sweep job. Subscriptions owns its own `subscriptions:reconcile`; Payvia exposes the per-object pull it calls.

> **Decision D8 -- how much provider-subscription modeling ships in v-next.**
> - **(a) Full table + ingest-from-webhooks + single-object `reconcile()` pull (recommended).** Enough for Subscriptions v1 to build on; Paystack-only.
> - (b) Webhook-ingest only (no pull/reconcile seam) in v-next; add reconcile in v-next.1. Smaller, but Subscriptions can't recover from missed webhooks without it.
> Recommendation: **(a)** -- the reconcile pull is the whole reason to persist provider objects.

---

## Gateway interface evolution (capability segregation)

Rather than fattening `PaymentGatewayInterface`, add **optional capability interfaces** a driver may implement. `GatewayManager` resolves them and callers check support.

```php
// unchanged
interface PaymentGatewayInterface { public function verify(string $reference, array $options = []): array; }

// new -- a gateway that can ingest webhooks
interface WebhookCapableGateway
{
    public function verifyWebhookSignature(string $rawBody, array $headers): bool;
    public function parseWebhookEvent(string $rawBody, array $headers): PaymentProviderEventInterface;
}

// new -- a gateway that exposes recurring subscriptions
interface SubscriptionCapableGateway
{
    public function fetchSubscription(string $gatewaySubscriptionId): array;   // raw provider object
    public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): array;
}
```

- `GatewayManager` gains `supports(string $gateway, string $capability): bool` and typed resolvers (`webhookGateway()`, `subscriptionGateway()`) that throw a clear error if the driver lacks the capability.
- `PaystackGateway` implements all three in v-next. Stripe/Flutterwave stay unimplemented; attempting to use an unimplemented capability fails loudly, not silently.

> **Decision D9 -- capability interfaces (recommended) vs one growing interface.** Capability segregation keeps `verify`-only drivers valid, makes "does this gateway do webhooks/subscriptions?" explicit, and matches ISP. The alternative (one interface with default-throwing methods) is simpler but lies about what a driver supports. Recommendation: **capability interfaces**.

---

## Data model summary

**Altered:**
- `billing_plans` + `gateway`, `gateway_product_id`, `gateway_price_id`; no entitlement catalog fields.

**New tables (all migrate at `MigrationPriority::DEPENDENT`, source `glueful/payvia`, 12-char uuids -- matching existing payvia conventions):**
- `provider_events` -- cross-path (webhook + verify) idempotency + audit; two keys (unique `(gateway, delivery_key)` for ingest, indexed `(gateway, logical_event_key)` for dispatch dedupe); status-aware (`received`/`processing`/`processed`/`failed`) + outbox (`dispatch_status`/`dispatched_at`) with `attempts`.
- `gateway_subscriptions` -- persisted provider subscriptions, unique `(gateway, gateway_subscription_id)`.

No cross-package foreign keys (consistent with payvia's existing FK-less `user_uuid` policy).

---

## Config additions (`config/payvia.php`)

```php
'webhooks' => [
    'queue' => env('PAYVIA_WEBHOOKS_QUEUE', false),     // D3: false = sync, true = queued
],
'gateways' => [
    'paystack' => [
        // ...existing...
        'webhook_secret' => env('PAYVIA_PAYSTACK_WEBHOOK_SECRET', env('PAYVIA_PAYSTACK_SECRET_KEY', null)),
    ],
],
```

---

## Routes added in v-next

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/payvia/webhooks/{gateway}` | none (signature-verified) | Ingest provider events. |

The only route v-next adds is the webhook receiver. A provider-subscription read API is **deferred** (D10) -- Subscriptions reads `gateway_subscriptions` via the repository/service in-process, so no HTTP surface is required.

> **Decision D10 -- ship a provider-subscription read API in v-next? (Resolved: deferred.)** Subscriptions consumes provider state via events + direct repository reads (same install) or via reconcile, not over HTTP. A public `GET /payvia/subscriptions` is admin convenience, not required by the boundary. **Deferred**; add an HTTP admin API later if an admin UI needs it.

---

## The contract Payvia exposes to `glueful/subscriptions`

This is the concrete seam the downstream spec builds on:

1. **Priced plans** -- read `billing_plans` (now clearly priced/gateway plans) by uuid; `gateway`, `gateway_price_id` available for linkage. Entitlements are *not* here.
2. **Normalized events** -- subscribe to `PaymentProviderEvent` on the framework bus; switch on `type()`; depend only on `normalized()`.
3. **Provider subscriptions** -- read `gateway_subscriptions` (status, period, cancel flags); call Payvia's `reconcile()` to refresh.
4. **No tenancy coupling** -- Payvia never imports `glueful/tenancy`; Subscriptions owns the provider-subscription<->tenant mapping.

## Versioning

- Suggested version: **1.0.0** for the stable provider-event, webhook, provider-subscription, Paystack, and Stripe surface.
- The framework floor stays at `>=1.50.1`.

> **Decision D11 -- does v-next raise the framework floor? (Resolved: no.)** Webhooks reuse `Glueful\Http\Client`; events use `BaseEvent`/`EventService`; queue is the existing seam -- all present in the current floor (`>=1.50.1`). The additive `billing_plans` columns need `alterTable`, which is **confirmed present** in the framework schema layer. No new framework seam is required, so the floor **stays at `>=1.50.1`**.

## Out of scope (explicit)

- Flutterwave concrete driver.
- Tenant entitlements, quotas, `EntitlementCheckerInterface`, `RequireEntitlement` (Subscriptions).
- Periodic reconciliation sweep / scheduler (Subscriptions owns cadence).
- Usage metering.

## Build sequence (phases -- detailed in the implementation plan)

1. **Priced-plan reframe** -- `billing_plans` gateway linkage columns; remove billing-plan entitlement fields from Payvia. (D1, D2)
2. **Normalized event seam** -- `PaymentProviderEventInterface` (nullable `providerEventId()` + `deliveryKey()` + type-specific `logicalEventKey()`) + `ProviderEvent` VO + `PaymentProviderEvent` BaseEvent + type vocabulary + key derivation. (D5, D6)
3. **Provider-event dedupe + webhook ingestion** -- `provider_events` table (two-key, status-aware, outbox dispatch, `attempts`), signature-verify capability, ingest/dispatch branch, outbox relay (`payvia:relay-events`), controller/route, sync+queued processing, Paystack + Stripe signature impls. (D3, D4)
4. **Provider subscriptions** -- `gateway_subscriptions` table, service/repo, webhook upserts, `SubscriptionCapableGateway` + Paystack/Stripe impls, single-object `reconcile()`. (D7, D8, D9)
5. **Wiring & docs** -- service registrations, config, README/CHANGELOG, and the "contract exposed to Subscriptions" section. (D10, D11)

## Decisions (all resolved -- locked for implementation)

No open architecture decisions remain. Do not re-litigate these in the implementation plan.

| # | Decision | Resolution |
|---|---|---|
| D1 | Billing-plan entitlements | Remove `billing_plans.features`; entitlements belong in `glueful/subscriptions` |
| D2 | Gateway linkage granularity | Both `gateway_product_id` + `gateway_price_id` (nullable); populate only `gateway_price_id` for Paystack plan codes |
| D3 | Webhook sync vs queued | Support both; default sync for zero-infra installs, queued when `payvia.webhooks.queue = true` |
| D4 | Webhook secret config | `payvia.gateways.{gateway}.webhook_secret`; Paystack falls back to its API secret (that's how Paystack signs webhooks) |
| D5 | One event class vs hierarchy | Single `PaymentProviderEvent` carrying a typed `ProviderEvent` VO -- no event-class sprawl in v-next |
| D6 | Emit events from `verify()` too | Yes -- both paths share one `provider_events` outbox, dedupe on `logical_event_key` |
| D7 | Payvia stores tenant link? | No -- Payvia stays tenancy-agnostic; Subscriptions owns the provider-sub<->tenant map |
| D8 | Provider-subscription scope in v-next | Full `gateway_subscriptions` table + webhook upserts + single-object `reconcile()` |
| D9 | Capability interfaces vs fat interface | Capability interfaces (`WebhookCapableGateway`, `SubscriptionCapableGateway`) |
| D10 | Ship `GET /payvia/subscriptions`? | Defer -- service/repository access is enough; add HTTP admin API later if needed |
| D11 | Raise framework floor? | Keep `>=1.50.1` -- `alterTable` confirmed present in the framework schema layer, so additive columns need no bump |
| -- | Idempotency model | Two keys: `delivery_key` (ingest, unique) + `logical_event_key` (dispatch, type-specific) + outbox |
