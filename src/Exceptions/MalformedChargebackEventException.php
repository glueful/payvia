<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Exceptions;

/**
 * Thrown by `ProviderChargebackDispatcher::handle()` when a recognized dispute/chargeback
 * webhook's resolved disputed amount is zero or negative -- either a literal `0` in the
 * normalized payload (which bypasses the `?? $payable->amount` fallback entirely, since `0` is
 * not `null`) or a fallback to a non-positive correlated-payment amount.
 *
 * The contracts `ProviderChargebackEvent` constructor requires `amount > 0` and would otherwise
 * throw a generic `\InvalidArgumentException` from inside event construction. Failing closed
 * HERE, before construction, keeps this a classified, greppable Payvia failure rather than an
 * unhandled contract exception -- and, exactly like `UnresolvedPaymentOwnershipException`, no
 * fabricated event is ever dispatched and this rides `WebhookService`'s existing generic
 * `\Throwable` handling, leaving the triggering `provider_events` row in the same
 * retryable/failed state.
 */
final class MalformedChargebackEventException extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely, mirroring
     * `UnresolvedPaymentOwnershipException::MARKER`.
     */
    public const MARKER = 'malformed_chargeback_event';

    public static function forNonPositiveAmount(string $gateway, string $disputeId, int $amount): self
    {
        return new self(sprintf(
            '%s: %s dispute %s resolved a non-positive disputed amount (%d)',
            self::MARKER,
            $gateway,
            $disputeId,
            $amount
        ));
    }
}
