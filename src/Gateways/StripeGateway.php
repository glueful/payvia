<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Gateways;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Glueful\Extensions\Payvia\Checkout\DefinitiveSubscriptionCheckoutRejection;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutRequest;
use Glueful\Extensions\Payvia\Contracts\InitiationCapableGateway;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCancellationModeProvider;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCheckoutLifecycleCapableGateway;
use Glueful\Extensions\Payvia\Contracts\SubscriptionInitiationCapableGateway;
use Glueful\Extensions\Payvia\Contracts\TransferCapableGateway;
use Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Http\Client as HttpClient;
use Glueful\Http\Exceptions\HttpClientException;
use Glueful\Http\Response\Response as HttpResponse;

final class StripeGateway implements
    PaymentGatewayInterface,
    InitiationCapableGateway,
    WebhookCapableGateway,
    SubscriptionCapableGateway,
    SubscriptionInitiationCapableGateway,
    SubscriptionCheckoutLifecycleCapableGateway,
    SubscriptionCancellationModeProvider,
    TransferCapableGateway
{
    private ApplicationContext $context;

    public function __construct(
        private HttpClient $httpClient,
        ApplicationContext $context,
    ) {
        $this->context = $context;
    }

    public function verify(string $reference, array $options = []): array
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            return [
                'status' => 'failed',
                'reference' => $reference,
                'message' => 'Missing Stripe secret key (PAYVIA_STRIPE_SECRET_KEY)',
            ];
        }

        $object = (string) ($options['object'] ?? $options['type'] ?? '');
        $isCheckoutSession = $object === 'checkout_session' || str_starts_with($reference, 'cs_');
        $path = $isCheckoutSession ? '/v1/checkout/sessions/' : '/v1/payment_intents/';

        try {
            $response = $this->httpClient->get(
                $this->baseUrl() . $path . rawurlencode($reference),
                $this->requestOptions()
            );

            /** @var array<string,mixed> $decoded */
            $decoded = $response->toArray();
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'reference' => $reference,
                'message' => 'Stripe verification request failed: ' . $e->getMessage(),
            ];
        }

        return $isCheckoutSession
            ? $this->normalizeCheckoutSessionVerification($reference, $decoded)
            : $this->normalizePaymentIntentVerification($reference, $decoded);
    }

    /**
     * Start a hosted Stripe Checkout Session for a payable. The session id (`cs_…`) is the
     * provider reference — exactly what {@see verify()}'s checkout-session branch resolves.
     *
     * `callback_url` (the success URL) is REQUIRED — Stripe rejects a session without one, so a
     * missing value throws here, before any request; `cancel_url` falls back to it. The
     * Idempotency-Key is deterministic per payable, so concurrent initiations of the SAME payable
     * cannot mint two hosted sessions. The response is validated (non-empty `cs_` id, absolute
     * HTTPS checkout URL) BEFORE returning, so a caller can never persist an intent for a
     * malformed session.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function initialize(PayableReference $payable, array $options = []): array
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw new \RuntimeException('Missing Stripe secret key (PAYVIA_STRIPE_SECRET_KEY)');
        }

        $callback = isset($options['callback_url']) && is_string($options['callback_url'])
            ? trim($options['callback_url'])
            : '';
        if ($callback === '') {
            throw new \RuntimeException(
                'Stripe Checkout requires a callback_url (success URL) in the initiation options'
            );
        }
        $cancelOption = isset($options['cancel_url']) && is_string($options['cancel_url'])
            ? trim($options['cancel_url'])
            : '';
        $cancel = $cancelOption !== '' ? $cancelOption : $callback;

        $form = [
            'mode' => 'payment',
            'client_reference_id' => $payable->id,
            'success_url' => $callback,
            'cancel_url' => $cancel,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($payable->currency),
                    'unit_amount' => $payable->amount,
                    'product_data' => [
                        'name' => $payable->description !== null && $payable->description !== ''
                            ? $payable->description
                            : 'Payment',
                    ],
                ],
            ]],
            'metadata' => [
                'payable_type' => $payable->type,
                'payable_id' => $payable->id,
            ],
        ];
        $email = $options['email'] ?? null;
        if (is_string($email) && $email !== '') {
            $form['customer_email'] = $email;
        }

        $requestOptions = $this->requestOptions();
        $requestOptions['headers']['Idempotency-Key'] = 'payvia-init-' . $payable->type . '-' . $payable->id;
        $requestOptions['form_params'] = $form;

        $response = $this->httpClient->post($this->baseUrl() . '/v1/checkout/sessions', $requestOptions);
        [, $decoded] = $this->decodeJsonResponse($response, 'Stripe checkout session');

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            throw new \RuntimeException(
                'Stripe checkout session failed: ' . (string) ($decoded['error']['message'] ?? 'unknown error')
            );
        }

        $sessionId = $decoded['id'] ?? '';
        if (!is_string($sessionId) || !str_starts_with($sessionId, 'cs_')) {
            throw new \RuntimeException('Stripe checkout session returned no usable session id');
        }
        $url = $decoded['url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        if (
            !is_string($url)
            || !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
        ) {
            throw new \RuntimeException('Stripe checkout session returned no usable HTTPS checkout URL');
        }

        return [
            'reference' => $sessionId,
            'checkout_url' => $url,
            'raw' => $decoded,
        ];
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        $signatureHeader = $this->header($headers, 'stripe-signature');
        if ($signatureHeader === '') {
            return false;
        }

        $secret = (string) ($this->config()['webhook_secret'] ?? '');
        if ($secret === '') {
            return false;
        }

        $parts = $this->parseSignatureHeader($signatureHeader);
        $timestamp = (int) ($parts['t'][0] ?? 0);
        if ($timestamp <= 0) {
            return false;
        }

        $tolerance = (int) ($this->config()['webhook_tolerance'] ?? 300);
        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        foreach (($parts['v1'] ?? []) as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhookEvent(string $rawBody, array $headers): PaymentProviderEventInterface
    {
        /** @var array<string,mixed> $payload */
        $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        $providerType = (string) ($payload['type'] ?? '');
        $object = (array) ($payload['data']['object'] ?? []);
        $type = $this->normalizeType($providerType, (string) ($object['status'] ?? ''));
        $normalized = $this->normalizePayload($type, $object);
        $entityId = $this->entityId($type, $object);
        $discriminator = $object['updated'] ?? $payload['created'] ?? $object['current_period_end'] ?? null;

        $providerEventId = isset($payload['id']) && is_scalar($payload['id'])
            ? (string) $payload['id']
            : null;

        return ProviderEvent::create(
            gateway: 'stripe',
            type: $type,
            providerEventId: $providerEventId,
            deliveryKey: $providerEventId !== null ? $providerEventId : hash('sha256', $rawBody),
            entityId: $entityId,
            occurredAt: $this->occurredAt($payload),
            normalized: $normalized,
            raw: $payload,
            discriminator: is_scalar($discriminator) ? (string) $discriminator : null,
        );
    }

    public function fetchSubscription(string $gatewaySubscriptionId): array
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            return ['status' => 'failed', 'message' => 'Missing Stripe secret key'];
        }

        $response = $this->httpClient->get(
            $this->baseUrl() . '/v1/subscriptions/' . rawurlencode($gatewaySubscriptionId),
            $this->requestOptions()
        );

        return $response->toArray();
    }

    public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): array
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            return ['status' => 'failed', 'message' => 'Missing Stripe secret key'];
        }

        if ($atPeriodEnd) {
            $response = $this->httpClient->post(
                $this->baseUrl() . '/v1/subscriptions/' . rawurlencode($gatewaySubscriptionId),
                array_replace($this->requestOptions(), [
                    'form_params' => ['cancel_at_period_end' => 'true'],
                ])
            );
        } else {
            $response = $this->httpClient->delete(
                $this->baseUrl() . '/v1/subscriptions/' . rawurlencode($gatewaySubscriptionId),
                $this->requestOptions()
            );
        }

        return $response->toArray();
    }

    /**
     * Start a hosted Stripe Checkout Session for a NEW subscription (workspace checkout
     * program). Mirrors `initialize()`'s request/validation/error style, but creates a
     * `mode=subscription` session against an existing Price id rather than a one-time
     * `price_data` line item -- this method never delegates to `initialize()`.
     *
     * `client_reference_id` and top-level `metadata.origination_uuid` are set for
     * diagnostics/correlation on the SESSION object, but Stripe does not propagate session
     * metadata onto the subscription it creates -- `subscription_data.metadata` is the
     * documented mechanism that does, so `origination_uuid` is set there too (this is the one
     * value later correlation actually reads off the resulting subscription/webhook).
     *
     * The success URL is REQUIRED (Stripe rejects a session without one) and throws before any
     * request; `cancel_url` falls back to it, exactly like `initialize()`. The Idempotency-Key
     * is derived deterministically from `originationUuid` alone -- NOT the caller's own
     * `idempotencyKey` -- so a replayed initiation of the SAME origination can never mint two
     * sessions.
     *
     * A validated, well-formed provider error body throws the typed {@see
     * DefinitiveSubscriptionCheckoutRejection} (a KNOWN, definitive rejection); anything else
     * -- transport failures, a present-but-unusable `error` body, or a malformed/invalid
     * session response -- is an UNKNOWN outcome and throws a plain exception instead, exactly
     * like `initialize()`'s existing malformed-response handling.
     */
    public function initializeSubscription(SubscriptionCheckoutRequest $request): array
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw new \RuntimeException('Missing Stripe secret key (PAYVIA_STRIPE_SECRET_KEY)');
        }

        $successUrl = trim($request->returnUrl);
        if ($successUrl === '') {
            throw new \RuntimeException(
                'Stripe subscription checkout requires a success URL (returnUrl) in the request'
            );
        }
        $cancelOption = trim($request->cancelUrl);
        $cancel = $cancelOption !== '' ? $cancelOption : $successUrl;

        $form = [
            'mode' => 'subscription',
            'client_reference_id' => $request->originationUuid,
            'success_url' => $successUrl,
            'cancel_url' => $cancel,
            'line_items' => [[
                'quantity' => 1,
                'price' => $request->providerPlanIdentifier,
            ]],
            'metadata' => [
                'origination_uuid' => $request->originationUuid,
            ],
            'subscription_data' => [
                'metadata' => [
                    'origination_uuid' => $request->originationUuid,
                ],
            ],
        ];
        if ($request->customerEmail !== '') {
            $form['customer_email'] = $request->customerEmail;
        }

        $requestOptions = $this->requestOptions();
        $requestOptions['headers']['Idempotency-Key'] = 'payvia-subinit-' . $request->originationUuid;
        $requestOptions['form_params'] = $form;

        $response = $this->httpClient->post($this->baseUrl() . '/v1/checkout/sessions', $requestOptions);
        [, $decoded] = $this->decodeJsonResponse($response, 'Stripe subscription checkout session');

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $message = $decoded['error']['message'] ?? null;
            if (is_string($message) && $message !== '') {
                throw DefinitiveSubscriptionCheckoutRejection::forStripeError($decoded['error'], $decoded);
            }

            // Present but unusable ("looks like an error but carries no message") -- the
            // outcome is unknown, never a validated definitive rejection.
            throw new \RuntimeException(
                'Stripe subscription checkout session returned an unparseable error body'
            );
        }

        $sessionId = $decoded['id'] ?? '';
        if (!is_string($sessionId) || !str_starts_with($sessionId, 'cs_')) {
            throw new \RuntimeException('Stripe subscription checkout session returned no usable session id');
        }
        $url = $decoded['url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        if (
            !is_string($url)
            || !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
        ) {
            throw new \RuntimeException('Stripe subscription checkout session returned no usable HTTPS checkout URL');
        }

        return [
            'reference' => $sessionId,
            'checkout_url' => $url,
            'expires_at' => $this->timestamp($decoded['expires_at'] ?? null),
            'raw' => $decoded,
        ];
    }

    /**
     * On-demand reconciliation of a subscription checkout session already created by {@see
     * initializeSubscription()}, without waiting for a webhook. Maps the session's own
     * `status` (open/complete/expired) onto the shared 5-value enum; an unrecognized or
     * unparseable response is `'unknown'` rather than a fabricated outcome.
     *
     * @return 'pending'|'completed'|'expired'|'canceled'|'unknown'
     */
    public function subscriptionCheckoutStatus(string $reference): string
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw new \RuntimeException('Missing Stripe secret key (PAYVIA_STRIPE_SECRET_KEY)');
        }

        $response = $this->httpClient->get(
            $this->baseUrl() . '/v1/checkout/sessions/' . rawurlencode($reference),
            $this->requestOptions()
        );
        [, $decoded] = $this->decodeJsonResponse($response, 'Stripe subscription checkout status');

        return $this->mapCheckoutStatus($decoded);
    }

    /**
     * Attempt to definitively kill a presumed-abandoned checkout session: expire it, then
     * re-fetch -- the re-fetch is the single source of truth for the final classification, so
     * expire's OWN response (which Stripe errors on for an already-completed/expired session)
     * is intentionally never inspected. `'confirmed_dead'` fires ONLY when the re-fetched
     * session is genuinely `expired`/`canceled`; a still-open or already-completed session is
     * `'still_live'` (never free a guard under it); an unparseable re-fetch is `'unknown'`.
     *
     * @return 'confirmed_dead'|'still_live'|'unsupported'|'unknown'
     */
    public function abandonSubscriptionCheckout(string $reference): string
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw new \RuntimeException('Missing Stripe secret key (PAYVIA_STRIPE_SECRET_KEY)');
        }

        $expireResponse = $this->httpClient->post(
            $this->baseUrl() . '/v1/checkout/sessions/' . rawurlencode($reference) . '/expire',
            $this->requestOptions()
        );
        // Deliberately ignored beyond decoding (guards against a 5xx transport failure) --
        // Stripe rejects expiring an already-terminal session, which is not itself meaningful.
        $this->decodeJsonResponse($expireResponse, 'Stripe subscription checkout expire');

        $statusResponse = $this->httpClient->get(
            $this->baseUrl() . '/v1/checkout/sessions/' . rawurlencode($reference),
            $this->requestOptions()
        );
        [, $decoded] = $this->decodeJsonResponse($statusResponse, 'Stripe subscription checkout status');

        return match ($this->mapCheckoutStatus($decoded)) {
            'expired', 'canceled' => 'confirmed_dead',
            'pending', 'completed' => 'still_live',
            default => 'unknown',
        };
    }

    /**
     * Stripe supports both self-serve cancellation modes over the existing
     * `cancelSubscription()` -- `stop_renewal` (`atPeriodEnd = true`) and `immediate`
     * (`atPeriodEnd = false`).
     *
     * @return list<'stop_renewal'|'immediate'>
     */
    public function cancellationModes(): array
    {
        return ['stop_renewal', 'immediate'];
    }

    /**
     * A Checkout Session can reach `status=complete` while `payment_status` stays `unpaid` --
     * Stripe's own fulfillment guidance is explicit that async payment methods (e.g. bank
     * debits) complete the SESSION immediately but settle the PAYMENT later, surfaced via the
     * separate `checkout.session.async_payment_succeeded` / `.async_payment_failed` events.
     * Fulfillment/activation decisions must key off `payment_status`, never `status` alone, so
     * `complete` only maps to `'completed'` when `payment_status` confirms it (`paid` or
     * `no_payment_required`, e.g. a trial with no payment due); `complete` with `unpaid` (or any
     * other/missing payment_status) stays `'pending'` -- the checkout is still awaiting a
     * definitive payment outcome.
     *
     * @param array<string,mixed> $decoded
     * @return 'pending'|'completed'|'expired'|'canceled'|'unknown'
     */
    private function mapCheckoutStatus(array $decoded): string
    {
        if (isset($decoded['error'])) {
            return 'unknown';
        }

        $status = (string) ($decoded['status'] ?? '');
        $paymentStatus = (string) ($decoded['payment_status'] ?? '');

        return match (true) {
            $status === 'complete' && in_array($paymentStatus, ['paid', 'no_payment_required'], true) => 'completed',
            $status === 'complete' => 'pending',
            $status === 'open' => 'pending',
            $status === 'expired' => 'expired',
            // Stripe's currently-documented Checkout Session `status` enum is only
            // open|complete|expired -- `canceled` never appears in practice. Mapped anyway
            // (dead code against the real API today) purely so this driver satisfies the full
            // shared 5-value contract if a test double or a future API version ever emits it.
            $status === 'canceled' => 'canceled',
            default => 'unknown',
        };
    }

    /**
     * Create a Stripe Transfer to $destination->accountRef (a connected-account
     * id). $providerSafeRef is sent as the Stripe `Idempotency-Key` header --
     * Stripe accepts the caller's canonical attempt key directly (see
     * {@see \Glueful\Extensions\Payvia\Support\ProviderSafeReference::forStripe()}).
     *
     * Returns a normalized array `{status, provider_ref, failure_code,
     * failure_reason, raw}` with `status` one of PayoutResult's five
     * constants. Network/timeout/5xx/unparseable responses throw instead of
     * fabricating a status.
     *
     * @return array<string,mixed>
     */
    public function transfer(PayoutDestination $destination, PayoutRequest $request, string $providerSafeRef): array
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw new \RuntimeException('Missing Stripe secret key (PAYVIA_STRIPE_SECRET_KEY)');
        }

        $options = $this->requestOptions();
        $options['headers']['Idempotency-Key'] = $providerSafeRef;
        $options['form_params'] = array_filter([
            'amount' => $request->amount,
            'currency' => strtolower($request->currency),
            'destination' => $destination->accountRef,
            'description' => $request->reason,
        ], static fn(mixed $value): bool => $value !== null);

        $response = $this->httpClient->post($this->baseUrl() . '/v1/transfers', $options);
        [$statusCode, $decoded] = $this->decodeJsonResponse($response, 'Stripe transfer');

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            return $this->classifyStripeTransferError($statusCode, $decoded['error'], $decoded);
        }

        $settled = array_key_exists('balance_transaction', $decoded) && $decoded['balance_transaction'] !== null;

        return [
            'status' => $settled ? PayoutResult::PAID : PayoutResult::PENDING,
            'provider_ref' => isset($decoded['id']) && is_scalar($decoded['id']) ? (string) $decoded['id'] : null,
            'failure_code' => null,
            'failure_reason' => null,
            'raw' => $decoded,
        ];
    }

    /**
     * Recover a possibly-completed transfer WITHOUT moving money twice: a
     * Stripe transfer whose CREATE reached Stripe but whose RESPONSE was
     * lost has no known $providerRef, so transferStatus() (provider-id-
     * based) cannot recover it. Replaying the identical create under the
     * same Idempotency-Key ($providerSafeRef) is the documented-safe
     * recovery mechanism -- Stripe de-dupes on that key and returns the
     * *original* transfer object rather than creating a second one, so
     * this is never a double-transfer.
     *
     * @return array<string,mixed>
     */
    public function recoverTransfer(
        PayoutDestination $destination,
        PayoutRequest $request,
        string $providerSafeRef,
        ?string $providerRef
    ): array {
        return $this->transfer($destination, $request, $providerSafeRef);
    }

    /**
     * Reconcile a transfer's current state. Stripe transfer retrieval is
     * provider-id-based (GET /v1/transfers/{id}), so $providerRef is
     * required here -- when it is not yet known (e.g. the create call's
     * outcome was itself lost), recovering the original response is
     * {@see recoverTransfer()}'s job (replaying the identical create
     * request under the same Idempotency-Key), not this method's.
     *
     * Returns `{status, reversed_amount, provider_ref, failure_code,
     * failure_reason, raw}` with `status` one of PayoutStatusResult's six
     * constants. Stripe's own `reversed` boolean distinguishes a full
     * reversal from a partial one (`amount_reversed` > 0 while `reversed`
     * stays false) -- see https://stripe.com/docs/api/transfers/object.
     *
     * @return array<string,mixed>
     */
    public function transferStatus(string $providerSafeRef, ?string $providerRef): array
    {
        if ($providerRef === null || $providerRef === '') {
            throw new \RuntimeException(
                'Stripe transfer status lookup requires a known provider reference (transfer id); '
                . 'Stripe transfer retrieval is provider-id-based, not idempotency-key-based.'
            );
        }

        $secret = $this->secretKey();
        if ($secret === '') {
            throw new \RuntimeException('Missing Stripe secret key (PAYVIA_STRIPE_SECRET_KEY)');
        }

        $response = $this->httpClient->get(
            $this->baseUrl() . '/v1/transfers/' . rawurlencode($providerRef),
            $this->requestOptions()
        );
        [$statusCode, $decoded] = $this->decodeJsonResponse($response, 'Stripe transfer status');

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $mapped = $this->classifyStripeTransferError($statusCode, $decoded['error'], $decoded);

            return [
                'status' => $mapped['status'],
                'reversed_amount' => 0,
                'provider_ref' => null,
                'failure_code' => $mapped['failure_code'],
                'failure_reason' => $mapped['failure_reason'],
                'raw' => $decoded,
            ];
        }

        $reversed = (bool) ($decoded['reversed'] ?? false);
        $amountReversed = (int) ($decoded['amount_reversed'] ?? 0);
        $settled = array_key_exists('balance_transaction', $decoded) && $decoded['balance_transaction'] !== null;

        $status = match (true) {
            $reversed => PayoutStatusResult::REVERSED,
            !$settled => PayoutStatusResult::PENDING,
            default => PayoutStatusResult::PAID,
        };

        return [
            'status' => $status,
            'reversed_amount' => $amountReversed,
            'provider_ref' => isset($decoded['id']) && is_scalar($decoded['id']) ? (string) $decoded['id'] : null,
            'failure_code' => null,
            'failure_reason' => null,
            'raw' => $decoded,
        ];
    }

    /**
     * Inspect a Stripe connected account's payout readiness.
     * `requirements.disabled_reason` is Stripe's own signal for a
     * definitively unusable account (e.g. rejected for fraud) -- that maps
     * to RESTRICTED. `payouts_enabled` false without a disabled_reason means
     * onboarding/verification is still in progress -- PENDING.
     *
     * @return array<string,mixed>
     */
    public function inspectAccount(string $accountRef): array
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw new \RuntimeException('Missing Stripe secret key (PAYVIA_STRIPE_SECRET_KEY)');
        }

        $response = $this->httpClient->get(
            $this->baseUrl() . '/v1/accounts/' . rawurlencode($accountRef),
            $this->requestOptions()
        );
        [, $decoded] = $this->decodeJsonResponse($response, 'Stripe account inspection');

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $error = $decoded['error'];
            $code = isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : 'account_error';

            return ['state' => DestinationStatus::RESTRICTED, 'failure_code' => $code];
        }

        $requirements = (array) ($decoded['requirements'] ?? []);
        $disabledReason = isset($requirements['disabled_reason']) && is_scalar($requirements['disabled_reason'])
            ? (string) $requirements['disabled_reason']
            : null;

        if ($disabledReason !== null && $disabledReason !== '') {
            return ['state' => DestinationStatus::RESTRICTED, 'failure_code' => $disabledReason];
        }

        $payoutsEnabled = (bool) ($decoded['payouts_enabled'] ?? false);

        return [
            'state' => $payoutsEnabled ? DestinationStatus::READY : DestinationStatus::PENDING,
            'failure_code' => null,
        ];
    }

    /**
     * Decode a Stripe JSON response without letting a non-2xx status throw
     * (so a structured error body can still be classified) while a genuine
     * server error still surfaces as a thrown exception rather than a
     * fabricated status.
     *
     * @return array{0:int,1:array<string,mixed>}
     */
    private function decodeJsonResponse(HttpResponse $response, string $context): array
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 500) {
            throw new HttpClientException("{$context} failed with server error {$statusCode}", $statusCode);
        }

        /** @var array<string,mixed> $decoded */
        $decoded = $response->getSymfonyResponse()->toArray(false);

        return [$statusCode, $decoded];
    }

    /**
     * @param array<string,mixed> $error
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function classifyStripeTransferError(int $statusCode, array $error, array $raw): array
    {
        $code = isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : null;
        $type = isset($error['type']) && is_scalar($error['type']) ? (string) $error['type'] : null;
        $message = isset($error['message']) && is_scalar($error['message'])
            ? (string) $error['message']
            : 'Stripe transfer request failed.';

        // Rate limiting/lock contention are documented-transient; everything
        // else with a structured Stripe error (bad destination, insufficient
        // funds that won't self-resolve, etc.) is a definite decline.
        $retryable = $statusCode === 429 || $type === 'rate_limit_error' || $code === 'lock_timeout';

        return [
            'status' => $retryable ? PayoutResult::RETRYABLE_FAILURE : PayoutResult::TERMINAL_FAILURE,
            'provider_ref' => null,
            'failure_code' => $code ?? $type ?? 'stripe_error',
            'failure_reason' => $message,
            'raw' => $raw,
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private function normalizePaymentIntentVerification(string $reference, array $decoded): array
    {
        $status = (string) ($decoded['status'] ?? 'failed');
        // Stripe's wire amount is already an integer minor unit (e.g. 5000 = USD
        // 50.00); pass it through untouched — never float-divide by 100.
        $amount = isset($decoded['amount_received'])
            ? (int) $decoded['amount_received']
            : (int) ($decoded['amount'] ?? 0);

        return [
            'status' => $status === 'succeeded' ? 'success' : ($status === '' ? 'failed' : $status),
            'id' => $decoded['id'] ?? null,
            'reference' => (string) ($decoded['id'] ?? $reference),
            'amount' => $amount,
            'currency' => strtoupper((string) ($decoded['currency'] ?? '')),
            'message' => (string) ($decoded['last_payment_error']['message'] ?? $status),
            'raw' => $decoded,
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private function normalizeCheckoutSessionVerification(string $reference, array $decoded): array
    {
        $paymentStatus = (string) ($decoded['payment_status'] ?? '');

        return [
            'status' => $paymentStatus === 'paid' ? 'success' : ($paymentStatus !== '' ? $paymentStatus : 'failed'),
            'id' => $decoded['payment_intent'] ?? $decoded['id'] ?? null,
            'reference' => (string) ($decoded['id'] ?? $reference),
            // Wire amount is already an integer minor unit; pass it through untouched.
            'amount' => (int) ($decoded['amount_total'] ?? 0),
            'currency' => strtoupper((string) ($decoded['currency'] ?? '')),
            'message' => $paymentStatus,
            'raw' => $decoded,
        ];
    }

    private function normalizeType(string $providerType, string $status): string
    {
        return match ($providerType) {
            'payment_intent.succeeded', 'checkout.session.completed' => EventType::PAYMENT_SUCCEEDED,
            'payment_intent.payment_failed' => EventType::PAYMENT_FAILED,
            // Workspace self-serve checkout (design spec §3.3): a ledger lifecycle event, not a
            // subscription projection event -- see EventType::CHECKOUT_EXPIRED's docblock.
            'checkout.session.expired' => EventType::CHECKOUT_EXPIRED,
            'invoice.paid' => EventType::INVOICE_PAID,
            'invoice.payment_failed' => EventType::INVOICE_PAYMENT_FAILED,
            'customer.subscription.created' => EventType::SUBSCRIPTION_CREATED,
            'customer.subscription.updated' => $status === 'past_due'
                ? EventType::SUBSCRIPTION_PAST_DUE
                : EventType::SUBSCRIPTION_UPDATED,
            'customer.subscription.deleted' => EventType::SUBSCRIPTION_CANCELED,
            // A dispute's own `status` becomes `won`/`lost`/`warning_closed` only once closed;
            // only `won` (funds actually returned) is recognized as a reversal here -- `lost`
            // and `warning_closed` leave the original chargeback standing, so no new logical
            // event is produced for them.
            'charge.dispute.created' => EventType::CHARGEBACK_CREATED,
            'charge.dispute.closed' => $status === 'won' ? EventType::CHARGEBACK_REVERSED : EventType::UNKNOWN,
            default => EventType::UNKNOWN,
        };
    }

    /**
     * @param array<string,mixed> $object
     * @return array<string,mixed>
     */
    private function normalizePayload(string $type, array $object): array
    {
        if (EventType::isChargeback($type)) {
            return $this->normalizeDisputePayload($object);
        }

        $currency = isset($object['currency']) ? strtoupper((string) $object['currency']) : null;
        $amount = $this->amount($object);

        return array_filter([
            'type' => $type,
            'reference' => $this->reference($object),
            'gateway_transaction_id' => $this->transactionId($object),
            'gateway_subscription_id' => $this->subscriptionId($object),
            'gateway_customer_id' => isset($object['customer']) && is_scalar($object['customer'])
                ? (string) $object['customer']
                : null,
            'gateway_price_id' => $this->priceId($object),
            'billing_plan_uuid' => $this->metadataString($object, 'billing_plan_uuid'),
            // Workspace self-serve checkout (design spec §3.4): promoted to a top-level key --
            // mirroring billing_plan_uuid above -- so GatewaySubscriptionService::
            // applyProviderEvent() has a single canonical field to read for origination-ledger
            // correlation, whether the object is a Stripe subscription (subscription_data.
            // metadata.origination_uuid) or a checkout session (top-level metadata.
            // origination_uuid, read by the checkout.session.expired lifecycle handler).
            'origination_uuid' => $this->metadataString($object, 'origination_uuid'),
            'amount' => $amount,
            // Forward-compat marker for any future consumer/re-normalizer; only set
            // when a numeric amount is actually present.
            'amount_unit' => $amount !== null ? 'minor' : null,
            'currency' => $currency,
            'status' => $object['status'] ?? $object['payment_status'] ?? null,
            'current_period_start' => $this->timestamp($object['current_period_start'] ?? null),
            'current_period_end' => $this->timestamp($object['current_period_end'] ?? null),
            'cancel_at_period_end' => $object['cancel_at_period_end'] ?? null,
            'canceled_at' => $this->timestamp($object['canceled_at'] ?? null),
            'metadata' => isset($object['metadata']) && is_array($object['metadata']) ? $object['metadata'] : null,
        ], static fn($value): bool => $value !== null);
    }

    /**
     * Normalize a Stripe Dispute object (`charge.dispute.created`/`.closed`). The disputed
     * charge/PaymentIntent -- not the dispute's own id -- is what `payments.gateway_transaction_id`
     * was stored as, so `transactionId()`'s existing payment_intent-then-charge priority is
     * reused as-is (it never falls through to the dispute's own `id` for a real dispute object,
     * since `charge` is always present).
     *
     * @param array<string,mixed> $object
     * @return array<string,mixed>
     */
    private function normalizeDisputePayload(array $object): array
    {
        $amount = $this->amount($object);
        $disputeId = isset($object['id']) && is_scalar($object['id']) ? (string) $object['id'] : null;
        $reason = isset($object['reason']) && is_scalar($object['reason']) ? (string) $object['reason'] : null;

        return array_filter([
            'gateway_transaction_id' => $this->transactionId($object),
            'dispute_provider_event_id' => $disputeId,
            'amount' => $amount,
            'amount_unit' => $amount !== null ? 'minor' : null,
            'currency' => isset($object['currency']) ? strtoupper((string) $object['currency']) : null,
            'reason_code' => $reason,
            'status' => isset($object['status']) && is_scalar($object['status']) ? (string) $object['status'] : null,
        ], static fn($value): bool => $value !== null);
    }

    /** @param array<string,mixed> $object */
    private function entityId(string $type, array $object): string
    {
        if (str_starts_with($type, 'subscription.')) {
            return (string) ($this->subscriptionId($object) ?? $object['id'] ?? 'unknown');
        }

        if (str_starts_with($type, 'invoice.')) {
            return (string) ($object['id'] ?? 'unknown');
        }

        if (EventType::isChargeback($type)) {
            // The dispute's own id is stable across its created -> closed lifecycle, so the
            // "created" and "closed/reversed" logical events for the SAME dispute derive from
            // the same entityId -- they stay distinct logical keys only because $type differs.
            return (string) ($object['id'] ?? 'unknown');
        }

        return (string) ($this->reference($object) ?? $object['id'] ?? 'unknown');
    }

    /** @param array<string,mixed> $object */
    private function reference(array $object): ?string
    {
        foreach (['id', 'payment_intent'] as $key) {
            if (isset($object[$key]) && is_scalar($object[$key])) {
                return (string) $object[$key];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $object */
    private function transactionId(array $object): ?string
    {
        foreach (['payment_intent', 'charge', 'id'] as $key) {
            if (isset($object[$key]) && is_scalar($object[$key])) {
                return (string) $object[$key];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $object */
    private function subscriptionId(array $object): ?string
    {
        if (($object['object'] ?? null) === 'subscription' && isset($object['id']) && is_scalar($object['id'])) {
            return (string) $object['id'];
        }

        if (isset($object['subscription']) && is_scalar($object['subscription'])) {
            return (string) $object['subscription'];
        }

        return null;
    }

    /** @param array<string,mixed> $object */
    private function priceId(array $object): ?string
    {
        $items = (array) ($object['items']['data'] ?? []);
        $first = (array) ($items[0] ?? []);
        $price = (array) ($first['price'] ?? []);

        return isset($price['id']) && is_scalar($price['id']) ? (string) $price['id'] : null;
    }

    /**
     * Wire amounts are already integer minor units; pass them through untouched
     * — never float-divide by 100.
     *
     * @param array<string,mixed> $object
     */
    private function amount(array $object): ?int
    {
        foreach (['amount_received', 'amount_paid', 'amount_due', 'amount_total', 'amount'] as $key) {
            if (isset($object[$key]) && is_numeric($object[$key])) {
                return (int) $object[$key];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $object */
    private function metadataString(array $object, string $key): ?string
    {
        $metadata = (array) ($object['metadata'] ?? []);
        return isset($metadata[$key]) && is_scalar($metadata[$key]) ? (string) $metadata[$key] : null;
    }

    private function occurredAt(array $payload): \DateTimeImmutable
    {
        if (isset($payload['created']) && is_numeric($payload['created'])) {
            return new \DateTimeImmutable('@' . (string) $payload['created']);
        }

        return new \DateTimeImmutable();
    }

    private function timestamp(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (new \DateTimeImmutable('@' . (string) $value))->format('Y-m-d H:i:s');
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        return PayviaSettings::gatewayConfig($this->context, 'stripe');
    }

    private function secretKey(): string
    {
        return (string) ($this->config()['secret_key'] ?? '');
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config()['base_url'] ?? 'https://api.stripe.com'), '/');
    }

    /** @return array<string,mixed> */
    private function requestOptions(): array
    {
        return [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secretKey(),
                'Accept' => 'application/json',
            ],
            'timeout' => (int) ($this->config()['timeout'] ?? 15),
        ];
    }

    /** @param array<string,mixed> $headers */
    private function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === strtolower($name)) {
                if (is_array($value)) {
                    return (string) ($value[0] ?? '');
                }
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * @return array<string,list<string>>
     */
    private function parseSignatureHeader(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, '');
            if ($key === '' || $value === '') {
                continue;
            }
            $parts[$key][] = $value;
        }

        return $parts;
    }
}
