# Strict Payment-Event Lane Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship payvia 2.4.0's opt-in strict payment-event lane (tagged `StrictPaymentEventListener`s + owner-fenced immediate-retry leases) and fold the subscriptions consumer (strict adapter with ownership-aware routing) into the unpublished subscriptions 2.0.0 — per `docs/superpowers/specs/2026-08-02-strict-payment-event-lane-design.md`.

**Architecture:** Two repos, strict order. Phase A (payvia, Tasks 1–4) adds an owner-fenced logical-dispatch lease capability without changing the existing repository interface, adds the listener contract and lane in `makeWebhookService()`'s composed dispatcher, and releases 2.4.0 (local tag). Phase B (subscriptions, Tasks 5–8) adds the strict adapter + ownership routing + registration modes, amends the 2.0.0 spec/plan/CHANGELOG, and re-points the local `v2.0.0` tag. Payvia never learns subscriptions semantics; the subscriptions runtime never hard-requires payvia.

**Tech Stack:** PHP 8.3, glueful/framework ≥1.57 (container tags via provider `static tags()` / DSL `'tags'`, `ContainerFactory::applyProviderTags`), phpunit 10.5 (payvia: `tests/{Unit,Integration,Support}` with `PayviaTestCase` + `FakeWebhookGateway`; subscriptions: `SubscriptionsTestCase` harness), phpstan, phpcs PSR-12.

## Global Constraints (from the spec — every task implicitly includes these)

- Ordinary `PaymentProviderEvent` bus listeners keep today's fault-isolated semantics byte-identical (spec §2 step 1, §5).
- Lane order: fault-isolated bus dispatch → strict tagged lane → chargebacks, chargebacks still last (spec §2).
- Strict lane: FQCN-sorted, duplicate concrete class ⇒ `LogicException`; non-`StrictPaymentEventListener` tagged value ⇒ loud failure at service construction; `supports()` false ⇒ `handle()` never called; empty tag ⇒ byte-identical behavior (spec §2).
- `ProviderEventRepositoryInterface` stays unchanged for 2.4 semver compatibility. A separate `LogicalDispatchLeaseRepositoryInterface` owns acquire/complete/release with an opaque claim token; release and completion are fenced to the exact owner. The optional capability wraps only the composed-dispatcher invocation; release failure is concretely log-tested and rethrows the ORIGINAL dispatch exception; applies equally to the chargeback lane (spec §2).
- Contract typed against `PaymentProviderEventInterface`, payvia-owned, tag `payvia.strict_payment_event_listeners`, at-least-once/idempotency obligations in the interface docblock (spec §1).
- Subscriptions: neutral `PayviaSubscriptionEventBridge` MUST NOT implement the payvia interface (compiled containers reflect every definition; payvia-absent installs would fatal). Separate `StrictPayviaSubscriptionEventBridge`, conditionally defined, shares projection logic (spec §3).
- `supports()` = closed six-type set AND non-empty `gateway_subscription_id` AND ownership proof (local `findByProviderSubscription` under `TenantIntegration::runAsSystemOr`, OR `normalized()['metadata']['glueful_consumer'] === 'subscriptions'`) (spec §3).
- `glueful_consumer` joins `ProviderEventData::METADATA_ALLOW` (spec §3).
- Exactly one lane: payvia ≥2.4 ⇒ tag-only (no `addListener`); payvia ≤2.3 ⇒ bus fallback (documented degraded); payvia absent ⇒ neither. Pure `strict|bus|none` decision function (spec §3).
- Payvia `^2.4` is a subscriptions require-dev dependency only; runtime stays `suggest` (spec §3).
- All gates green per task (`vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, `vendor/bin/phpcs`); conventional commits, no AI-attribution trailers.

## File Structure (final state)

```
payvia/
  src/Contracts/StrictPaymentEventListener.php        (new)
  src/Contracts/LogicalDispatchLeaseRepositoryInterface.php (new)
  src/Contracts/ProviderEventRepositoryInterface.php  (UNCHANGED)
  src/Repositories/ProviderEventRepository.php        (modified: lease capability)
  src/Services/WebhookService.php                     (modified: release-on-failure wrap)
  src/PayviaServiceProvider.php                       (modified: strict lane in makeWebhookService; docblocks)
  migrations/009_AddProviderEventDispatchClaimToken.php (new)
  tests/Unit/StrictLaneCompositionTest.php            (new)
  tests/Integration/StrictDispatchFailureTest.php     (new)
  tests/Integration/ProviderEventRepositoryLeaseTest.php (new)
  CHANGELOG.md / composer.json (2.4.0) / docs/…       (modified)
subscriptions/
  src/Bridge/PayviaSubscriptionEventBridge.php        (modified: extract shared projection entry)
  src/Bridge/StrictPayviaSubscriptionEventBridge.php  (new, conditionally defined)
  src/Bridge/StrictLaneRegistration.php               (new: pure strict|bus|none decision)
  src/Projection/ProviderEventData.php                (modified: +glueful_consumer)
  src/SubscriptionsServiceProvider.php                (modified: conditional definition + tags() + boot branch)
  composer.json (+payvia ^2.4 require-dev)            (modified)
  tests/Support/StrictFakeProviderEvent.php            (new: implements the real payvia interface)
  tests/… (StrictBridgeSupportsTest, StrictLaneRegistrationTest, provider wiring extensions)
  docs/…/2026-08-02-subscriptions-v2-subject-model-design.md (+§6 amendment)
  CHANGELOG.md (2.0.0 amended)                        (modified)
```

---

# PHASE A — payvia 2.4.0

### Task 1: Additive, owner-fenced logical-dispatch leases

**Files:**
- Create: `src/Contracts/LogicalDispatchLeaseRepositoryInterface.php`
- Create: `migrations/009_AddProviderEventDispatchClaimToken.php`
- Modify: `src/Repositories/ProviderEventRepository.php` (implement the new interface; the existing `ProviderEventRepositoryInterface` MUST remain unchanged)
- Modify: `tests/Integration/MigrationsTest.php`
- Test: `tests/Integration/ProviderEventRepositoryLeaseTest.php` (new; mirror the real repository setup in `ProviderEventRepositoryTest`/`RelayEventsTest`)

**Interfaces:**
- Produces the following separate capability. It is deliberately NOT added to `ProviderEventRepositoryInterface`: adding an abstract method to that public substitution seam would break existing implementations in a 2.4 minor release.

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

- `ProviderEventRepository` implements BOTH the unchanged legacy interface and this capability. Migration `009` adds nullable `dispatch_claim_token VARCHAR(64)` and has an idempotent guarded `down()` matching the migration conventions in `006`/`007`.
- Acquisition generates an opaque NanoID token, atomically updates either matching `pending` rows or matching stale `dispatching` rows, and stamps both the token and `dispatch_claimed_at`. It returns the token only when its update wins; otherwise `null`.
- Completion/release compare gateway + logical key + `dispatch_status='dispatching'` + exact token. Completion writes `dispatched`/`dispatched_at` and clears the token. Release writes `pending`, clears token and claimed-at. Both return whether their fenced update won.

- [ ] **Step 1: Write failing migration/repository tests:** migration up/down exposes/removes `dispatch_claim_token`; a pending key returns a non-empty token and becomes dispatching; wrong gateway/key/token cannot release or complete; release clears the matching lease only; completion finalizes the matching lease only and cannot reopen a dispatched row.
- [ ] **Step 2: Pin the stale-owner race:** A acquires; force A's `dispatch_claimed_at` stale; B acquires and receives a different token; A's release and completion both return false; the row remains `dispatching` with B's token; B can complete. This test is the authority for claim ownership — key/status scoping alone is insufficient.
- [ ] **Step 3:** Run `vendor/bin/phpunit --filter 'ProviderEventRepositoryLease|MigrationsTest'` → RED.
- [ ] **Step 4:** Implement migration, capability, and repository methods. Keep the existing `claimLogicalForDispatch`, `reclaimStaleDispatching`, and `markLogicalDispatched` methods unchanged for backward-compatible callers.
- [ ] **Step 5:** Focused tests → PASS; full suite + phpstan + phpcs → green.
- [ ] **Step 6:** Commit `feat: add owner-fenced logical dispatch leases`.

### Task 2: Release-on-failure in `WebhookService::dispatch()`

**Files:**
- Modify: `src/Services/WebhookService.php:164-196` (the `dispatch()` private method)
- Modify: `src/PayviaServiceProvider.php:350-396` (pass the built-in repository as the optional lease capability)
- Test: `tests/Integration/StrictDispatchFailureTest.php` (new — starts here with the CHARGEBACK lane as the throwing strict path, since the tagged lane doesn't exist until Task 3; Task 3 extends this file)

**Interfaces:**
- Consumes: `LogicalDispatchLeaseRepositoryInterface` (Task 1) as a new optional FINAL `WebhookService` constructor argument. Existing constructor call sites remain source-compatible. Payvia's production factory resolves `ProviderEventRepositoryInterface` once, passes it in its existing position, and passes it as the final lease argument only when `instanceof LogicalDispatchLeaseRepositoryInterface`; a custom legacy implementation receives `null` and keeps the old behavior rather than failing construction.
- Produces: on the lease path, ANY exception escaping the composed `$dispatcher` releases only that worker's lease before rethrowing; completion is reachable only on success. When the optional capability is absent, the existing claim/reclaim/mark path remains byte-identical for custom repositories/direct constructors.

- [ ] **Step 1: Failing tests** — build a real `WebhookService` with a dispatcher that throws (constructor takes the dispatcher callable directly; see `makeWebhookService` for argument order):

```php
public function testDispatchFailureReleasesTheClaimAndRethrows(): void
{
    $boom = new \RuntimeException('listener exploded');
    [$service, $uuid] = $this->queuedEventWithDispatcher(
        function () use ($boom): void { throw $boom; }
    );

    try {
        $service->processStored($uuid);
        self::fail('expected the dispatcher exception to escape');
    } catch (\RuntimeException $e) {
        self::assertSame($boom, $e); // ORIGINAL exception, not a wrapper
    }
    self::assertSame('pending', $this->dispatchStatusFor($uuid)); // released, NOT 'dispatching'
}

public function testImmediateRetryAfterFailureInvokesTheDispatcherAgainAndCompletes(): void
{
    $calls = 0;
    $service = $this->serviceWithDispatcher(function () use (&$calls): void {
        $calls++;
        if ($calls === 1) { throw new \RuntimeException('fail once'); }
    });
    $uuid = $this->insertReceivedEvent();

    try { $service->processStored($uuid); } catch (\RuntimeException) {}
    $service->processStored($uuid); // IMMEDIATE retry — no clock manipulation

    self::assertSame(2, $calls);
    self::assertSame('dispatched', $this->dispatchStatusFor($uuid));
}

public function testReleaseFailureLogsAndRethrowsTheOriginalException(): void
{
    // Lease-capability double throws from release; dispatcher throws first.
    // Redirect ini_get('error_log') to a temp file for this test (restore it in
    // finally), assert the ORIGINAL dispatcher exception by identity, and assert
    // the temp file contains the secondary release failure plus logical key.
}
```

`WebhookService::ingest()` calls `processStored()` inline when queueing is off, so the first helper MUST either construct queue mode and capture the enqueued UUID or insert the event directly. It must not call an inline throwing `ingest()` and then expect to receive a UUID.

- [ ] **Step 2:** RED — first test fails on `assertSame('pending', …)` (status stays `dispatching` today).
- [ ] **Step 3: Implement** — acquire through the lease capability when present (otherwise execute today's legacy claim/reclaim block unchanged), then wrap ONLY the dispatcher invocation:

```php
if ($this->dispatcher !== null) {
    try {
        ($this->dispatcher)(new PaymentProviderEvent($event));
    } catch (\Throwable $dispatchFailure) {
        if ($leaseToken !== null && $this->logicalDispatchLeases !== null) {
            try {
                $this->logicalDispatchLeases->releaseLogicalDispatch(
                    $event->gateway(),
                    $event->logicalEventKey(),
                    $leaseToken,
                );
            } catch (\Throwable $releaseFailure) {
                error_log(sprintf(
                    '[Payvia] logical dispatch lease release failed for %s/%s: %s '
                    . '(stale-lease recovery remains the backstop)',
                    $event->gateway(),
                    $event->logicalEventKey(),
                    $releaseFailure->getMessage()
                ));
            }
        }
        throw $dispatchFailure;
    }
}
```

After a successful dispatcher call, the lease path calls `completeLogicalDispatch(..., $leaseToken)` and returns that fenced result; it never calls legacy `markLogicalDispatched()`. The legacy path remains unchanged. Update the class/constructor docblocks to describe the optional capability, fencing, release-before-rethrow, and immediate-retry semantics.

- [ ] **Step 4:** GREEN; full suite (existing chargeback tests must still pass — their failure path now ALSO releases; if any asserted the stuck-`dispatching` state, update them to the new contract and say so in the commit body) + gates.
- [ ] **Step 5:** Commit `feat: use fenced leases for retryable logical dispatch`.

### Task 3: The strict tagged lane

**Files:**
- Create: `src/Contracts/StrictPaymentEventListener.php`
- Modify: `src/PayviaServiceProvider.php` (`makeWebhookService()` ~line 350; `makeProviderChargebackDispatcher()` docblock ~line 321)
- Test: `tests/Unit/StrictLaneCompositionTest.php` (new), extend `tests/Integration/StrictDispatchFailureTest.php`

**Interfaces:**
- Produces (verbatim, spec §1):

```php
namespace Glueful\Extensions\Payvia\Contracts;

interface StrictPaymentEventListener
{
    public const CONTAINER_TAG = 'payvia.strict_payment_event_listeners';
    public function supports(PaymentProviderEventInterface $event): bool;
    public function handle(PaymentProviderEventInterface $event): void;
}
```

Plus a static composition helper the provider and tests share:
`PayviaServiceProvider::composeStrictLane(iterable $tagged): array` — validates every item implements the contract (else `\LogicException` naming the offender), rejects duplicate concrete FQCNs (`\LogicException` naming the class), returns the FQCN-sorted list.

- [ ] **Step 1: Failing unit tests** (`StrictLaneCompositionTest`): sorted-by-FQCN regardless of input order; duplicate FQCN throws; non-implementing object throws; empty iterable returns `[]`.
- [ ] **Step 2:** RED. **Step 3: Implement** — contract file with the full obligations docblock (idempotent, at-least-once, may re-run after sibling failure, throwing prevents dispatch-marking and produces retryable delivery). `composeStrictLane()`:

```php
/** @param iterable<mixed> $tagged @return list<StrictPaymentEventListener> */
public static function composeStrictLane(iterable $tagged): array
{
    $byClass = [];
    foreach ($tagged as $item) {
        if (!$item instanceof StrictPaymentEventListener) {
            throw new \LogicException(sprintf(
                'Tagged strict payment-event listener %s does not implement %s.',
                get_debug_type($item),
                StrictPaymentEventListener::class
            ));
        }
        $class = get_class($item);
        if (isset($byClass[$class])) {
            throw new \LogicException("Duplicate strict payment-event listener class {$class}.");
        }
        $byClass[$class] = $item;
    }
    ksort($byClass, SORT_STRING);
    return array_values($byClass);
}
```

`makeWebhookService()` dispatcher becomes (chargebacks last, unchanged):

```php
$strict = self::composeStrictLane(
    $container->has(StrictPaymentEventListener::CONTAINER_TAG)
        && is_iterable($tagged = $container->get(StrictPaymentEventListener::CONTAINER_TAG))
        ? $tagged
        : []
);
$dispatcher = static function (PaymentProviderEvent $event) use ($container, $chargebacks, $strict): void {
    if ($container->has(EventService::class)) {
        $container->get(EventService::class)->dispatch($event); // fault-isolated, unchanged
    }
    foreach ($strict as $listener) {           // strict lane: uncaught, like chargebacks
        if ($listener->supports($event->event)) {
            $listener->handle($event->event);
        }
    }
    $chargebacks->handle($event->event);
};
```

Update `makeProviderChargebackDispatcher()`'s docblock to name both strict lanes and the shared release-on-failure semantics.

- [ ] **Step 4:** Integration extensions (in `StrictDispatchFailureTest`): a tagged fail-once strict listener → first `processStored` throws + its lease is released, immediate retry succeeds and completes a new lease exactly once; a `supports()`-false listener is never invoked; with no tag registered the dispatcher output is behaviorally identical to a pre-lane service (assert same statuses + same ordinary-listener invocations). Inline controller path: reuse `WebhookIngestionTest`'s harness to assert the first strict failure produces the error response and the SECOND delivery of the same payload executes rather than returning a false duplicate-success. Queue path: `ProcessWebhookJobTest` gains a fail-once case (first `handle()` throws, retry completes).
- [ ] **Step 5:** Full suite + gates; commit `feat: opt-in strict payment-event lane (tagged listeners)`.

### Task 4: Payvia 2.4.0 release chores

**Files:** `CHANGELOG.md`, `composer.json` (`extra.glueful.version` → `2.4.0`), the webhook docs page if one exists under `docs/` (grep for the webhook section; add the strict-lane contract + listener obligations), migration inventory/docs if this repo maintains one.

- [ ] CHANGELOG 2.4.0: the lane (opt-in, ordering, obligations), additive owner-fenced lease capability + migration `009` + immediate-retry semantics, explicit note that the old repository interface is unchanged and the chargeback lane also gains release-on-failure, zero changes to ordinary listener semantics.
- [ ] Gates green; commit per repo convention (`Release 2.4.0 — strict payment-event lane`, matching `git log`'s existing `Release X` style); local tag `v2.4.0`; nothing pushed.

---

# PHASE B — subscriptions 2.0.0 amendment

### Task 5: Ownership-aware strict adapter

**Files:**
- Modify: `src/Bridge/PayviaSubscriptionEventBridge.php` (extract the projection entry so both bridges share it)
- Create: `src/Bridge/StrictPayviaSubscriptionEventBridge.php`
- Modify: `composer.json` (add `"glueful/payvia": "^2.4"` to require-dev; `suggest` untouched; run `composer update glueful/payvia` — Packagist must satisfy it after Task 4's release is published; until then use the sibling path repo with `"canonical": false`, same pattern as extension-contracts, and NOTE in the report that the constraint resolves from Packagist once 2.4.0 publishes)
- Create: `tests/Support/StrictFakeProviderEvent.php` (implements the real `PaymentProviderEventInterface`; do not alter/reuse the deliberately Payvia-neutral bus fakes)
- Test: `tests/Integration/Bridge/StrictBridgeSupportsTest.php` (new)

**Interfaces:**
- Consumes: payvia's `StrictPaymentEventListener` + `PaymentProviderEventInterface` (dev-dep), `SubscriptionRepository::findByProviderSubscription(ApplicationContext, string, string): ?array`, `TenantIntegration::runAsSystemOr(ApplicationContext, callable)`, the neutral bridge's projection path.
- Produces: the neutral bridge gains `public function projectInner(object $inner): void` containing the existing DTO construction/projection body, and `__invoke()` delegates to it after unwrapping the bus event. `StrictPayviaSubscriptionEventBridge` implements `StrictPaymentEventListener`, takes `(PayviaSubscriptionEventBridge $neutralBridge, SubscriptionRepository $subscriptions, ApplicationContext $context)`, and delegates `handle()` to `$neutralBridge->projectInner($event)`. This is one executable mapping authority, not two copied DTO constructors held together only by a parity test.

- [ ] **Step 1: Failing tests** — first add `StrictFakeProviderEvent implements PaymentProviderEventInterface` with explicit constructor values for all eight interface methods, then run the full spec §4 matrix against it. `FakePaymentProviderEvent` is the bus wrapper and its inner `FakeProviderEvent` deliberately does NOT implement Payvia's optional interface; changing either would erase the existing optional-dependency test posture.
  - each of the six SUPPORTED_TYPES with a linked local row (seed via harness) and no marker → `supports()` true;
  - `subscription.created` with NO local row but `metadata.glueful_consumer==='subscriptions'` → true (the checkout race);
  - unmapped + no marker → false; hostile lookalike markers (`'Subscriptions'`, `' subscriptions '`, nested array) → false (strict `===`);
  - unknown type with an id → false; supported type with missing/empty id → false; one-off `payment.succeeded` without id → false;
  - `handle()` delegates to the same projection entry as `__invoke` (spy projector receives identical `ProviderSubscriptionEvent` fields from both paths — the parity test);
  - the repository lookup runs under system mode (RecordingTenantContextRunner from Task-12 support records `system`).
- [ ] **Step 2:** RED. **Step 3: Implement**:

```php
private const SUPPORTED_TYPES = [
    'subscription.created', 'subscription.updated', 'subscription.past_due',
    'subscription.canceled', 'payment.succeeded', 'invoice.paid',
];

public function supports(PaymentProviderEventInterface $event): bool
{
    if (!in_array($event->type(), self::SUPPORTED_TYPES, true)) {
        return false;
    }
    $normalized = $event->normalized();
    $gwSubId = $normalized['gateway_subscription_id'] ?? null;
    if (!is_string($gwSubId) || $gwSubId === '') {
        return false;
    }
    $mapped = TenantIntegration::runAsSystemOr(
        $this->context,
        fn (): ?array => $this->subscriptions->findByProviderSubscription($this->context, $event->gateway(), $gwSubId)
    );
    if ($mapped !== null) {
        return true;
    }
    $metadata = $normalized['metadata'] ?? null;
    return is_array($metadata) && ($metadata['glueful_consumer'] ?? null) === 'subscriptions';
}

public function handle(PaymentProviderEventInterface $event): void
{
    $this->neutralBridge->projectInner($event);
}
```

The parity test still pins the resulting `ProviderSubscriptionEvent` fields, but delegation is the structural guarantee that the two lanes cannot drift.

- [ ] **Step 4:** GREEN; full suite + gates. **Step 5:** Commit `feat: strict payvia lane adapter with ownership-aware routing`.

### Task 6: `glueful_consumer` in the sanitizer allowlist

**Files:** Modify `src/Projection/ProviderEventData.php` (the `METADATA_ALLOW` const); Test: extend `tests/Unit/Projection/ProviderEventDataTest.php` (locate by `--filter ProviderEventData`).

- [ ] Failing test: sanitize a payload whose metadata carries `glueful_consumer='subscriptions'` + hostile nested secrets → output metadata retains `glueful_consumer` exactly; secrets absent; a receipts-vs-events parity assertion confirms both stores keep the marker.
- [ ] Implement: add `'glueful_consumer'` to `METADATA_ALLOW`. GREEN; gates; commit `feat: retain the glueful_consumer ownership marker through sanitization`.

### Task 7: Registration modes (`strict|bus|none`)

**Files:**
- Create: `src/Bridge/StrictLaneRegistration.php`
- Modify: `src/SubscriptionsServiceProvider.php` (conditional definition + `static tags()` + `boot()` branch at the S7 listener block ~line 381)
- Test: `tests/Unit/Bridge/StrictLaneRegistrationTest.php` (new) + extend `tests/Integration/ServiceProviderWiringTest.php`

**Interfaces:**
- Produces:

```php
final class StrictLaneRegistration
{
    public const STRICT = 'strict';
    public const BUS = 'bus';
    public const NONE = 'none';
    /** $strictContractPresent = interface_exists(StrictPaymentEventListener);
        $payviaEventPresent = class_exists(Payvia PaymentProviderEvent). */
    public static function decide(bool $strictContractPresent, bool $payviaEventPresent): string
    {
        return $strictContractPresent ? self::STRICT : ($payviaEventPresent ? self::BUS : self::NONE);
    }
}
```

- [ ] **Step 1: Failing tests** — unit: the three-branch truth table (true/* ⇒ strict; false/true ⇒ bus; false/false ⇒ none). Wiring: in strict mode the provider's `services()` defines `StrictPayviaSubscriptionEventBridge` and `tags()` publishes it under `StrictPaymentEventListener::CONTAINER_TAG` while `boot()` registers NO `addListener` for the payvia event; the definition for the strict adapter is ABSENT from `services()` when the contract interface is absent (test through a pure `serviceDefinitionsForMode($mode)` helper consumed by the real `services()` method); bus fallback keeps the existing lazy `addListener`.
- [ ] **Step 2: Required compiled-container gate** — feed the actual `bus` and `none` definition maps from `serviceDefinitionsForMode()` through the framework's real `ContainerFactory`/compiler harness in isolated tests, then resolve/boot the resulting container and assert no reflection/autoload attempt names `StrictPayviaSubscriptionEventBridge` or `StrictPaymentEventListener`. A grep, serialized-array inspection, or “closest available” source assertion does NOT satisfy this gate. Reuse the closest existing compiled-container test fixture; if the subscriptions harness lacks one, add the minimal real compiler fixture rather than weakening the assertion.
- [ ] **Step 3:** RED. **Step 4:** Implement: provider gains `private static function strictLaneMode(): string` calling `decide(interface_exists(...), class_exists(...))`; `services()` delegates to `serviceDefinitionsForMode()` and appends the strict-adapter definition only in strict mode; `static tags()` returns `[StrictPaymentEventListener::CONTAINER_TAG => [StrictPayviaSubscriptionEventBridge::class]]` in strict mode, `[]` otherwise; `boot()`'s S7 block registers the bus listener only in bus mode (comment: "degraded fault-isolated fallback for payvia ≤2.3 — the retryable-unmapped guarantee requires ≥2.4").
- [ ] **Step 5:** GREEN; full suite + gates. **Step 6:** Commit `feat: single-lane strict/bus/none registration for the payvia bridge`.

### Task 8: 2.0.0 amendment — spec, CHANGELOG, BYOP, retag

**Files:** Modify `docs/superpowers/specs/2026-08-02-subscriptions-v2-subject-model-design.md` (§6 gains the strict-lane delivery contract + a pointer to the payvia spec), `docs/superpowers/plans/2026-08-02-subscriptions-v2-subject-model.md` (append an "Amendment: strict payvia lane" section listing Tasks 5–7 of THIS plan as the executed change), `CHANGELOG.md` (2.0.0 entry gains the strict adapter, ownership marker, registration modes, and the "strict guarantee requires payvia ≥2.4; ≤2.3 falls back to fault-isolated bus delivery" note), `docs/BRING_YOUR_OWN_PROVIDER.md` (§6: the payvia channel now honors the retryable contract via the strict lane; other channels must do equivalently).

- [ ] Make the four doc edits; run full gates once.
- [ ] Commit `docs: fold the strict payvia lane into the 2.0.0 release notes and spec`.
- [ ] Re-point the local tag: `git tag -f v2.0.0 HEAD -m "subscriptions 2.0.0 — subject model"` (unpublished local tag; the release commit history is unchanged).

---

## Self-Review

- **Spec coverage:** §1 → Task 3 (contract) with obligations docblock; §2 lane/ordering/duplicate/loud-failure → Task 3; §2 additive lease capability + owner fencing + chargeback retrofit + immediate retry → Tasks 1–2; §2 observable failure (inline + queue) → Tasks 2–3 tests; §3 neutral-bridge preservation + strict adapter → Task 5; §3 ownership routing + marker → Tasks 5–6; §3 single-lane registration modes + real compiled-container gate → Task 7; §3 dev-dep → Task 5; §4 payvia tests → Tasks 1–3; §4 subscriptions tests → Tasks 5–7; §5 out-of-scope respected (no ordinary-listener changes anywhere). Release mechanics → Tasks 4, 8. No gaps found.
- **Placeholder scan:** no conditional “capture-or-document” or “closest available” escape hatches remain. The secondary error log and compiled-container behavior are mandatory executable gates.
- **Type consistency:** the old `ProviderEventRepositoryInterface` is unchanged; `LogicalDispatchLeaseRepositoryInterface` uses `?string` acquisition and token-fenced boolean complete/release consistently across Tasks 1–2; `StrictPaymentEventListener` shape is consistent across Tasks 3, 5, 7; `composeStrictLane(iterable): array` is used only in Task 3; `StrictLaneRegistration::decide(bool,bool): string` matches its Task-7 usages; `projectInner(object): void` is named identically in Task 5's Interfaces and body text.
- **Cross-repo sequencing:** Task 5's require-dev on payvia `^2.4` depends on Task 4's tag existing at least locally via the path repo (`canonical:false`) until Packagist publication — called out inline in Task 5.
