<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * The opt-in strict payment-event lane. Implementers tag their service with `CONTAINER_TAG`;
 * `PayviaServiceProvider::makeWebhookService()` composes every tagged instance (via
 * `composeStrictLane()`) into a single, FQCN-sorted lane that runs AFTER the fault-isolated
 * ordinary `PaymentProviderEvent` bus dispatch and BEFORE the chargeback dispatcher, inside the
 * same composed `$dispatcher` callable `WebhookService::dispatch()` invokes under its
 * lease/claim.
 *
 * Delivery is therefore governed by the SAME lease semantics as the chargeback lane, not the
 * fault-isolated bus semantics: `handle()` runs uncaught. Throwing releases the in-flight
 * logical-dispatch lease (or, without the lease capability, leaves the row `dispatching` for a
 * later stale-claim sweep) and rethrows, so the row's dispatch is never marked complete and the
 * SAME event -- not just the strict lane in isolation -- is retryable, either immediately
 * (`processStored()` again) or via `relayPending()`.
 *
 * Consequently every implementation MUST be:
 * - Idempotent: `handle()` may be invoked more than once for the identical logical event,
 *   including re-runs triggered solely because a DIFFERENT sibling listener (another strict
 *   listener, or the chargeback dispatcher) failed after this one already ran successfully on an
 *   earlier attempt.
 * - At-least-once, never exactly-once: a throw anywhere in the composed dispatcher -- this
 *   listener, another strict listener, or the chargeback dispatcher -- prevents the logical
 *   dispatch from being marked complete, so the whole lane (including listeners that already
 *   succeeded) may run again on retry.
 * - Aware that `supports()` gates delivery: `handle()` is only ever called for an event on which
 *   `supports()` most recently returned `true`; returning `false` must be side-effect-free.
 */
interface StrictPaymentEventListener
{
    public const CONTAINER_TAG = 'payvia.strict_payment_event_listeners';

    public function supports(PaymentProviderEventInterface $event): bool;

    public function handle(PaymentProviderEventInterface $event): void;
}
