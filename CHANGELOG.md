# Changelog

All notable changes to the Payvia (Payments) extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

### Planned
- Flutterwave gateway driver.
- PayPal/Braintree gateway driver.
- Adyen gateway driver.
- Checkout.com gateway driver.
- Optional split-payment (split-at-charge) capability interface — marketplace *payouts* landed in 2.1.0 via `TransferCapableGateway`; splitting a single charge across recipients at capture time is still pending.
- Optional `RefundCollector` gateway binding — dispute ingestion landed in 2.1.0; refunds still fall back to commerce's manual path.

## [Unreleased]

### Added
- Declares the Glueful schema manifest (migration descriptors, requires.extensions, structural
  verifier); requires framework >=1.79.0 for schema-on-enable participation. Migrations are now
  registered by the manifest, not by provider boot.


## [2.7.0] - 2026-08-14 — Attribution-Bound Confirmations

Payvia closes two gaps adjacent to the reference-addressable session lifecycle 2.6.0 built.
`POST /payvia/payments/confirm` now enforces that a caller-supplied payable agrees with the
reference's own `payment_intents` binding, and stops mistaking an already-settled redelivery for
a late payment. A new sweeper reclaims `payment_intents` rows abandoned before a session was ever
confirmed, so an abandoned checkout no longer wedges its payable's idempotency port forever. And
confirmed-live session reuse now revalidates the payable's price before handing back a cached
checkout URL, closing the gap where a mid-checkout reprice could serve — or, on Paystack, leave
alive — a stale-priced session. **Upgrading requires clearing any compiled service container** —
see Notes.

### Security

- **Confirm refuses a payable-attribution mismatch.** `POST /payvia/payments/confirm` now reads
  the reference's own `payment_intents` binding before any provider I/O or `payments` write and,
  when a caller-supplied `payable_type`/`payable_id` disagrees with it, refuses with a typed
  `PayableAttributionException` mapped to `409` (`error.details.reason = "payable_mismatch"`).
  Previously two equal-amount, equal-currency payables were indistinguishable to the downstream
  handlers, so an authenticated caller could attribute a reference they legitimately paid to a
  DIFFERENT pending payable and have it marked paid. The refusal message deliberately never names
  the payable the reference IS bound to, and the lookup stays tenant-scoped, so another tenant's
  reference is invisible rather than a cross-tenant existence oracle. A reference with no intent
  row (legacy rows, manual/operator references) is unaffected — there is nothing to compare it
  against.

### Fixed

- **Same-reference redelivery of an already-settled confirmation now returns success instead of
  recording a spurious `payment_late_rejected`.** Two orderings are covered: a nested strict-lane
  listener settling THIS call's own reference during `recordVerifyEvent()`, and the more common
  out-of-band ordering — a provider webhook settles and closes the intent first, and only later
  does an operator replay the identical reference through the manual confirm route. Both are now
  recognized as redelivery, not lateness, by re-reading the reference's own intent status. A
  genuinely late confirmation — a different reference/attempt against a payable something else
  already paid — is unaffected: it still dispatches and is still recorded late.

### Added

- **Loud guard against a stale compiled container.** `PaymentService::confirmAndRecord()` now
  throws `\LogicException` immediately if it was constructed without a `PaymentIntentRepository`
  — the shape a container compiled before this release still produces (the pre-2.7.0,
  five-argument constructor). Previously that shape would have silently disabled every confirm
  guard above with no error and no log. See Notes for the upgrade step this requires.
- **Stale-intent sweeper** — `payvia:intents:sweep-stale` retires `initializing`/`open`
  `payment_intents` rows untouched (`COALESCE(updated_at, created_at)`) for longer than
  `payvia.intents.stale_after_days` (`PAYVIA_INTENTS_STALE_AFTER_DAYS`, default 30, clamped
  1–365), freeing each payable's active idempotency port. Batched (`--limit`, default 200),
  per-row compare-and-swap, non-destructive: a swept row keeps its `reference`, so a late webhook
  still settles it via `findByReference()`/`settle()`. `--tenant` (backed by a new, narrowly
  scoped `ExplicitTenantResolver`) names the partition on tenancy-enabled hosts, where an
  unqualified CLI run is correctly refused — a command has no request to resolve a tenant from.
- **`RENEWAL_STILL_LIVE` now revalidates price too**, behind the same renewal-capable guard as
  the other confirmed-live paths, so a reprice is caught on every route to "this session is still
  live," not just the two most common ones.

### Changed

- **Reuse revalidates the price, and repricing obeys Ruling 6.** A confirmed-live session is
  reused only if the payable still costs what the session was created for — checked on the probe
  branch, the liveness-cooldown branch (a reprice *invalidates* the cooldown and forces one probe,
  so a session that completed inside the window is never blindly superseded), and the
  abandon-contradicts-the-probe branch. On drift, a renewal-capable gateway (Stripe) supersedes and
  opens a fresh attempt at the current amount; a gateway that cannot prove a session dead
  (Paystack) throws `SessionRenewalUnavailableException` and leaves the intent untouched, rather
  than leaving two simultaneously-payable checkout URLs alive. `STATE_COMPLETED` sessions are
  never superseded by a price change — settlement is the webhook's business.

### Notes

- **Upgrade obligation: clear any compiled container.** Autowiring resolves `PaymentService`'s
  new sixth constructor argument automatically on a fresh build, but a container compiled before
  this release still constructs the pre-2.7.0, five-argument shape — which now fails LOUD
  (`\LogicException`) on every confirm instead of silently running the route unguarded. Clear or
  rebuild the container cache as part of upgrading.
- **Operational obligation: schedule the sweeper.** Nothing runs `payvia:intents:sweep-stale` on
  its own. Single-store hosts should add a cron entry (daily is ample at the 30-day default
  window: `php glueful payvia:intents:sweep-stale`); tenancy-enabled hosts must either loop
  `--tenant` over their tenants or schedule `StaleIntentSweeper::sweep()` through their own
  per-tenant scheduler (e.g. tenancy's `ForEachTenant`).
- **Paystack repricing is now a hard stop, not a silent replacement.** A payable repriced while
  its Paystack checkout is open cannot be renewed — Paystack has no provider-side proof of death
  to renew against — so `initiate()` throws `SessionRenewalUnavailableException` and the payable
  is stuck until an operator marks it paid out-of-band or performs an explicit,
  risk-acknowledged cancel/recreate. Hosts should map this exception to an honest operator-facing
  message rather than a generic failure.

## [2.6.0] - 2026-08-11 — Ensure-Live Hosted Sessions

Payvia's hosted-session lifecycle becomes reference-addressable end to end: every session
attempt is provable, renewable, and independently webhook-settleable, closing the gap where a
stale or replayed checkout session could be silently trusted, misattributed, or fabricated as a
success with nothing persisted. `PayviaPaymentCollector::initiate()` now asks the provider
whether a payable's open session is genuinely still live before ever handing it back, and only
supersedes it for a NEW session when the provider proves it dead. One new migration (012) adds
a duplicate-safe composite unique index over `(tenant_uuid, gateway, reference)`. Existing
single-attempt-per-payable flows on a freshly initiated payable are unaffected; a *repeat*
`initiate()` on an already-open payable is the one call shape that behaves differently — see
Changed.

### Added

- **Reference-addressable session attempts** (migration 012): `payment_intents` gains a
  service-enforced attempt lifecycle (`initializing -> open -> {superseded|closed|failed}`) atop
  its existing `uuid`, plus a portable `UNIQUE(tenant_uuid, gateway, reference)` index —
  proven on both SQLite and PostgreSQL to admit unlimited `reference IS NULL` rows while
  rejecting a genuine non-NULL duplicate. `PaymentIntentRepository` gains
  `claimAttempt()`/`markOpen()`/`supersede()`/`fail()`/`findActive()`/`findByUuid()` as durable
  idempotency ports around each attempt; a race on the active-port key recovers the existing
  attempt instead of minting a second one. A collision against a different, already-retired
  attempt's reference (e.g. a provider replaying a fixed idempotency key onto a closed payable)
  now throws a typed `DuplicateReferenceException` instead of the collector fabricating a fake
  `ok` initiation with nothing persisted.
- **Ensure-live hosted sessions in `PayviaPaymentCollector::initiate()`**: a call against a
  payable with no open intent claims an attempt and creates a session as before. A call against
  an already-open intent now asks the provider to prove liveness first: **confirmed live**
  (or completed — settlement is the webhook's business) returns the exact same checkout URL with
  no new session minted; **confirmed dead** supersedes the retired attempt (its reference stays
  webhook-addressable — see below) and claims a fresh one; **unknown** (an unreadable provider
  answer, a probe exception, or an ambiguous abandon result) fails closed with a typed
  `ProviderSessionStateUnknownException` rather than guessing. A gateway that isn't
  state-capable, a payable whose intent belongs to a different gateway, or an intent with no
  reference yet is "nothing provable" and returns the open intent unchanged.
- **Stripe per-attempt idempotency keys + provider-proven renewal**: `Idempotency-Key` is now
  `payvia-init-{attemptUuid}` (previously a fixed per-payable key), so a transport-timeout retry
  replays the identical attempt into the identical session while a genuine new attempt always
  gets a fresh key. `hostedSessionState()` reads Checkout Session `status`/`payment_status`
  (`complete`+unsettled is still **live** — async payment methods settle later);
  `abandonHostedSession()` expires the session and re-fetches, trusting only the re-fetch's
  `expired`/`canceled` as proof of death.
- **Paystack verify-first recovery** (`ResumableHostedSessionGateway`): `/transaction/initialize`
  is not idempotent (a repeated reference is a permanent "Duplicate Transaction Reference"
  error), so a RESUMED attempt (the collector distinguishes a fresh claim from a resumed one by
  comparing the attempt uuid it minted against the one `claimAttempt()` actually returned) now
  calls `/transaction/verify` — which is idempotent — before ever calling initialize again:
  reference absent ⇒ safe to re-initialize under the same attempt; a paid transaction ⇒ adopt it
  (record the reference, never re-create); an unpaid or reversed transaction ⇒ fail the retired
  attempt and claim a new one. Paystack's non-terminal transaction states
  (`abandoned`/`ongoing`/`pending`/`processing`/`queued`), including `failed`, are now treated as
  **live** for the purpose of reusing the existing checkout URL — a repeat visit to the same page
  or another payment option should not be treated as dead; only `reversed` is dead. Paystack
  deliberately does **not** implement session renewal (`HostedSessionRenewalCapableGateway`) — it
  has no provider-side proof of death to renew against — so a genuinely dead Paystack session
  surfaces `SessionRenewalUnavailableException` instead of being silently replaced.
- **Checkout URL trust boundary** (`Support\HostedCheckoutUrl` + `gateways.{stripe,paystack}.checkout_hosts`):
  every hosted checkout URL a driver returns (Stripe Checkout, Stripe subscription Checkout,
  Paystack `authorization_url` — previously unchecked) is now validated against a per-gateway
  host allowlist before it can reach an intent payload: absolute HTTPS, case-normalized exact
  host match, no userinfo/credentials, no explicit port, no trailing dot, no sub/superdomain
  lookalikes, no whitespace/control characters. Rejection throws inside the gateway; nothing
  untrusted is ever persisted. A missing or malformed `checkout_hosts` config falls back to the
  driver's own shipped default host, so a pre-2.6.0 `config/payvia.php` keeps working unchanged.
- **Per-intent liveness cooldown**: `payvia.session_liveness_cooldown_seconds` (default `30`, `0`
  disables) suppresses a repeat provider liveness probe for that long after the last
  **confirmed-live** answer, stamped into the intent's own payload
  (`PaymentIntentRepository::recordLivenessConfirmation()`). Only a confirmed-live probe stamps
  it; dead/unknown answers and a brand-new attempt are never suppressed; a future-dated stamp
  (clock skew) is ignored.
- **Reference-addressable webhook confirmation**: new `PaymentIntentRepository::findByReference()`
  (exact lookup on the new composite unique index, any status) and `::settle()` (CAS to `closed`
  from `open`, `superseded`, or `failed`; an already-`closed` row is excluded, so a redelivered
  webhook is a harmless no-op). `ConfirmationDispatcher::dispatch()` now resolves the row to close
  by the confirmation's own `(gateway, reference)` instead of "whichever attempt is open for this
  payable" — so a webhook confirming a *superseded* attempt's reference settles that exact
  retired row and never touches a newer open attempt for the same payable.

### Changed

- **`initiate()` is fallible on every call, not just the first.** In 2.5.0, a repeat `initiate()`
  against an already-open intent unconditionally returned `ok` with the cached checkout URL and
  never touched the provider. In 2.6.0 it performs provider I/O to prove liveness first and can
  return a typed failure (`ProviderSessionStateUnknownException`,
  `SessionRenewalUnavailableException`, or a propagated `DuplicateReferenceException`). Callers
  must treat every `initiate()` call as fallible, not just the first one for a payable.
- **`ConfirmationDispatcher::dispatch()` gains a required 4th parameter, `string $gateway`.** Its
  only in-repo caller (`PaymentService::confirmAndRecord()`) already had the gateway key in
  scope; a third-party caller must be updated to pass it.

### Migrations

- **`012_AddPaymentIntentAttemptLifecycle`** — adds the attempt lifecycle columns/constants and
  the composite `UNIQUE(tenant_uuid, gateway, reference)` index. Runs a duplicate-reference
  dedup preflight first: for every existing group of rows sharing a non-NULL
  `(tenant_uuid, gateway, reference)`, keeps the newest row's reference and nulls the reference
  on the older rows in that group (no row is deleted; nothing else is touched) — so the
  constraint can be added safely even on a database that already has replay-produced duplicates.
  Idempotent both ways; runs inside one transaction (dedup, constraint, and — on SQLite, which
  cannot add a composite UNIQUE via `ALTER TABLE` — the full table rebuild) so a failure anywhere
  in the sequence rolls back to the untouched original table. Run `php glueful migrate:run` after
  upgrading.

### Notes

- **Stripe custom Checkout domains:** a merchant serving Stripe Checkout on their own domain must
  extend `gateways.stripe.checkout_hosts` in file config. This key is deliberately **not**
  overridable via `PayviaSettings` — the checkout-URL trust boundary is platform-owned, not
  runtime-editable.
- **Paystack operational requirement:** the Paystack integration setting
  `payment_session_timeout` must remain `0` (infinite). A non-zero value silently dead-ends a
  resumed checkout page while `/transaction/verify` still reports it `abandoned` — indistinguishable
  from live from payvia's side.
- **Paystack has no session renewal.** There is no provider-side proof of death to renew
  against, so a genuinely dead Paystack session surfaces `SessionRenewalUnavailableException`
  rather than being silently replaced with a new one.

## [2.5.0] - 2026-08-04 — Subscription Checkout Origination

Payvia gains a hosted, provider-agnostic subscription checkout capability with its own
origination ledger, ownership correlation, durable projection acknowledgement, and operator
reconciliation -- the workspace self-serve checkout program's Phase A. Everything is additive:
existing payment/webhook/subscription-projection flows are byte-identical. Two new migrations
(010, 011) create the ledger/guard tables and add reconciliation audit columns; nothing existing
is altered.

### Added

- **Subscription checkout capability** -- three new, purely additive gateway interfaces, none of
  which touch the existing `SubscriptionCapableGateway`:
  - `SubscriptionInitiationCapableGateway::initializeSubscription()` starts a provider-hosted
    checkout for a NEW subscription (as opposed to managing an already-existing one).
  - `SubscriptionCheckoutLifecycleCapableGateway` reconciles an in-flight checkout on demand
    (`subscriptionCheckoutStatus()`) and attempts to definitively kill a presumed-abandoned one
    (`abandonSubscriptionCheckout()`), without waiting for a webhook.
  - `SubscriptionCancellationModeProvider::cancellationModes()` declares which self-serve
    cancellation modes (`stop_renewal` | `immediate`) a gateway's existing `cancelSubscription()`
    actually honors.

  `GatewayManager::supports()` gains two capability keys: `subscription_checkout` and
  `cancellation_modes`.

- **Stripe implements all three.** `StripeGateway::initializeSubscription()` creates a
  `mode=subscription` Checkout Session against an existing Price id, stamping `origination_uuid`
  into both the session's own metadata (diagnostics) and `subscription_data.metadata` (the field
  Stripe actually propagates onto the subscription it creates, and the one later correlation
  reads). `subscriptionCheckoutStatus()`/`abandonSubscriptionCheckout()` map the session's
  `status` gated by `payment_status`: a session can reach `status=complete` while an async
  payment method is still settling, so `complete` only maps to `completed` when `payment_status`
  confirms it (`paid` or `no_payment_required`) -- never on `status` alone. Abandonment expires
  the session, then re-fetches and classifies off that re-fetch alone (the expire call's own
  response is never trusted for a session already terminal). `cancellationModes()` reports both
  `stop_renewal` and `immediate` over the existing `cancelSubscription()`.

- **Paystack: subscription checkout is explicitly unavailable.** A 2026-08-04 sandbox proof
  (`tests/Fixtures/paystack-checkout/`) established that Paystack exposes neither correlation
  mode a hosted subscription checkout needs: `subscription.create` carries no transaction
  metadata or reference, and `charge.success` carries no subscription identifier -- there is no
  non-ambiguous way to join a checkout attempt to the subscription it produces. `PaystackGateway`
  deliberately does not implement `SubscriptionInitiationCapableGateway`;
  `GatewayManager::supports('paystack', 'subscription_checkout')` is `false`; and
  `SubscriptionCheckoutService::prepare()` targeting Paystack fails closed with
  `CheckoutUnavailableException` before any ledger or guard row is written -- there is no
  one-time-payment fallback. The four committed negative-proof fixtures and a regression suite
  (`PaystackSubscriptionCheckoutUnavailableTest`) pin this shape permanently, including a
  byte-for-byte reproduction of the fixtures through the same projector used to capture them.
  Paystack's existing webhook projection and operator-created subscription support are
  unaffected; Paystack additionally now declares cancellation mode `stop_renewal`.
  **Revisit trigger:** only when Paystack starts propagating transaction metadata onto
  `subscription.create`, or starts including a subscription identifier in `charge.success`.

- **Origination ledger + subject guard** (migration 010): `subscription_checkout_originations`
  is the permanent correlation identity for a hosted subscription checkout attempt -- its opaque
  `uuid` is stamped into provider metadata so a webhook arriving after any terminal state still
  correlates. `subscription_checkout_subject_guards` is a separate, narrower authority ("may this
  subject originate another checkout right now?") that is never inferred from origination rows or
  any local TTL. `SubscriptionCheckoutService::prepare()` owns exactly one transaction: it
  validates before any write, claims (or idempotently replays) the origination row by a
  per-attempt idempotency key with a request fingerprint (a mismatched fingerprint against an
  existing key is a genuine conflict), claims the subject guard only for a genuinely new claim,
  and advances `preparing -> initializing`. `initializeClaim()` is a separate, later call: it
  acquires a narrow 120-second initialization lease (stale-reclaimable), calls the provider
  driver entirely outside of any database transaction, and persists the outcome via a single
  atomic compare-and-swap -- a concurrent loser never touches the provider at all. Checkout URLs
  are stored verbatim for crash recovery, and `customer_email` is force-cleared the moment an
  origination reaches a definitive (terminal) outcome.

- **Ownership correlation.** `GatewaySubscriptionService::applyProviderEvent()` resolves the
  `origination_uuid` token Stripe's normalizer promotes into normalized metadata: found with no
  existing `gateway_subscriptions` projection, the ledger row's own `tenant_uuid` is adopted as
  the projection's owner (never a provider-supplied metadata hint alone); the ledger transitions
  to `provider_observed` and its `consumer_metadata` correlation fields are merged into the
  event's normalized metadata via a new immutable-replacement seam,
  `ProviderEvent::withNormalized()`, persisted by the new additive
  `ProviderEventPayloadUpdaterInterface` (a repository MAY implement it; when it doesn't, the
  enrichment is discarded and the row fails closed exactly as if the applier itself had thrown).
  A late webhook for a historical, already-terminal attempt whose subject guard is now held by a
  newer origination is recorded as `late_settlement_conflict` instead of silently re-observing
  money against a superseded attempt -- both the guard block and the ledger transition commit
  atomically, and the conflict is permanently terminal (operator-visible, never auto-resolved,
  never silently overwriting a newer owner). Stripe's `checkout.session.expired` webhook is
  recognized as a ledger lifecycle event (never a subscription projection) and closes the
  matching guard pre-dispatch by transitioning `pending -> expired`.

- **Durable projection acknowledgement + post-dispatch finalizer.** `CheckoutOriginationRepository`
  implements the new `SubscriptionProjectionAcknowledger` contract: the sole way a required
  projection consumer (e.g. `glueful/subscriptions`) records its durable `accepted`/`rejected`
  verdict for a given delivery, via a compare-and-swap over the origination's current state.
  A duplicate delivery re-reads the existing receipt and treats a repeat of the identical
  outcome as an idempotent no-op; a conflicting second outcome for the same logical event key
  throws rather than silently overwriting the first verdict. `WebhookService`'s new post-dispatch
  finalizer runs immediately after `subscription.created` dispatches without throwing: `accepted`
  advances the origination `provider_observed -> dispatched` and releases the subject guard
  atomically; `rejected` moves it to operator-visible `projection_rejected` and leaves the guard
  held; a missing acknowledgement where one was required is retryable (throws, exactly like a
  dispatcher failure).

- **Operator reconciliation** (`CheckoutReconciliationService`, migration 011 audit columns).
  Exactly two explicit resolutions exist for a stuck or rejected origination -- there is no third
  "ignore" option: `provider_confirmed_dead` (allowed only when this ledger row never observed
  provider money/subscription state; the only reconcilable status that qualifies is a stuck
  `pending` row, advanced to `abandoned`) and `provider_canceled_or_refunded` (allowed only when
  money WAS observed; `projection_rejected` and `late_settlement_conflict` stay at their own
  status -- a legal, vacuous self-transition that writes only the audit trail). Every other
  status, including the already-successful `dispatched`, is refused outright. The audit note
  lands in new, dedicated `reconciliation_resolution`/`reconciliation_note`/`reconciled_at`
  columns rather than the projection consumer's own committed `projection_reason` receipt, which
  this service never rewrites. Resolution runs in the same one-transaction discipline as
  `prepare()`: the origination write, the subject guard's compare-and-swap reopen, and the host's
  local-only continuation all commit or roll back together. Both
  `subscription_checkout_originations` and `subscription_checkout_subject_guards` are now part of
  the tenant purge/adopt/diagnostics table inventory.

- **Console:** `payvia:checkout:sandbox-proof` -- a maintainer-run (never CI) harness that drives
  a real Paystack sandbox checkout end to end and projects the resulting webhook/API payloads
  through a closed, PII-free allowlist into committed fixtures. This is what produced the
  Paystack negative-proof fixtures above.

### Migrations

- **`010_CreateCheckoutOriginations`** -- creates `subscription_checkout_originations` and
  `subscription_checkout_subject_guards`.
- **`011_AddCheckoutOriginationReconciliationColumns`** -- adds nullable
  `reconciliation_resolution`/`reconciliation_note`/`reconciled_at` to
  `subscription_checkout_originations`.

  Run `php glueful migrate:run` after upgrading.

## [2.4.0] - 2026-08-03 — Opt-in Strict Payment-Event Lane

Payvia gains an opt-in strict-delivery lane for payment provider events, allowing consumers
(e.g. `glueful/subscriptions`) to receive guaranteed at-least-once delivery with mandatory
idempotency. The lane composes a discrete step in the webhook pipeline, ordered between
ordinary (fault-isolated) listener dispatch and the existing chargeback lane. Ordinary
listener semantics are completely unchanged. No new migrations beyond the owner-fenced lease
capability; chargeback delivery inherits the same release-on-failure behavior.

### Added

- **Opt-in strict payment-event lane** (`StrictPaymentEventListener` contract) — a discrete,
  FQCN-ordered dispatch step for listeners that require guaranteed delivery. Each tagged
  listener's `handle()` is invoked directly; an exception leaves logical dispatch unmarked
  (exactly like the chargeback lane) and produces a retryable delivery. Collection is
  container-tag-based (`payvia.strict_payment_event_listeners`); duplicate concrete classes
  and non-contract tagged values fail service construction loudly.
- **Owner-fenced logical dispatch leases** (`LogicalDispatchLeaseRepositoryInterface`) — an
  additive capability for atomic, owner-fenced claim tokens on provider events. Acquisition
  returns a fresh opaque token; completion and release require that exact token, preventing
  a stale worker from interfering with a successor's active claim. Enables immediate retry
  after strict-listener failure without waiting for the default 300-second stale-lease window.
  `WebhookService::dispatch()` now releases its lease on any composed-dispatcher exception
  (both strict and chargeback lanes) before rethrowing, so a concurrent delivery/retry can
  reclaim immediately.

### Migrations

- **`009_AddProviderEventDispatchClaimToken`** — adds nullable `dispatch_claim_token
  VARCHAR(64)` column to `provider_events`. Idempotent; safe to re-run if partially applied.
  Run `php glueful migrate:run` after upgrading.

### Changed

- **Chargeback-lane delivery now releases owner-fenced leases on failure.** The existing
  strict `ProviderChargebackEvent` dispatch (invoked last in the pipeline) now benefits from
  the new immediate-retry behavior: on any listener exception, the lease is released before
  rethrowing. Chargebacks remain the final composed step; nothing changes to their contract
  or error handling — only the timing of failure-path re-execution improves.

### Notes

- **Ordinary `PaymentProviderEvent` listener semantics are byte-identical.** The fault-isolated
  dispatch step (fault-isolated exceptions are logged and swallowed) remains the first
  pipeline step, unchanged since 2.0.0.
- **`ProviderEventRepositoryInterface` is unchanged.** The lease capability is a separate
  additive interface; third-party `ProviderEventRepositoryInterface` implementations remain
  source-compatible. When a custom repository does not implement `LogicalDispatchLeaseRepositoryInterface`,
  `WebhookService` retains the original stale-window fallback for `dispatchOrFail()` failure recovery.
- The strict lane is used by `glueful/subscriptions` (2.0.0 amendment) for subscription event
  projection under the strict-delivery contract documented in its CHANGELOG and BYOP (Build-Your-Own-Provider)
  guide, §6.

## [2.3.0] - 2026-08-01 — Hosted Initiation Metadata & Stripe Checkout

The hosted-initiation seam becomes payable-type-agnostic and Stripe gains a hosted checkout flow.
Additive throughout: no new migrations, env vars, or config keys; existing installs whose payables
carry no metadata behave exactly as 2.2.0 (the collector's manual/keyless fallbacks are
byte-identical, pinned by tests).

### Added
- **Payable metadata convention for hosted initiation** — `PayviaPaymentCollector` now lifts three
  well-known `PayableReference::metadata` keys (`email`, `callback_url`, `cancel_url`) into the
  gateway initiation options. The seam is payable-type-agnostic: whoever builds a payable (an
  order flow, a subscription flow, an invoice flow) supplies its own values; the collector never
  inspects `payable_type` and initiation exceptions still propagate to the caller. Paystack's
  required `email` and per-install `callback_url` can now be satisfied per payment instead of via
  the dashboard-global callback.
- **Stripe hosted checkout** — `StripeGateway` implements `InitiationCapableGateway` via Stripe
  Checkout Sessions: `mode=payment`, one line item from the payable's amount/currency/description,
  `customer_email` when supplied, `success_url`/`cancel_url` from the metadata convention
  (callback REQUIRED; cancel falls back to callback), and a deterministic per-payable
  `Idempotency-Key` so concurrent initiations cannot mint two sessions. The response is validated
  (non-empty `cs_…` id, absolute HTTPS checkout URL) before any intent is persisted, and the
  returned session id is exactly the reference `verify()`'s existing checkout-session branch
  resolves.


## [2.2.0] - 2026-07-25 — Host Settings Seam & Graceful Degradation

Payvia becomes runtime-configurable by its host: a consuming application can bind the new
settings seam to make the default gateway, per-gateway enablement, and the API keys
themselves editable from an admin screen (Thallo ships exactly that as its Payments tab),
and an installed-but-unconfigured payvia now degrades checkout to manual collection instead
of failing it. No new migrations, env vars, or config keys; existing installs without a
seam binding behave exactly as before.


### Added
- **Host settings seam** (`PayviaSettingsOverride` + `PayviaSettings`): a consuming application
  can bind `Glueful\Extensions\Payvia\Support\PayviaSettingsOverride` to make a whitelisted
  subset of `payvia.*` keys runtime-editable (an admin Payments screen backed by a settings
  table) — `payvia.default_gateway` and, per configured gateway, `enabled`, `secret_key`, and
  `webhook_secret`. All internal reads of those keys (GatewayManager, both gateway drivers,
  PaymentService, PayviaPaymentCollector) now go through the `PayviaSettings` reader:
  override-first, defensive casting (malformed values fall back to config), null-never-throw,
  and pure config/env passthrough when no override is bound — existing installs are unchanged.
  Overrides can reconfigure a configured gateway but never invent a new one; ops knobs
  (base URLs, timeouts, middleware profiles) deliberately stay config/env-only. Secrets cross
  the seam as plaintext — if the host stores them encrypted, decryption is the host binding's
  job, and payvia never persists or logs seam values.
- **Graceful degradation for unconfigured gateways**: `PayviaPaymentCollector` now falls back to
  MANUAL collection (`status: manual`, operator marks paid — the same posture as commerce's own
  `ManualPaymentCollector`) when the default gateway is disabled or declares a `secret_key` slot
  with no value in it, instead of initiating a charge with empty credentials. Installing payvia
  before entering keys no longer breaks a store's checkout; the moment a key lands (env or a
  host settings screen), hosted initiation takes over. Drivers that genuinely need no secret
  simply omit the `secret_key` slot from their config.

### Fixed
- **Route registration failed on every host that loads routes through the provider**
  (`ServiceProvider::loadRoutesFrom()`): `routes.php` read `config($context, ...)` trusting the
  `RouteManifest::load()` injection contract, but the provider loader injects only `$router` —
  "Undefined variable $context". In production the failure was swallowed (all Payvia endpoints
  silently missing); in development the rethrow escaped the framework's single extension-boot
  guard and aborted every provider booted after Payvia, taking unrelated extensions down with it.
  `routes.php` now derives `$context = $router->getContext()` (the same pattern as
  glueful/commerce) and works under both loaders.

## [2.1.0] - 2026-07-20 — Provider Payouts & Dispute Ingestion

Payvia gains an outbound payout surface and inbound dispute ingestion, both through the neutral
`glueful/extension-contracts` payment ports — so a consuming application (e.g. the commerce
marketplace) settles seller payouts and reacts to chargebacks without any concrete-provider
reference. Requires framework 1.71.0 (strict `dispatchOrFail` event delivery) and
extension-contracts 1.5.0 (the `PayoutCollector` port and `ProviderChargebackEvent`). One new
migration; no new env vars or config keys.

### Added
- **Provider payouts** — `PayviaPayoutCollector` binds the contracts `PayoutCollector` port, backed by
  a `TransferCapableGateway` capability arm (`transfer()`, `recoverTransfer()`, `transferStatus()`,
  `inspectAccount()`) implemented by `StripeGateway` and `PaystackGateway`. Payouts are recorded in a
  new `payvia_transfers` table via `PayoutTransferRepository` with a pre-I/O pending row keyed by the
  idempotency key, so a transfer is issued exactly once even across retries.
- **Lost-response recovery** — `recoverTransfer()` reconciles a transfer whose provider response was
  lost mid-flight without double-paying: Stripe replays under the idempotency key (the provider
  de-duplicates), Paystack verifies by reference. A destination that can't be resolved fails closed.
- **Dispute ingestion** — `ProviderChargebackDispatcher` turns a provider dispute/chargeback webhook
  into a neutral contracts `ProviderChargebackEvent` (chargeback and reversal kinds), correlating it
  to the owning payment by gateway transaction id. Correlation is fail-closed and row-authoritative:
  an ambiguous or unresolved owner raises `UnresolvedPaymentOwnershipException` rather than guessing,
  and a malformed webhook raises `MalformedChargebackEventException`.
- **Guaranteed delivery** — chargeback events dispatch strictly via the framework 1.71.0
  `EventService::dispatchOrFail()` seam: a listener failure rethrows so the webhook can be retried,
  and stored-event redelivery is isolated per row so one poison event never starves the relay.
  Ordinary (non-chargeback) Payvia events keep their existing fault-isolated dispatch.

### Migrations
- **`008_CreatePayviaTransfersTable`** — the `payvia_transfers` ledger (idempotency-keyed, with a
  null-exempt unique on the idempotency key across MySQL, PostgreSQL, and SQLite). Run
  `php glueful migrate:run` after upgrading; the table is only written when a `TransferCapableGateway`
  payout is actually issued.

## [2.0.0] - 2026-07-16 — Money & Tenancy Hardening

### Breaking Changes

- **All stored and transported amounts are now integer minor units, not decimal majors.**
  `payments.amount`, `billing_plans.amount`, and `invoices.amount` are `bigInteger` columns
  (matching `payment_intents.amount`, which was already integer). Every gateway, service,
  controller, and normalized event now carries a single integer end-to-end — there is no
  runtime Money/exponent helper, because Payvia never formats money for display. Amounts in
  request bodies and JSON responses change shape accordingly:

  ```jsonc
  // v1 (decimal major units)
  { "amount": 10.50 }

  // v2 (integer minor units)
  { "amount": 1050 }
  ```

  This applies to `POST /payvia/plans`, `/payvia/plans/update`, `POST /payvia/invoices`, and
  every response/list endpoint that carries an `amount`. `PaymentService::minorUnits()` is
  deleted; gateway normalization methods (`PaystackGateway`, `StripeGateway`) return
  `int`/`?int` and no longer float-divide a wire amount by `100.0` — Stripe/Paystack already
  send integer minor units on the wire, so the pass-through is now lossless. `currency`
  columns and formats are unchanged (`GHS` default, ISO-4217-shaped string).

- **Schema is finalized pre-deployment — there is no data-migration path from a pre-2.0
  install.** Because no `glueful/payvia` database existed in production when this hardening
  was designed, the money and tenancy schema changes were folded directly into the original
  `001`–`005`/`007` create-table migrations rather than shipped as upgrade migrations with a
  conversion step. There is no `amount` decimal→integer conversion routine and no
  auto-migration for existing rows. **If you have a pre-2.0 development database, you must
  drop and recreate the Payvia tables (or hand-write and run your own conversion) before
  upgrading** — running `php glueful migrate:run` against an already-migrated pre-2.0 schema
  will not retroactively reshape existing columns/rows. `007_CreatePaymentIntentsTable`
  (previously `008`) also absorbed the `billing_plans` `(gateway, name)` unique-scoping
  rebuild that shipped as a standalone `007` migration in `1.1.0` — that migration file is
  gone; the composite unique is now part of `002`'s create-table shape directly.

- **Tenant sentinel scoping added to the five domain tables.** `payments`, `billing_plans`,
  `invoices`, `gateway_subscriptions`, and `payment_intents` each gained a `tenant_uuid
  char(12)` column, defaulting to `''` so single-store installs remain byte-identical to
  `1.x`. Business keys that are meaningful only within a tenant became **composite** in the
  create-table shape (the old global forms no longer exist):
  - `invoices`: `(tenant_uuid, number)` — invoice numbers may repeat across tenants.
  - `billing_plans`: `(tenant_uuid, gateway, name)` — plan names may repeat across tenants.
  - `payment_intents`: `(tenant_uuid, idempotency_key)` — two tenants may open the same
    logical `{payable_type}:{payable_id}` intent without colliding.

  **Global correlation keys are unchanged** — `uuid` on every table, `payments.reference`,
  and `gateway_subscriptions (gateway, gateway_subscription_id)` stay globally unique,
  because provider callbacks and webhooks arrive with no tenant request context and must
  resolve unambiguously. A new local `PayviaTenantResolver` seam consumes the shared
  `CurrentTenantResolver` when a host has one (wrapped fail-closed) and falls back to a
  sentinel resolver otherwise; Payvia never binds or replaces the shared contract itself.

- **Route middleware configuration split into three ordered profiles.**
  `payvia.security.manage_middleware` used to carry both authentication and authorization for
  the billing-plan/invoice write routes. It is now three separate, independently
  configurable keys — `auth_middleware`, `tenant_context_middleware`, and
  `manage_middleware` — composed in that order (authenticated read/confirm routes use
  profile 1 → 2; management routes use 1 → 2 → 3). The webhook route uses none of them and
  stays signature-authenticated/tenantless.

  ```php
  // v1 config/payvia.php
  return [
      'security' => [
          'manage_middleware' => ['auth', 'admin'],
      ],
  ];

  // v2 config/payvia.php
  return [
      'security' => [
          'auth_middleware' => ['auth'],
          'tenant_context_middleware' => [],
          'manage_middleware' => ['admin'],
      ],
  ];
  ```

  **If your host overrode `payvia.security.manage_middleware`, you must split it**: move the
  authentication entries (e.g. `auth`) into `auth_middleware`, leave only authorization checks
  (e.g. `admin`, a custom permission middleware) in `manage_middleware`, and set
  `tenant_context_middleware` to whatever establishes request-scoped tenant context in your
  app (empty array for single-store hosts). The default configuration is byte-identical in
  behavior to `1.x`'s default (`['auth', 'admin']` on write routes); only a *customized* v1
  override needs to be reshaped.

### Added

- **`amount_unit: 'minor'` marker on runtime-normalized events.** Every normalized provider
  event and payment confirmation that carries a numeric `amount` now also carries
  `amount_unit: 'minor'` — a forward-compatible marker for any future consumer or
  re-normalizer, making the unit explicit rather than implied. Raw provider payloads
  (`raw_payload`) are unaffected; they remain opaque historical evidence, never rewritten.
- **`php glueful payvia:diagnose`** — reports Payvia's contract bindings, tenancy resolver
  mode, registered tenant tables, per-table sentinel row counts, and any unresolved
  subscription-ownership failures.
- **`php glueful payvia:tenancy:adopt --tenant=<uuid>`** — adopts existing sentinel
  (`tenant_uuid = ''`) rows across all five domain tables into a named tenant, for hosts
  turning on tenancy after running Payvia single-store.

### Changed

- **Subscription webhook ownership now resolves in a strict, fail-closed order and never
  fabricates a sentinel projection.** `GatewaySubscriptionService` resolves ownership for an
  incoming `(gateway, gateway_subscription_id)` event as: (1) an existing projection's
  persisted `tenant_uuid` is authoritative; (2) for a first-seen subscription, the tenant is
  derived from the globally unique `billing_plan_uuid` in the normalized event metadata (an
  explicit metadata `tenant_uuid`, if present, must agree with the plan's owner — metadata
  alone is never an ownership authority, even under a validly signed webhook); (3) if neither
  resolves, the event is left failed/retryable and diagnosable rather than written as an
  ownerless or sentinel-tenant row. Reconciliation uses the same locator and never moves a
  projection between tenants.
- **Interactive repositories always resolve and scope by the current tenant; one named
  internal `ProviderCorrelationRepository` is the sole exception**, used only for the
  provider-subscription and billing-plan UUID correlation lookups above. When tenancy is
  active it runs through the tenancy contracts' `TenantContextRunner::runAsSystem()` seam
  (preserving query interception and observability while suspending row scoping); if a shared
  tenant resolver is present but no runner is bound, construction fails closed rather than
  running an unscoped query. A permanent test forbids any other runtime code from bypassing
  the query builder with raw PDO/SQL against the five tenant-scoped tables.
- Migrated OpenAPI documentation to the framework 1.57.0 reflect generator. Route
  documentation (summaries, query parameters, request-body fields and response codes)
  is now expressed as typed `#[ApiOperation]`, `#[QueryParam]` and `#[ApiResponse]`
  attributes on the controller methods; the now-inert route-file docblocks were removed.
  Docs-only — no runtime behaviour changes.
- Raised the minimum framework requirement to `^1.57.0`.

### Fixed

- **Amount columns are normalized to `int` on every read surface.** SQLite (and PDO
  generally) can hand `bigInteger` columns back as numeric strings; the new
  `NormalizesAmountColumn` repository concern casts `amount` on every read in the
  payment/intent/plan/invoice repositories, so JSON responses and internal consumers always
  see integer minor units regardless of driver.
- **Cross-tenant UUID global uniqueness is now proven, not assumed.** Schema shape tests
  pin the plain `UNIQUE(uuid)` constraint (deliberately NOT tenant-composited) on all five
  domain tables — the webhook ownership resolution above depends on `billing_plan_uuid`
  being globally unique, and these tests make that dependency permanent.
- **`GatewaySubscriptionService::reconcile()` no longer creates sentinel projections for
  unknown subscription ids.** Reconciling an id with no existing projection and no resolvable
  billing-plan owner previously wrote an ownerless sentinel-tenant row; it now fails closed,
  matching the webhook path's strict ownership order.

## [1.0.2] - 2026-06-13

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

- **Removed the inert `flutterwave` gateway stanza from the default config.** `config/payvia.php` shipped a `gateways.flutterwave` entry, but no Flutterwave driver class exists yet, so enabling it would make `GatewayManager` throw at resolution time. The stanza (and its `PAYVIA_FLUTTERWAVE_*` env example / config comment in the README) has been removed; the driver remains tracked under the **Planned** section above and the stanza will return when it ships. No code referenced `payvia.gateways.flutterwave`.
- **Documented `status` on the create-invoice request body.** The `POST /payvia/invoices` route docblock now lists `status` as an accepted field with its enum (`draft,pending,paid,canceled,failed`) and the `pending` default, matching `InvoiceController::create`'s validation. `draft` remains an accepted input value for callers that explicitly create drafts.
- **`billing_plans.name` uniqueness is now scoped per gateway.** The original `002` migration declared a global `UNIQUE (name)`, so the same plan name could never coexist across two payment gateways. New migration `007_ScopeBillingPlanNameUniquePerGateway` replaces it with a composite `UNIQUE (gateway, name)`. Because the framework emits the original constraint INLINE in `CREATE TABLE` — undroppable on SQLite (anonymous `sqlite_autoindex_*`) and a named CONSTRAINT on PostgreSQL that the schema builder's `dropUnique()` cannot drop portably — the change is applied via a full table rebuild (create replacement, copy every row, drop original, rename into place) so it works identically on SQLite, MySQL, and PostgreSQL. The rebuilt table is index-equivalent to `002` apart from this one change (the `uuid` unique is preserved). NULL semantics: `gateway` is nullable and NULLs do not collide in a unique index on any of the three drivers, so multiple plans with no gateway (`gateway IS NULL`) may still share a name; two plans with the same non-NULL gateway may not. The migration is idempotent (a guarded no-op if the composite unique is already present) and ships a `down()` that restores the global unique — `down()` will fail if the data has come to contain the same name under two different gateways, which is expected for a uniqueness-tightening rollback.

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
