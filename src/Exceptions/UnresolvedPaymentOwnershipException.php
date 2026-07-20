<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Exceptions;

/**
 * Thrown by `ProviderChargebackDispatcher::handle()` when the payment OWNER of a recognized
 * dispute/chargeback webhook cannot be resolved to exactly one persisted `payments` row under
 * the global (gateway, gateway_transaction_id) identity -- either zero rows matched, or more
 * than one did. `ProviderCorrelationRepository::findPaymentOwnerByGatewayTxn()` collapses both
 * cases to a single `null`, so this exception -- and the fail-closed refusal it represents --
 * covers both without ever guessing which row is the real owner.
 *
 * This always fires AFTER `WebhookService` has already persisted (and, for this delivery,
 * claimed for dispatch) the triggering `provider_events` row. Letting it propagate out of the
 * dispatcher callback keeps that row's logical dispatch UNMARKED (`dispatch_status` never
 * reaches `dispatched`), so the durable row stays redispatchable rather than a fabricated
 * `ProviderChargebackEvent` ever going out.
 */
final class UnresolvedPaymentOwnershipException extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely, mirroring
     * `Glueful\Extensions\Payvia\Services\UnresolvedSubscriptionOwnershipException::MARKER`.
     */
    public const MARKER = 'unresolved_payment_ownership';

    public static function forGatewayTransaction(string $gateway, string $gatewayTransactionId): self
    {
        $detail = $gatewayTransactionId !== ''
            ? sprintf('gateway_transaction_id "%s"', $gatewayTransactionId)
            : 'no usable gateway_transaction_id in the normalized provider payload';

        return new self(sprintf(
            '%s: could not resolve exactly one payments owner for %s %s',
            self::MARKER,
            $gateway,
            $detail
        ));
    }

    /**
     * Distinct from {@see forGatewayTransaction()}: this fires when the normalized payload has
     * no usable `dispute_provider_event_id` at all, so the correlation lookup is never even
     * attempted -- `gateway_transaction_id` may well be present and perfectly valid. Reusing
     * `forGatewayTransaction()`'s "could not resolve exactly one payments owner for
     * gateway_transaction_id X" wording here would misreport a missing dispute id as a failed
     * ownership lookup against that transaction id, conflating two distinct failure causes.
     */
    public static function forMissingDisputeId(string $gateway, ?string $gatewayTransactionId): self
    {
        $detail = $gatewayTransactionId !== null && $gatewayTransactionId !== ''
            ? sprintf('gateway_transaction_id "%s" was present', $gatewayTransactionId)
            : 'no gateway_transaction_id was present either';

        return new self(sprintf(
            '%s: no usable dispute_provider_event_id in the normalized provider payload for %s (%s)',
            self::MARKER,
            $gateway,
            $detail
        ));
    }
}
