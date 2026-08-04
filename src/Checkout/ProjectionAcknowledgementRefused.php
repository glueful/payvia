<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

/**
 * Thrown by {@see \Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository::acknowledge()}
 * (design spec §3.6's CAS ack writer) whenever a durable projection acknowledgement cannot be
 * recorded: an unknown origination, a consumer that does not exactly match the row's
 * `required_projection_consumer`, a status the writer never accepts acknowledgements against, a
 * conflicting second outcome for the SAME logical event key, or an `accepted` acknowledgement
 * against a `late_settlement_conflict` row (which accepts only a matching `rejected`).
 *
 * A REPEAT of the identical (consumer, logicalEventKey, outcome) tuple is deliberately NOT an
 * error -- see `acknowledge()`'s own docblock -- so this exception is reserved for genuine
 * refusals only.
 */
final class ProjectionAcknowledgementRefused extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely.
     */
    public const MARKER = 'projection_acknowledgement_refused';

    public static function unknownOrigination(string $originationUuid): self
    {
        return new self(sprintf(
            '%s: no checkout origination exists for uuid "%s".',
            self::MARKER,
            $originationUuid
        ));
    }

    public static function wrongConsumer(string $originationUuid, ?string $required, string $attempted): self
    {
        return new self(sprintf(
            '%s: checkout origination %s requires acknowledgement from consumer "%s", but "%s" attempted it.',
            self::MARKER,
            $originationUuid,
            $required ?? '(none required)',
            $attempted
        ));
    }

    public static function wrongState(string $originationUuid, string $status): self
    {
        return new self(sprintf(
            '%s: checkout origination %s is in status "%s", which does not accept a projection '
                . 'acknowledgement.',
            self::MARKER,
            $originationUuid,
            $status
        ));
    }

    public static function conflictingOutcome(
        string $originationUuid,
        string $existingOutcome,
        string $attemptedOutcome,
    ): self {
        return new self(sprintf(
            '%s: checkout origination %s already recorded outcome "%s" for this logical event; a '
                . 'conflicting "%s" acknowledgement was refused.',
            self::MARKER,
            $originationUuid,
            $existingOutcome,
            $attemptedOutcome
        ));
    }

    public static function lateSettlementConflictRequiresRejected(
        string $originationUuid,
        string $attemptedOutcome,
    ): self {
        return new self(sprintf(
            '%s: late_settlement_conflict checkout origination %s accepts only a matching "rejected" '
                . 'acknowledgement; "%s" was refused.',
            self::MARKER,
            $originationUuid,
            $attemptedOutcome
        ));
    }
}
