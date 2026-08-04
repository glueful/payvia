<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

/**
 * Thrown by `WebhookService`'s post-dispatch finalizer (design spec §3.6) when a correlated
 * checkout origination's `subscription.created` delivery finished dispatching but the required
 * projection consumer has not (yet) durably acknowledged THIS logical event:
 *
 * - `forOrigination()`: an ordinary `provider_observed` origination with a required consumer and
 *   no matching acknowledgement recorded at all.
 * - `lateSettlementConflictUnresolved()`: a `late_settlement_conflict` origination whose only
 *   legal completion is a matching `rejected` acknowledgement -- missing, `accepted`, or a
 *   conflicting acknowledgement for this event all land here instead.
 *
 * Thrown from INSIDE `WebhookService::dispatch()`'s lease/claim scope, so it is handled exactly
 * like a dispatcher failure: the in-flight logical-dispatch lease is released (or the claim left
 * for a later stale-claim reclaim), and the provider event stays retryable -- no new ownership
 * row is ever created on retry, and a subsequent delivery that DOES find a matching
 * acknowledgement completes normally.
 */
final class RequiredProjectionAcknowledgementMissing extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely.
     */
    public const MARKER = 'required_projection_acknowledgement_missing';

    public static function forOrigination(
        string $originationUuid,
        string $consumer,
        string $logicalEventKey,
    ): self {
        return new self(sprintf(
            '%s: checkout origination %s requires an acknowledgement from consumer "%s" for logical event '
                . '"%s" before it can finalize, and none has been recorded yet.',
            self::MARKER,
            $originationUuid,
            $consumer,
            $logicalEventKey
        ));
    }

    public static function lateSettlementConflictUnresolved(
        string $originationUuid,
        string $consumer,
        string $logicalEventKey,
        ?string $actualOutcome,
    ): self {
        $detail = $actualOutcome === null
            ? 'no acknowledgement has been recorded yet'
            : sprintf('the recorded acknowledgement was "%s", not the required "rejected"', $actualOutcome);

        return new self(sprintf(
            '%s: late_settlement_conflict checkout origination %s requires a matching "rejected" '
                . 'acknowledgement from consumer "%s" for logical event "%s" before this delivery can '
                . 'complete, but %s.',
            self::MARKER,
            $originationUuid,
            $consumer,
            $logicalEventKey,
            $detail
        ));
    }
}
