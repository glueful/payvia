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
  queue mode).

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
  iterable-guarded). Absent tag ⇒ empty lane ⇒ zero behavior change.
- **Deterministic order:** listeners sorted by FQCN (`get_class`) before
  invocation. Sufficient for v1; a priority mechanism is deliberately
  omitted (YAGNI) and would be a conscious follow-up.
- **Failure semantics need no new plumbing.** An escaping exception already
  leaves `markLogicalDispatched()` unreached (the row stays redeliverable by
  `relayPending()`), already surfaces from `WebhookService::ingest()` →
  `WebhookController::handle()` as a non-2xx, and already propagates out of
  `ProcessWebhookJob::handle()` so the queue worker retries. The existing
  redelivery-re-runs-ordinary-listeners posture is unchanged — it is already
  true for the chargeback lane today.
- The `makeProviderChargebackDispatcher()` docblock is updated to describe
  BOTH strict lanes and point at the shared contract language.

## 3. First consumer — subscriptions (folded into 2.0.0)

The subscriptions repo amends its unpublished 2.0.0 (spec + plan + CHANGELOG
amendment; **no 2.0.1**):

- `PayviaSubscriptionEventBridge` implements `StrictPaymentEventListener`.
  `supports()` uses a **closed event set** — not a prefix rule:

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

  `supports()` returns true iff BOTH: `type()` is in that exact set AND
  `normalized()['gateway_subscription_id']` is a non-empty string. This
  excludes one-off payments, unknown future event types, and unrelated
  provider objects; expanding the projector's vocabulary requires
  consciously expanding this list and its tests. (The projector keeps its
  own guards unchanged — the bridge filter is the lane gate, not the
  validation authority.)
- **Exactly one lane** (no double invocation): when payvia ≥ 2.4 is
  installed (`interface_exists(StrictPaymentEventListener::class)`), the
  subscriptions provider registers the bridge ONLY under the container tag
  and does NOT `EventService::addListener()`. With payvia ≤ 2.3 the current
  bus listener remains as a **documented degraded fallback** (fault-isolated
  delivery; the retryable-unmapped guarantee does not hold). The strict
  guarantee applies only with payvia ≥ 2.4 — stated in subscriptions'
  CHANGELOG/README and BYOP §6.

## 4. Testing

**Payvia (this repo):**
- Contract/lane units: FQCN ordering deterministic across registration
  orders; `supports()` false ⇒ `handle()` never called; empty tag ⇒
  dispatcher behaves byte-identically to today.
- Failure semantics (service-level): a throwing strict listener leaves the
  logical dispatch unmarked and the exception escapes `processStored()`;
  redelivery invokes the same listener again (at-least-once proven); a
  strict failure does not prevent ordinary listeners having run (existing
  posture); ordinary-listener exceptions remain swallowed (fault isolation
  untouched).
- Controller-level: `ingest()` inline mode surfaces the escaping exception
  (the existing error path); queue mode enqueues normally (the job-side
  propagation is already covered by the job's own contract).

**Subscriptions (companion, inside the 2.0.0 amendment):**
- `supports()` decision matrix on the existing `FakePaymentProviderEvent`
  (each supported type × with/without `gateway_subscription_id`; an
  unsupported type with an id; empty-string id).
- Registration branch: with the contract interface present the provider
  defines the bridge tagged and skips `addListener`; the ≤ 2.3 fallback
  branch keeps the listener (tested via the provider's definition arrays,
  not runtime class fakery).
- Neither suite executes the other repo's code; each side tests its half of
  the shared contract. The true end-to-end lands when both are installed in
  a host (Thallo Phase 2).

## 5. Out of scope

- Any change to ordinary `PaymentProviderEvent` listener semantics.
- Listener priorities beyond FQCN ordering.
- Neutralizing the event shape into `glueful/extension-contracts`.
- A config flag for strict-vs-isolated delivery (rejected: hides a
  failure-semantics change behind per-install config).
