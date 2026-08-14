<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\Concerns\DetectsUniqueViolations;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;

final class PaymentService
{
    use DetectsUniqueViolations;

    private ApplicationContext $context;

    /**
     * `$intents` is optional so every existing constructor call site (tests, hosts wiring this
     * service by hand) keeps working unchanged; when it is absent the two intent-aware
     * behaviors below degrade to exactly the flow that preceded them -- there is simply no
     * intent row to consult.
     */
    public function __construct(
        ApplicationContext $context,
        private PaymentRepositoryInterface $payments,
        private GatewayManager $gateways,
        private ?WebhookService $webhooks = null,
        private ?ConfirmationDispatcher $confirmations = null,
        private ?PaymentIntentRepository $intents = null,
    ) {
        $this->context = $context;
    }

    /**
     * Verify a reference with its gateway, upsert the `payments` row, record the verify-origin
     * provider event, and dispatch the payable's confirmation.
     *
     * Two intent-aware guards sit on this path (both no-ops when the reference has no
     * `payment_intents` row of its own -- legacy rows predating the table, and manual/operator
     * references created directly at the provider, never have one):
     *
     *  1. PAYABLE BINDING. The caller-supplied `payable_type`/`payable_id` must agree with the
     *     ones the reference's own intent row is bound to, otherwise
     *     {@see PayableAttributionException} is thrown BEFORE any provider I/O, any `payments`
     *     write, and any confirmation dispatch. Downstream handlers refuse a mismatched
     *     amount/currency, but two equal-amount, equal-currency payables are indistinguishable
     *     to that guard -- so without this an authenticated caller could attribute a reference
     *     they legitimately paid to a DIFFERENT pending payable and have it marked paid.
     *     Refusing before the upsert matters too: `payments` is keyed by reference alone, so a
     *     mismatched confirm would otherwise rewrite the stored row's own attribution.
     *
     *  2. SETTLE-AWARE ORDERING. `recordVerifyEvent()` runs the full webhook machinery inline,
     *     including any strict-lane listener; a host that settles payables from that lane (e.g.
     *     Thallo's `WebhookOrderSettlementListener`, which resolves the payable FROM the intent
     *     row) therefore settles THIS call's reference before control returns here. Dispatching
     *     again would hand the domain handler an already-paid payable and have it record a late
     *     rejection for a payment that was not late -- on the manual-recovery timeline, exactly
     *     where an operator reads it. So the intent row's status is re-read after recording: if
     *     it transitioned to `closed` during that nested path, the confirmation has already been
     *     delivered and this call reports success without dispatching a second time. A GENUINELY
     *     late confirmation -- another attempt's reference against a payable something else
     *     already paid -- does not close THIS reference's intent in the nested path, so it still
     *     dispatches and is still recorded as late.
     *
     * @param array<string,mixed> $context user_uuid, payable_type, payable_id, metadata, options
     * @return array<string,mixed>
     * @throws PayableAttributionException when the supplied payable is not the one the
     *         reference's intent row is bound to.
     */
    public function confirmAndRecord(
        string $reference,
        ?string $gatewayName = null,
        array $context = []
    ): array {
        $gatewayKey = $gatewayName ?: PayviaSettings::defaultGateway($this->context);

        // Tenant-scoped, reference-addressable: another tenant's row is invisible here and takes
        // the unbound path below, so a confirmation can never become a cross-tenant existence
        // oracle.
        $intent = $this->intents?->findByReference($this->context, $gatewayKey, $reference);
        $this->assertPayableBinding($intent, $context, $gatewayKey, $reference);

        $options = (array) ($context['options'] ?? []);
        $gateway = $this->gateways->gateway($gatewayKey);
        $verification = $gateway->verify($reference, $options);

        $status = (string) ($verification['status'] ?? 'failed');
        $providerId = (string) ($verification['id'] ?? '');
        $message = (string) ($verification['message'] ?? '');
        // Gateways already normalize amounts to integer minor units on the wire;
        // this is the single integer carried end-to-end into storage, events, and
        // API responses. No float arithmetic on money.
        $amount = (int) ($verification['amount'] ?? 0);
        $currency = (string) ($verification['currency'] ?? 'GHS');

        // Start with caller-provided metadata
        $metadata = [];
        if (isset($context['metadata']) && is_array($context['metadata'])) {
            $metadata = $context['metadata'];
        }

        // Enrich metadata for known gateways when raw payload is available
        if ($gatewayKey === 'paystack' && isset($verification['raw']) && is_array($verification['raw'])) {
            /** @var array<string,mixed> $raw */
            $raw = $verification['raw'];
            $data = (array) ($raw['data'] ?? []);

            $customer = (array) ($data['customer'] ?? []);
            $authorization = (array) ($data['authorization'] ?? []);

            $extra = [];
            if (isset($customer['email']) && is_string($customer['email'])) {
                $extra['customer_email'] = $customer['email'];
            }
            if (isset($authorization['last4']) && is_string($authorization['last4'])) {
                $extra['card_last4'] = $authorization['last4'];
            }
            if (isset($authorization['brand']) && is_string($authorization['brand'])) {
                $extra['card_brand'] = trim($authorization['brand']);
            }
            if (isset($authorization['bank']) && is_string($authorization['bank'])) {
                $extra['card_bank'] = $authorization['bank'];
            }
            if (isset($data['channel']) && is_string($data['channel'])) {
                $extra['channel'] = $data['channel'];
            }

            if ($extra !== []) {
                $metadata = array_merge($metadata, $extra);
            }
        }

        $payload = [
            'user_uuid' => $context['user_uuid'] ?? null,
            'payable_type' => $context['payable_type'] ?? null,
            'payable_id' => $context['payable_id'] ?? null,
            'gateway' => $gatewayKey,
            'gateway_transaction_id' => $providerId !== '' ? $providerId : null,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'message' => $message !== '' ? $message : null,
            'metadata' => $metadata !== [] ? $metadata : null,
            'raw_payload' => config($this->context, 'payvia.features.store_raw_payload', true)
                ? json_encode($verification, JSON_THROW_ON_ERROR)
                : null,
        ];

        $existing = $this->payments->findByReference($this->context, $reference);
        if ($existing === null) {
            try {
                $this->payments->createPayment($this->context, $payload);
            } catch (\Throwable $e) {
                if (!$this->isUniqueViolation($e)) {
                    throw $e;
                }

                // Concurrent webhook/client retry inserted the row between our
                // find and insert (payments.reference is UNIQUE). Apply the same
                // payload through the update path instead of returning a 500.
                $this->payments->updateByReference($this->context, $reference, $payload);
            }
        } else {
            $this->payments->updateByReference($this->context, $reference, $payload);
        }

        if ($this->webhooks !== null) {
            try {
                $eventType = $status === 'success' ? EventType::PAYMENT_SUCCEEDED : EventType::PAYMENT_FAILED;
                $this->webhooks->recordVerifyEvent(ProviderEvent::create(
                    gateway: $gatewayKey,
                    type: $eventType,
                    providerEventId: $providerId !== '' ? $providerId : null,
                    deliveryKey: $providerId !== '' ? 'verify:' . $providerId : 'verify:' . $reference,
                    entityId: $reference,
                    occurredAt: new \DateTimeImmutable(),
                    normalized: [
                        'reference' => $reference,
                        'gateway_transaction_id' => $providerId !== '' ? $providerId : null,
                        'amount' => $amount,
                        'amount_unit' => 'minor',
                        'currency' => $currency,
                        'status' => $status,
                    ],
                    raw: $verification,
                ));
            } catch (\Throwable) {
                // Payment confirmation must not regress if the optional outbox path is unavailable.
            }
        }

        if (
            $status === 'success'
            && $this->settledWhileRecording($intent, $gatewayKey, $reference)
        ) {
            // Guard 2 (see the method docblock): the nested webhook path already delivered this
            // reference's confirmation and closed its intent. Dispatching again would only
            // produce a spurious late-payment rejection, and the intent settle would be a no-op
            // (`settle()` never transitions out of `closed`).
            return $this->result($status, $gatewayKey, $reference, $amount, $currency, $message, $verification);
        }

        if (
            $status === 'success'
            && is_string($payload['payable_type'])
            && $payload['payable_type'] !== ''
            && is_string($payload['payable_id'])
            && $payload['payable_id'] !== ''
        ) {
            $this->confirmations?->dispatch(
                $this->context,
                new PayableReference(
                    $payload['payable_type'],
                    $payload['payable_id'],
                    $amount,
                    $currency,
                    metadata: $metadata
                ),
                new PaymentConfirmation(
                    'paid',
                    (string) ($verification['reference'] ?? $reference),
                    $amount,
                    $currency,
                    $verification
                ),
                $gatewayKey
            );
        }

        return $this->result($status, $gatewayKey, $reference, $amount, $currency, $message, $verification);
    }

    /**
     * Guard 1 (see {@see confirmAndRecord()}): refuse a caller-supplied payable that disagrees
     * with the one the reference's intent row is bound to.
     *
     * Silent when there is nothing to compare: no intent row (unbound reference), an intent row
     * carrying no payable of its own, or a caller that supplied no payable at all -- that last
     * case already dispatches nothing, so there is no attribution to get wrong.
     *
     * @param array<string,mixed>|null $intent
     * @param array<string,mixed> $context
     */
    private function assertPayableBinding(
        ?array $intent,
        array $context,
        string $gatewayKey,
        string $reference
    ): void {
        if ($intent === null) {
            return;
        }

        $suppliedType = is_string($context['payable_type'] ?? null) ? $context['payable_type'] : '';
        $suppliedId = is_string($context['payable_id'] ?? null) ? $context['payable_id'] : '';
        if ($suppliedType === '' || $suppliedId === '') {
            return;
        }

        $boundType = is_string($intent['payable_type'] ?? null) ? $intent['payable_type'] : '';
        $boundId = is_string($intent['payable_id'] ?? null) ? $intent['payable_id'] : '';
        if ($boundType === '' || $boundId === '') {
            return;
        }

        if ($boundType !== $suppliedType || $boundId !== $suppliedId) {
            throw PayableAttributionException::forReference($gatewayKey, $reference);
        }
    }

    /**
     * Guard 2 (see {@see confirmAndRecord()}): did THIS call's own `recordVerifyEvent()` chain
     * settle THIS reference?
     *
     * Answered by comparing the intent row's status across that call rather than by asking the
     * listener lane anything -- payvia does not know which host listeners are wired, only that
     * an intent leaving a live status for `closed` means a confirmation was delivered for it.
     * `ConfirmationDispatcher::dispatch()` is the sole writer of that transition and always runs
     * the handlers first, so a row observed `closed` here has already had its confirmation
     * handled. A row that was ALREADY `closed` before this call is not this case: nothing
     * happened during the nested path, so today's behavior (dispatch, and let the domain handler
     * judge the confirmation) is preserved.
     *
     * @param array<string,mixed>|null $intent the row as it was BEFORE the verify event was recorded
     */
    private function settledWhileRecording(?array $intent, string $gatewayKey, string $reference): bool
    {
        if ($this->webhooks === null || $this->intents === null || $intent === null) {
            return false;
        }

        if ((string) ($intent['status'] ?? '') === PaymentIntentRepository::STATUS_CLOSED) {
            return false;
        }

        $current = $this->intents->findByReference($this->context, $gatewayKey, $reference);

        return $current !== null
            && (string) ($current['status'] ?? '') === PaymentIntentRepository::STATUS_CLOSED;
    }

    /**
     * @param array<string,mixed> $verification
     * @return array<string,mixed>
     */
    private function result(
        string $status,
        string $gatewayKey,
        string $reference,
        int $amount,
        string $currency,
        string $message,
        array $verification
    ): array {
        return [
            'payment_status' => $status,
            'gateway' => $gatewayKey,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'message' => $message !== '' ? $message : null,
            'verification' => $verification,
        ];
    }
}
