# Strict Payment-Event Lane Design

**Status:** approved design, pre-implementation.
**Target release:** payvia 2.4.0 (minor; additive).
**Companion change:** glueful/subscriptions folds its consumer into the
unpublished 2.0.0 (spec amendment, no separate release).

## 0. Problem

`PayviaServiceProvider::makeWebhookService()` delivers the local
`PaymentProviderEvent` through the framework's **fault-isolated**
`EventService::dispatch()`: a listener exception is logged and swallowed,
delivery continues, and `WebhookService::dispatch()` proceeds to
`markLogicalDispatched()`.

subscriptions 2.0's projector deliberately throws
`UnmappedProviderSubscriptionException` when a `subscription.created` webhook
races the local subscription row (checkout race): the receipt claim rolls
back and the delivery must be retried. On the current bus that exception is
swallowed, the logical event is marked dispatched, the provider receives
2xx, and `relayPending()` will never redeliver — the event is permanently
lost on both sides. The retryable-rejection contract subscriptions documents
(BYOP §6) is therefore unreachable through payvia, its own first-party
channel.

Payvia already has the answer in-house, twice:

- the **chargeback lane**: `ProviderChargebackEvent` goes through strict
  `dispatchOrFail()`; the docblock at `makeProviderChargebackDispatcher()`
  documents the at-least-once + mandatory-idempotency contract;
- the **container-tag pattern**: `PaymentConfirmationHandler::CONTAINER_TAG`
  collects opt-in handlers that the provider composes into a dispatcher.

This design combines the two into a generic, opt-in strict lane. Ordinary
`PaymentProviderEvent` listeners keep today's fault-isolated semantics
untouched.

## 1. The contract

New `src/Contracts/StrictPaymentEventListener.php`:

```php
namespace Glueful\Extensions\Payvia\Contracts;

interface StrictPaymentEventListener
{
    public const CONTAINER_TAG = 'payvia.strict_payment_event_listeners';

    public function supports(PaymentProviderEventInterface $event): bool;

    public function handle(PaymentProviderEventInterface $event): void;
}
```

Pins (from the design ruling):

- **Typed against `PaymentProviderEventInterface`** — the provider-normalized
  event, invoked directly. The bus wrapper (`Events\PaymentProviderEvent`)
  is transport machinery this lane does not use; coupling the contract to it
  was considered and rejected.
- **Payvia-owned, not extension-contracts.** The neutral contracts package's
  `PaymentConfirmationHandler` precedent uses fully neutralized value
  objects; this lane is intrinsically typed against payvia's event surface.
  Neutralizing the event shape is a larger, unneeded project — this is a
  generic *payvia* capability, with subscriptions as its first consumer.
- **Listener obligations** (documented on the interface): implementations
  MUST be idempotent — delivery is at-least-once, and a retry after a
  sibling listener's failure re-invokes listeners that already succeeded.
  `handle()` throwing prevents the webhook's dispatch-marking and produces a
  retryable delivery (non-2xx response in inline mode; a retried job in
  queue mode). Payvia releases that worker's owner-fenced logical dispatch
  lease before rethrowing, so the next attempt can run immediately rather
  than waiting for stale-lease recovery.

## 2. The lane

`makeWebhookService()`'s composed `$dispatcher` gains one step between the
existing two:

1. Fault-isolated `EventService::dispatch($wrapper)` — ordinary listeners,
   semantics byte-identical to today.
2. **Strict lane (new):** for each tagged `StrictPaymentEventListener`, in
   deterministic order, `if ($listener->supports($event)) { $listener->handle($event); }`
   — invoked directly, exceptions **uncaught**, exactly like step 3.
3. `$chargebacks->handle($event->event)` — unchanged, still last.

Details:

- **Collection:** the provider resolves the tag exactly as
  `makeConfirmationDispatcher()` does (`$container->has(TAG) ? $container->get(TAG) : []`,
  iterable-guarded). Absent tag ⇒ empty lane ⇒ zero behavior change. Every
  resolved item MUST implement `StrictPaymentEventListener`; an invalid item
  fails service construction loudly rather than being skipped.
- **Deterministic order:** listener concrete classes are unique. The factory
  rejects a duplicate FQCN with `LogicException`, then sorts the unique
  listeners by FQCN (`get_class`) before invocation. A priority mechanism is
  deliberately omitted (YAGNI); the uniqueness rule closes the otherwise
  ambiguous equal-key case.
- **Immediate retry requires an owner-fenced claim release.** Today
  `claimLogicalForDispatch()` changes the row to `dispatching`; if dispatch
  throws, an immediate provider or queue retry cannot reclaim it until the
  default 300-second stale window and can be acknowledged without invoking
  listeners. Adding a method to the existing public
  `ProviderEventRepositoryInterface` would break third-party implementations
  in a minor release, so that interface remains byte-for-byte unchanged. Add
  a separate additive capability contract:

  ```php
  interface LogicalDispatchLeaseRepositoryInterface
  {
      public function acquireLogicalDispatchLease(
          string $gateway,
          string $logicalEventKey,
          int $staleSeconds = 300,
      ): ?string;

      public function completeLogicalDispatch(
          string $gateway,
          string $logicalEventKey,
          string $leaseToken,
      ): bool;

      public function releaseLogicalDispatch(
          string $gateway,
          string $logicalEventKey,
          string $leaseToken,
      ): bool;
  }
  ```

  Migration `009` adds nullable `dispatch_claim_token VARCHAR(64)`.
  Acquisition atomically claims either pending rows or a stale dispatching
  lease and stamps a fresh opaque token plus `dispatch_claimed_at`. Completion
  and release compare `(gateway, logical_event_key, dispatch_status,
  dispatch_claim_token)`: a worker can only complete or release the lease it
  acquired. This fencing is load-bearing. Without it, worker A can exceed the
  stale window, worker B can reclaim the key, and A's later failure can reset
  B's live claim to pending. A concurrent test pins that B remains owner after
  precisely that sequence.

  `ProviderEventRepository` implements both the unchanged legacy repository
  interface and the new lease capability. `WebhookService` receives the lease
  capability as a new optional final constructor argument; Payvia's production
  factory passes the same repository instance for both roles when it implements
  the capability; a custom implementation of only the old interface receives
  `null`. Existing direct constructors/custom repositories therefore remain
  source-compatible and retain the old stale-recovery behavior when the
  optional capability is absent.

  On the lease path, `WebhookService::dispatch()` wraps only the composed
  dispatcher invocation: on any escaping exception it releases its own lease
  and rethrows the original exception; lease completion is reachable only on
  success. If releasing itself fails, Payvia logs that secondary failure and
  rethrows the original dispatch exception; stale-lease recovery remains the
  final backstop. This applies equally to the existing strict chargeback lane.
- **Observable failure:** after release, the original exception still surfaces
  from `WebhookService::ingest()` through the HTTP error path and from
  `ProcessWebhookJob::handle()` to the queue worker. Ordinary bus listener
  exceptions remain swallowed inside `EventService::dispatch()` and therefore
  do not trigger claim release.
- The `makeProviderChargebackDispatcher()` docblock is updated to describe
  BOTH strict lanes and point at the shared contract language.

## 3. First consumer — subscriptions (folded into 2.0.0)

The subscriptions repo amends its unpublished 2.0.0 (spec + plan + CHANGELOG
amendment; **no 2.0.1**):

- **Preserve Payvia as an optional dependency.** The existing
  `PayviaSubscriptionEventBridge` remains Payvia-type-neutral and keeps its
  wrapper-based `__invoke()` entry point for the ≤2.3 fallback. It MUST NOT
  implement a Payvia-owned interface: subscriptions only `suggest`s Payvia,
  and the bridge is currently an unconditional service definition, so doing
  so would make loading subscriptions without Payvia fatal.
- Add a separate `StrictPayviaSubscriptionEventBridge` that implements
  `StrictPaymentEventListener` and delegates projection of the inner event to
  shared logic on the neutral bridge. The strict adapter class is referenced,
  resolved, and tagged only while the strict interface exists; the
  Payvia-absent container never loads it. Payvia 2.4 is a subscriptions
  **development** dependency for contract/type tests, not a runtime
  requirement; runtime `composer.json` keeps Payvia under `suggest`.
- The strict adapter's `supports()` uses a **closed event set** — not a prefix
  rule:

  ```php
  private const SUPPORTED_TYPES = [
      'subscription.created',
      'subscription.updated',
      'subscription.past_due',
      'subscription.canceled',
      'payment.succeeded',
      'invoice.paid',
  ];
  ```

  The type MUST be in that set and
  `normalized()['gateway_subscription_id']` MUST be a non-empty string. Those
  conditions classify vocabulary and shape, not ownership: another Payvia
  consumer may legitimately own a provider subscription with the same event
  shape. Therefore one of these ownership proofs is also required:

  1. `SubscriptionRepository::findByProviderSubscription(context, gateway, id)`
     finds an existing local subscription; or
  2. `normalized()['metadata']['glueful_consumer'] === 'subscriptions'`.

  The repository lookup runs through
  `TenantIntegration::runAsSystemOr(...)`, matching webhook projection's
  system-context discipline; it must not depend on a current tenant being
  established. The explicit, flat provider-metadata marker is written by
  whichever host flow creates a subscriptions-owned provider subscription
  and retained in later normalized events. It is what lets a strict
  `subscription.created` enter the retry path during the narrow race before
  the local row is committed. Existing linked subscriptions need no marker
  because the repository mapping is sufficient. `glueful_consumer` joins
  `ProviderEventData`'s metadata allowlist so accepted events and receipts
  retain the ownership evidence without retaining arbitrary metadata.
  Unknown types, missing IDs, unmapped events without the marker, and one-off
  payments return false. Expanding the projector vocabulary requires a
  conscious list-and-tests change. The projector remains the validation and
  subject/scope authority after this routing decision.

  **§3 CORRECTION (spec-owner ruling, final fix wave) — `subscription.created`
  requires NO ownership proof.** The two proofs above apply to the five
  non-created types only. At creation time there is by definition no local
  (gateway, id) link, and the legacy tenant-metadata relink flow
  (`metadata.tenant_uuid`, no `glueful_consumer`) carries no marker — so
  demanding proof for `subscription.created` silently strands every 1.x-shaped
  checkout the moment the strict lane is enabled. A created event with a
  non-empty `gateway_subscription_id` is therefore supported outright. This is
  safe because the projector, not `supports()`, remains the sole
  subject/scope/receipt/rejection/retry authority after routing: a foreign
  created event resolves to no subject or no local row and is rejected or
  thrown back as retryable-unmapped, never mis-projected. The accepted cost is
  foreign created-event **retries**; a cryptographic correlation token minted
  at checkout and echoed by the provider is the only clean fix, and is out of
  scope for this release.
- **Exactly one lane** (no double invocation): when payvia ≥ 2.4 is
  installed (`interface_exists(StrictPaymentEventListener::class)`),
  `SubscriptionsServiceProvider::services()` conditionally defines the strict
  adapter and publishes that adapter under
  `StrictPaymentEventListener::CONTAINER_TAG` via the `'tags'` key on the
  service definition (framework consults `static tags()` ONLY for defs()-based
  providers; services()/DSL providers like subscriptions publish tags via the
  definition-level `'tags'` key, which is the operative mechanism); `boot()`
  does NOT call `EventService::addListener()`. Subscriptions maintains a
  `static tags()` method as documentation-of-intent but the registered tags
  come from the service definition. With Payvia ≤2.3, the adapter definition
  has no tags and `boot()` keeps the existing neutral bridge as the bus listener.
  With Payvia absent, neither lane registers. A small pure registration-mode
  decision (`strict|bus|none`) takes the two capability booleans so all three
  branches are testable without redefining runtime classes. The strict
  guarantee applies only with Payvia ≥2.4 and is stated in subscriptions'
  CHANGELOG/README and BYOP §6.

## 4. Testing

**Payvia (this repo):**
- Contract/lane units: FQCN ordering is identical across registration orders;
  duplicate concrete classes and non-contract tagged values fail loudly;
  `supports()` false ⇒ `handle()` never called; empty tag ⇒ dispatcher
  behaves byte-identically to today.
- Repository/migration units: migration `009` adds and rolls back the nullable
  claim-token column; lease acquisition returns a fresh opaque token and
  claims only the matching logical key; completion/release require that exact
  token and cannot affect a dispatched row, another key, or a successor lease.
  The stale-worker race is explicit: A claims, B reclaims after A is made stale,
  then A's release and completion both fail while B remains `dispatching`.
- Failure semantics (service-level): a throwing strict listener releases its
  lease, leaves logical dispatch unmarked, and the original exception escapes
  `processStored()`; an **immediate** second attempt with the same delivery and
  logical key invokes it again without altering timestamps or waiting 300
  seconds. A fail-once listener then succeeds and reaches `dispatched` exactly
  once. Ordinary listeners run again under the established at-least-once
  posture; their own exceptions remain fault-isolated.
- Existing chargeback tests are strengthened to prove the same immediate
  retry behavior now that all escaping composed-dispatch failures release their
  lease. A simulated release failure proves stale reclaim remains available
  and the original listener exception is the one rethrown. The secondary log
  is asserted concretely by redirecting PHP's `error_log` to a temporary file;
  documenting instead of testing it is not an acceptable substitute.
- Inline/controller path: the first strict failure escapes into the HTTP error
  path; immediate provider redelivery re-executes rather than receiving a
  false duplicate success. Queue path: a fail-once strict listener makes the
  first `ProcessWebhookJob::handle()` throw and the immediate retry execute
  again and complete; it must not silently return while the claim is fresh.

**Subscriptions (companion, inside the 2.0.0 amendment):**
- Add Payvia `^2.4` as a development dependency and test the strict adapter
  against the real published interface. Payvia remains only a runtime
  suggestion. Add a dedicated strict-lane fake that implements
  `PaymentProviderEventInterface`; the existing `FakePaymentProviderEvent` is
  a bus wrapper and its inner fake deliberately does not implement Payvia's
  optional interface, so neither is reused for the strict contract tests.
- `supports()` matrix: each closed type; missing/empty ID; unknown type with
  an ID; mapped local subscription without marker; unmapped event with the
  exact ownership marker; unmapped event without it; hostile/lookalike marker;
  and one-off payment. The marker survives `ProviderEventData::sanitize()`.
- The neutral bus bridge and strict adapter feed byte-identical
  `ProviderSubscriptionEvent` values into the same projector logic.
- The pure registration-mode decision covers `strict`, `bus`, and `none`;
  provider definition/tag tests prove strict mode defines and tags only the
  strict adapter, boot does not add the bus listener, fallback mode adds only
  the neutral listener, and absent mode does neither. A real
  `ContainerFactory`/compiler test over the generated `bus` and `none`
  definitions proves a Payvia-absent subscriptions install never reflects or
  loads the optional strict adapter or its interface; a source-array/string
  assertion is not an acceptable replacement for this gate.
- Payvia's suite does not execute subscriptions code. Subscriptions' suite
  consumes only Payvia's released contract in its strict-adapter tests; the
  full cross-package webhook round trip lands when both are installed in a
  host (Thallo Phase 2).

## 5. Out of scope

- Any change to ordinary `PaymentProviderEvent` listener semantics.
- Listener priorities beyond FQCN ordering.
- Neutralizing the event shape into `glueful/extension-contracts`.
- A config flag for strict-vs-isolated delivery (rejected: hides a
  failure-semantics change behind per-install config).
