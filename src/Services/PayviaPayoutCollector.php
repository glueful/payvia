<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\PayoutTransferRepository;
use Glueful\Extensions\Payvia\Support\ProviderSafeReference;
use Glueful\Helpers\Utils;

/**
 * Provider-neutral payout/transfer collector backed by the durable
 * `payvia_transfers` attempt table (Commerce Marketplace MV4 spec §2.2).
 *
 * `transfer()` persists a pending attempt row BEFORE any gateway I/O, then
 * calls the resolved
 * {@see \Glueful\Extensions\Payvia\Contracts\TransferCapableGateway}. A
 * duplicate `(tenant, gateway, idempotency_key)` on that pre-I/O insert
 * means this idempotency key already has an attempt: the collector recovers
 * the existing row's already-known result directly, or -- for an unresolved
 * (lost-response) row -- recovers it via the gateway's own
 * `recoverTransfer()`, which uses each provider's safe mechanism (Paystack
 * verifies by reference; Stripe replays the create under the same
 * Idempotency-Key) so neither path can move money twice.
 */
final class PayviaPayoutCollector implements PayoutCollector
{
    public function __construct(
        private GatewayManager $gateways,
        private PayoutTransferRepository $transfers,
    ) {
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): PayoutResult {
        $gatewayKey = $destination->provider;
        $safeRef = $this->providerSafeReference($gatewayKey, $request->idempotencyKey);
        $uuid = Utils::generateNanoID();

        $inserted = $this->transfers->insertPending($context, [
            'uuid' => $uuid,
            'gateway' => $gatewayKey,
            'idempotency_key' => $request->idempotencyKey,
            'provider_reference' => $safeRef,
            'destination_ref' => $destination->accountRef,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'request_payload' => [
                'amount' => $request->amount,
                'currency' => $request->currency,
                'destination_ref' => $destination->accountRef,
                'reason' => $request->reason,
            ],
        ]);

        if (!$inserted) {
            // Duplicate (tenant, gateway, idempotency_key): this attempt is
            // already in flight or resolved. Recover it via each gateway's
            // own safe mechanism -- Paystack never gets a second transfer()
            // call for the same key; Stripe's safe recovery *is* an
            // idempotent replay of transfer() (see recoverTransfer()).
            return $this->recoverTransfer($context, $destination, $request, $safeRef);
        }

        $gateway = $this->gateways->payoutGateway($gatewayKey);
        $result = $gateway->transfer($destination, $request, $safeRef);

        $this->transfers->setResult($context, $uuid, [
            'provider_ref' => $result['provider_ref'] ?? null,
            'status' => (string) $result['status'],
            'message' => $result['failure_reason'] ?? null,
            'raw_payload' => $result['raw'],
        ]);

        return new PayoutResult(
            status: (string) $result['status'],
            providerRef: $this->nullableString($result['provider_ref'] ?? null),
            failureCode: $this->nullableString($result['failure_code'] ?? null),
            failureReason: $this->nullableString($result['failure_reason'] ?? null),
        );
    }

    public function status(
        ApplicationContext $context,
        PayoutDestination $destination,
        string $idempotencyKey
    ): PayoutStatusResult {
        $gatewayKey = $destination->provider;
        $existing = $this->transfers->findByIdempotencyKey($context, $gatewayKey, $idempotencyKey);

        if ($existing === null) {
            // The transfer call never reached Payvia (or belongs to another
            // tenant/gateway) -- a confirmed retryable outcome: nothing was
            // ever attempted under this key, so a fresh attempt is safe.
            return new PayoutStatusResult(
                status: PayoutStatusResult::RETRYABLE_FAILURE,
                reversedAmount: 0,
                providerRef: null,
                failureCode: 'attempt_not_started',
                failureReason: 'No payout transfer attempt has been recorded for this idempotency key.',
            );
        }

        return $this->reconcile($context, $gatewayKey, $existing);
    }

    public function inspectDestination(
        ApplicationContext $context,
        PayoutDestination $destination
    ): DestinationStatus {
        $gateway = $this->gateways->payoutGateway($destination->provider);
        $result = $gateway->inspectAccount($destination->accountRef);

        return new DestinationStatus(
            state: (string) $result['state'],
            failureCode: $this->nullableString($result['failure_code'] ?? null),
        );
    }

    /**
     * Recover a duplicate `(tenant, gateway, idempotency_key)` insert. A row
     * that already carries a known provider result (a provider_ref, or a
     * definite terminal/retryable decline) is mapped straight from the
     * durable row. An unresolved attempt (still `pending` with no
     * provider_ref -- the exact lost-response shape) is recovered via the
     * gateway's own
     * {@see \Glueful\Extensions\Payvia\Contracts\TransferCapableGateway::recoverTransfer()},
     * which uses each provider's safe mechanism -- Paystack verifies the
     * persisted provider-safe reference (never a second `transfer()` call);
     * Stripe replays the identical create request under the same
     * Idempotency-Key ($safeRef), which Stripe de-dupes and returns the
     * original transfer for. Neither path can move money twice.
     */
    private function recoverTransfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request,
        string $safeRef
    ): PayoutResult {
        $gatewayKey = $destination->provider;
        $existing = $this->transfers->findByIdempotencyKey($context, $gatewayKey, $request->idempotencyKey);
        if ($existing === null) {
            // The insert lost a uniqueness race but the winning row is not
            // yet visible to this read -- an infra-level ambiguity, not a
            // classifiable outcome.
            throw new \RuntimeException(
                "Payvia: payout attempt '{$request->idempotencyKey}' conflicted but no attempt row "
                . 'could be recovered.'
            );
        }

        if ($this->hasKnownResult($existing)) {
            return new PayoutResult(
                status: (string) $existing['status'],
                providerRef: $this->nullableString($existing['provider_ref'] ?? null),
                // failure_code is not part of the durable row's exact
                // schema (spec §3.4) -- only status/message survive a
                // known-result recovery; the original call already
                // returned the fully-classified result once, live.
                failureCode: null,
                failureReason: $this->nullableString($existing['message'] ?? null),
            );
        }

        $gateway = $this->gateways->payoutGateway($gatewayKey);
        $providerRef = $this->nullableString($existing['provider_ref'] ?? null);
        $result = $gateway->recoverTransfer($destination, $request, $safeRef, $providerRef);

        $this->transfers->setResult($context, (string) $existing['uuid'], [
            'provider_ref' => $result['provider_ref'] ?? $providerRef,
            'status' => (string) $result['status'],
            'message' => $result['failure_reason'] ?? null,
            'raw_payload' => $result['raw'],
        ]);

        return new PayoutResult(
            status: (string) $result['status'],
            providerRef: $this->nullableString($result['provider_ref'] ?? $providerRef),
            failureCode: $this->nullableString($result['failure_code'] ?? null),
            failureReason: $this->nullableString($result['failure_reason'] ?? null),
        );
    }

    /**
     * Reconcile the durable row's current provider-side state via the
     * gateway's `transferStatus()` and persist the (possibly newly-known)
     * result back onto it.
     *
     * A REVERSED status with a non-positive reversed_amount would be a
     * provider-side inconsistency (a full reversal always reverses the
     * full amount); `PayoutStatusResult`'s own constructor already fails
     * closed on that combination -- an integrity exception, never a
     * silently-downgraded status.
     *
     * @param array<string,mixed> $row
     */
    private function reconcile(ApplicationContext $context, string $gatewayKey, array $row): PayoutStatusResult
    {
        $gateway = $this->gateways->payoutGateway($gatewayKey);
        $providerRef = $this->nullableString($row['provider_ref'] ?? null);

        $result = $gateway->transferStatus((string) $row['provider_reference'], $providerRef);

        $this->transfers->setResult($context, (string) $row['uuid'], [
            'provider_ref' => $result['provider_ref'] ?? $providerRef,
            'status' => (string) $result['status'],
            'message' => $result['failure_reason'] ?? null,
            'raw_payload' => $result['raw'],
        ]);

        return new PayoutStatusResult(
            status: (string) $result['status'],
            reversedAmount: (int) $result['reversed_amount'],
            providerRef: $this->nullableString($result['provider_ref'] ?? $providerRef),
            failureCode: $this->nullableString($result['failure_code'] ?? null),
            failureReason: $this->nullableString($result['failure_reason'] ?? null),
        );
    }

    /** @param array<string,mixed> $row */
    private function hasKnownResult(array $row): bool
    {
        if (($row['provider_ref'] ?? null) !== null) {
            return true;
        }

        return in_array(
            $row['status'] ?? null,
            [PayoutResult::TERMINAL_FAILURE, PayoutResult::RETRYABLE_FAILURE],
            true
        );
    }

    private function providerSafeReference(string $gatewayKey, string $idempotencyKey): string
    {
        return match ($gatewayKey) {
            'stripe' => ProviderSafeReference::forStripe($idempotencyKey),
            'paystack' => ProviderSafeReference::forPaystack($idempotencyKey),
            default => throw new \RuntimeException(
                "Payvia: no provider-safe-reference deriver registered for gateway '{$gatewayKey}'."
            ),
        };
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
