<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Events;

use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Exceptions\MalformedChargebackEventException;
use Glueful\Extensions\Payvia\Exceptions\UnresolvedPaymentOwnershipException;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;

/**
 * Translates a recognized dispute/chargeback provider event into the neutral contracts
 * `ProviderChargebackEvent` and dispatches it. Payvia-internal only -- this class knows nothing
 * about, and never references, any downstream consumer of that contracts event.
 *
 * Called from `WebhookService`'s existing durable dispatch callback (composed in
 * `PayviaServiceProvider::makeWebhookService()`), AFTER that callback has already delivered
 * the ordinary local `PaymentProviderEvent` and AFTER the triggering `provider_events` row has
 * been persisted and claimed for this delivery. Ownership is resolved through the same
 * fail-closed, tenantless correlation seam `ProviderCorrelationRepository::
 * findPaymentOwnerByGatewayTxn()` (Task 2) added: exactly one persisted `payments` row located
 * by (gateway, gateway_transaction_id). A missing dispute id, missing/unresolvable
 * gateway-transaction ownership (zero or multiple matches), or a malformed non-positive disputed
 * amount each throw their own distinctly-worded exception (`UnresolvedPaymentOwnershipException`
 * or `MalformedChargebackEventException`) -- since this runs from inside
 * `WebhookService::dispatch()`'s dispatcher callback, letting that exception (or any failure
 * from the injected `$dispatch` callable) propagate keeps the logical dispatch UNMARKED, so the
 * durable row stays redispatchable instead of a fabricated event ever going out.
 *
 * `tenantUuid`, `paymentReference`, and the `PayableReference` are built EXCLUSIVELY from the
 * correlated `payments` row -- never from webhook metadata. The dispute's own identity/amount/
 * reason/timing, which the `payments` row has no knowledge of, come from the normalized webhook
 * payload the gateway produced.
 */
final class ProviderChargebackDispatcher
{
    /**
     * The chargeback `$dispatch` callable is dispatched STRICT (in production, a thin wrapper
     * around `EventService::dispatchOrFail()` -- {@see
     * \Glueful\Extensions\Payvia\PayviaServiceProvider::makeProviderChargebackDispatcher()}):
     * unlike ordinary local `PaymentProviderEvent` delivery, a downstream contracts-listener
     * failure here is NOT fault-isolated -- it rethrows and propagates straight out of `handle()`,
     * so `WebhookService` never marks the row dispatched on a listener failure.
     *
     * @param callable(ProviderChargebackEvent):void $dispatch
     */
    public function __construct(
        private readonly ProviderCorrelationRepository $correlation,
        private $dispatch,
    ) {
    }

    public function handle(PaymentProviderEventInterface $event): void
    {
        if (!EventType::isChargeback($event->type())) {
            return;
        }

        $normalized = $event->normalized();
        $gatewayTransactionId = $this->stringOrNull($normalized['gateway_transaction_id'] ?? null);
        $disputeId = $this->stringOrNull($normalized['dispute_provider_event_id'] ?? null);

        if ($disputeId === null) {
            throw UnresolvedPaymentOwnershipException::forMissingDisputeId($event->gateway(), $gatewayTransactionId);
        }

        $owner = $gatewayTransactionId !== null
            ? $this->correlation->findPaymentOwnerByGatewayTxn($event->gateway(), $gatewayTransactionId)
            : null;

        if ($owner === null) {
            throw UnresolvedPaymentOwnershipException::forGatewayTransaction(
                $event->gateway(),
                $gatewayTransactionId ?? ''
            );
        }

        $payable = new PayableReference(
            type: (string) $owner['payable_type'],
            id: (string) $owner['payable_id'],
            amount: (int) $owner['amount'],
            currency: (string) $owner['currency'],
        );

        $isReversal = EventType::isChargebackReversal($event->type());
        $amount = $this->intOrNull($normalized['amount'] ?? null) ?? $payable->amount;

        // A literal `0` (or any non-positive resolved amount) is a malformed/poison event, not a
        // real dispute -- fail closed HERE rather than letting the contract's own `amount > 0`
        // invariant throw a generic, unclassified `\InvalidArgumentException` during construction.
        if ($amount <= 0) {
            throw MalformedChargebackEventException::forNonPositiveAmount($event->gateway(), $disputeId, $amount);
        }

        $chargeback = new ProviderChargebackEvent(
            tenantUuid: (string) $owner['tenant_uuid'],
            provider: $event->gateway(),
            // The dispute's own provider-assigned id is stable across its created -> closed
            // lifecycle. A reversal's own identity is derived (never reused verbatim as the
            // original chargeback's own providerEventId) while relatedEventId links straight
            // back to that original chargeback event.
            providerEventId: $isReversal ? $disputeId . ':reversal' : $disputeId,
            paymentReference: (string) $owner['reference'],
            payable: $payable,
            amount: $amount,
            // Always the correlated row's own currency, never the webhook's: the contracts
            // event requires currency === payable currency, and the row is authoritative.
            currency: $payable->currency,
            reasonCode: $this->stringOrNull($normalized['reason_code'] ?? null),
            occurredAt: $event->occurredAt()->format(DATE_ATOM),
            kind: $isReversal ? ProviderChargebackEvent::KIND_REVERSAL : ProviderChargebackEvent::KIND_CHARGEBACK,
            relatedEventId: $isReversal ? $disputeId : null,
        );

        ($this->dispatch)($chargeback);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
