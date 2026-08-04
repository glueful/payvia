<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * Design spec §3.6: the durable projection-acknowledgement contract Payvia OWNS. Subscriptions
 * 2.2's strict bridge calls its outcome-returning projector entry point, then acknowledges
 * `accepted` or deterministic `rejected` against the correlated checkout origination -- the
 * SOLE way a `subscription_checkout_originations` row's required projection consumer records
 * its durable verdict for a given delivery.
 *
 * Duplicate delivery MUST re-read the existing receipt and re-call this with the SAME outcome
 * it originally computed: a crash after projection but before acknowledgement recovers exactly
 * that way, because the writer treats a repeat of the identical (originationUuid, consumer,
 * logicalEventKey, outcome) tuple as an idempotent no-op rather than a failure. Unmapped or
 * transient projection failures must throw and write NO acknowledgement at all -- there is
 * nothing to record until the projector itself reaches a definitive verdict.
 *
 * The concrete writer (bound by `PayviaServiceProvider`) is a compare-and-swap over the
 * origination's current state: a wrong consumer or a status other than `provider_observed` (the
 * one narrow exception being `late_settlement_conflict`, which accepts ONLY a matching
 * `rejected` -- see that writer's own docblock) is refused, and a conflicting second outcome for
 * the SAME `logicalEventKey` throws rather than silently overwriting the first verdict.
 */
interface SubscriptionProjectionAcknowledger
{
    public function acknowledge(
        string $originationUuid,
        string $consumer,
        string $logicalEventKey,
        string $outcome, // accepted|rejected
        ?string $reason = null,
    ): void;
}
