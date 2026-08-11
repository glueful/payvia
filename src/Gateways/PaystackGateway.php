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
use Glueful\Extensions\Payvia\Contracts\HostedSessionStateCapableGateway;
use Glueful\Extensions\Payvia\Contracts\InitiationCapableGateway;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCancellationModeProvider;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
use Glueful\Extensions\Payvia\Contracts\TransferCapableGateway;
use Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\Support\HostedCheckoutUrl;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Http\Client as HttpClient;
use Glueful\Http\Exceptions\HttpClientException;
use Glueful\Http\Response\Response as HttpResponse;

/**
 * Deliberately does NOT implement {@see \Glueful\Extensions\Payvia\Contracts\
 * SubscriptionInitiationCapableGateway}. The 2026-08-04 sandbox proof
 * (`tests/Fixtures/paystack-checkout/README.md`) established that Paystack exposes neither
 * approved §3.1 correlation mode: `subscription.create` carries no transaction metadata and
 * `charge.success` carries no subscription identifier. `GatewayManager::supports('paystack',
 * 'subscription_checkout')` is therefore `false` and `SubscriptionCheckoutService::prepare()`
 * targeting this gateway fails closed via `CheckoutUnavailableException` before any write — see
 * `tests/Unit/Gateways/PaystackSubscriptionCheckoutUnavailableTest.php`. Existing webhook
 * projection and operator-created subscription support (below) are unaffected.
 */
final class PaystackGateway implements
    PaymentGatewayInterface,
    InitiationCapableGateway,
    HostedSessionStateCapableGateway,
    WebhookCapableGateway,
    SubscriptionCapableGateway,
    SubscriptionCancellationModeProvider,
    TransferCapableGateway
{
    /**
     * The driver's own shipped checkout-host allow-list, used when `gateways.paystack
     * .checkout_hosts` is absent or malformed. See {@see HostedCheckoutUrl::configuredHosts()}.
     *
     * @var list<string>
     */
    private const CHECKOUT_HOSTS = ['checkout.paystack.com'];

    /**
     * Paystack transaction states that mean "this reference has NOT reached a payment outcome
     * yet" -- the transaction exists and its authorization url still leads somewhere payable, so
     * ensure-live reuses it verbatim. `abandoned` is Paystack's own word for "initialized, never
     * completed", which is exactly the state a payment link sits in between being sent and being
     * paid.
     *
     * @var list<string>
     */
    private const LIVE_TRANSACTION_STATES = ['abandoned', 'ongoing', 'pending', 'processing', 'queued'];

    /** @var list<string> */
    private const DEAD_TRANSACTION_STATES = ['failed', 'reversed'];

    private ApplicationContext $context;
    public function __construct(
        private HttpClient $httpClient,
        ApplicationContext $context,
    ) {
        $this->context = $context;
    }

    public function verify(string $reference, array $options = []): array
    {
        $config = $this->gatewayConfig();

        $secret = (string) ($config['secret_key'] ?? '');
        if ($secret === '') {
            return [
                'status' => 'failed',
                'reference' => $reference,
                'message' => 'Missing Paystack secret key (PAYVIA_PAYSTACK_SECRET_KEY / PAYSTACK_SECRET_KEY)',
            ];
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
        $timeout = (int) ($config['timeout'] ?? 15);

        // The verify URL is always derived from trusted config (base_url). Caller-supplied
        // options must never influence this URL, otherwise an authenticated user could point
        // verification at a server they control (SSRF + leaked secret key + forged success).
        $verifyUrl = $baseUrl . '/transaction/verify/' . rawurlencode($reference);

        try {
            $response = $this->httpClient->get($verifyUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret,
                    'Accept' => 'application/json',
                ],
                'timeout' => $timeout,
            ]);

            $httpCode = $response->getStatusCode();
            /** @var array<string,mixed> $decoded */
            $decoded = $response->toArray();
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'reference' => $reference,
                'message' => 'Paystack verification request failed: ' . $e->getMessage(),
            ];
        }

        $apiStatus = (bool) ($decoded['status'] ?? false);
        $data = (array) ($decoded['data'] ?? []);

        // Prefer gateway_response from transaction data when available
        $rawMessage = (string) ($decoded['message'] ?? '');
        $gatewayResponse = isset($data['gateway_response']) ? (string) $data['gateway_response'] : '';
        $message = $gatewayResponse !== '' ? $gatewayResponse : $rawMessage;

        if (!$apiStatus || $httpCode < 200 || $httpCode >= 300) {
            return [
                'status' => 'failed',
                'reference' => (string) ($data['reference'] ?? $reference),
                'message' => $message !== '' ? $message : 'Paystack verification returned error',
                'raw' => $decoded,
            ];
        }

        // Paystack's wire amount is already an integer minor unit (e.g. 5000 = GHS
        // 50.00); pass it through untouched — never float-divide by 100.
        $amount = isset($data['amount']) ? (int) $data['amount'] : 0;
        $currency = (string) ($data['currency'] ?? 'GHS');

        return [
            'status' => (string) ($data['status'] ?? 'success'),
            'id' => $data['id'] ?? null,
            'reference' => (string) ($data['reference'] ?? $reference),
            'amount' => $amount,
            'currency' => $currency,
            'message' => $message,
            'raw' => $decoded,
        ];
    }

    /**
     * Start a hosted Paystack transaction for a payable.
     *
     * `attempt_uuid` is REQUIRED (payment-links Task 2): Paystack's `reference` IS its idempotency
     * mechanism -- it rejects a duplicate reference rather than charging twice -- so the reference
     * is DERIVED from the caller's claimed attempt instead of being minted fresh per call. A
     * transport timeout can then be retried on the same attempt and re-initialize the very same
     * transaction; a random per-call reference would instead leave a second payable transaction
     * behind every time. There is deliberately no fallback and no caller-supplied `reference`
     * option: a caller with no attempt identity has no idempotency story, and is refused before
     * any provider I/O.
     *
     * `authorization_url` -- returned unchecked before 2.6.0 -- must now pass the provider-host
     * trust boundary before it can leave this method as a `checkout_url`.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function initialize(PayableReference $payable, array $options = []): array
    {
        $config = $this->gatewayConfig();

        $secret = (string) ($config['secret_key'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException(
                'Missing Paystack secret key (PAYVIA_PAYSTACK_SECRET_KEY / PAYSTACK_SECRET_KEY)'
            );
        }

        $attemptUuid = isset($options['attempt_uuid']) && is_string($options['attempt_uuid'])
            ? trim($options['attempt_uuid'])
            : '';
        if ($attemptUuid === '') {
            throw new \RuntimeException(
                'Paystack initialization requires an attempt_uuid in the initiation options; the '
                . 'transaction reference is derived from it.'
            );
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
        $timeout = (int) ($config['timeout'] ?? 15);
        $reference = $this->attemptReference($payable, $attemptUuid);

        $json = [
            'amount' => $payable->amount,
            'currency' => strtoupper($payable->currency),
            'reference' => $reference,
            'metadata' => array_merge($payable->metadata, [
                'payable_type' => $payable->type,
                'payable_id' => $payable->id,
            ]),
        ];

        if ($payable->description !== null && $payable->description !== '') {
            $json['metadata']['description'] = $payable->description;
        }

        foreach (['email', 'callback_url', 'channels'] as $key) {
            if (array_key_exists($key, $options)) {
                $json[$key] = $options[$key];
            }
        }

        $response = $this->httpClient->post($baseUrl . '/transaction/initialize', [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $json,
            'timeout' => $timeout,
        ]);

        $decoded = $response->toArray();
        $data = (array) ($decoded['data'] ?? []);

        return [
            'reference' => (string) ($data['reference'] ?? $reference),
            'checkout_url' => $this->trustedCheckoutUrl($data['authorization_url'] ?? null),
            'raw' => $decoded,
        ];
    }

    /**
     * Read-only liveness for a hosted transaction (payment-links Task 2). Paystack has no
     * "session" object: the transaction reference IS the session, and `GET /transaction/verify`
     * is the only honest read of it.
     *
     * Note what this deliberately does NOT do: guess. A reference Paystack cannot find, an API
     * envelope that says `status: false`, or a transaction state outside the documented set all
     * come back UNKNOWN rather than DEAD -- and because this driver cannot prove a session dead
     * at all (no renewal contract, Ruling 6), UNKNOWN and DEAD both end in the collector refusing
     * to replace anything.
     *
     * @return 'live'|'completed'|'dead'|'unknown'
     */
    public function hostedSessionState(string $reference): string
    {
        if (trim($reference) === '') {
            return self::STATE_UNKNOWN;
        }

        $config = $this->gatewayConfig();
        $secret = (string) ($config['secret_key'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException(
                'Missing Paystack secret key (PAYVIA_PAYSTACK_SECRET_KEY / PAYSTACK_SECRET_KEY)'
            );
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
        // Derived from trusted config only -- never from caller input (see verify()).
        $response = $this->httpClient->get($baseUrl . '/transaction/verify/' . rawurlencode($reference), [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Accept' => 'application/json',
            ],
            'timeout' => (int) ($config['timeout'] ?? 15),
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 500) {
            throw new HttpClientException(
                "Paystack transaction status failed with server error {$statusCode}",
                $statusCode
            );
        }

        /** @var array<string,mixed> $decoded */
        $decoded = $response->getSymfonyResponse()->toArray(false);
        if (($decoded['status'] ?? false) !== true) {
            return self::STATE_UNKNOWN;
        }

        $data = (array) ($decoded['data'] ?? []);
        $state = isset($data['status']) && is_scalar($data['status']) ? strtolower((string) $data['status']) : '';

        return match (true) {
            $state === 'success' => self::STATE_COMPLETED,
            in_array($state, self::LIVE_TRANSACTION_STATES, true) => self::STATE_LIVE,
            in_array($state, self::DEAD_TRANSACTION_STATES, true) => self::STATE_DEAD,
            default => self::STATE_UNKNOWN,
        };
    }

    /**
     * The hosted-redirect trust boundary for this driver: an absolute HTTPS url whose host
     * exactly matches the configured `checkout_hosts` set (default `checkout.paystack.com`).
     * Before 2.6.0 `authorization_url` was passed straight through, so whatever the response
     * carried became the customer's redirect. See {@see HostedCheckoutUrl}.
     */
    private function trustedCheckoutUrl(mixed $url): string
    {
        $trusted = HostedCheckoutUrl::trusted(
            $url,
            HostedCheckoutUrl::configuredHosts($this->gatewayConfig(), self::CHECKOUT_HOSTS),
        );

        if ($trusted === null) {
            throw new \RuntimeException(
                'Paystack initialization returned no usable HTTPS checkout URL on a trusted '
                . 'Paystack checkout host'
            );
        }

        return $trusted;
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        $signature = $this->header($headers, 'x-paystack-signature');
        if ($signature === '') {
            return false;
        }

        $config = $this->gatewayConfig();
        $secret = (string) ($config['webhook_secret'] ?? $config['secret_key'] ?? '');
        if ($secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }

    public function parseWebhookEvent(string $rawBody, array $headers): PaymentProviderEventInterface
    {
        /** @var array<string,mixed> $payload */
        $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        $providerType = (string) ($payload['event'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $type = $this->normalizeType(
            $providerType,
            (string) ($data['status'] ?? ''),
            (string) ($data['resolution'] ?? '')
        );
        $entityId = $this->entityId($type, $data);
        $normalized = $this->normalizePayload($type, $data);
        $discriminator = $data['updated_at'] ?? $data['paid_at'] ?? $data['created_at'] ?? null;

        return ProviderEvent::create(
            gateway: 'paystack',
            type: $type,
            providerEventId: null,
            deliveryKey: hash('sha256', $rawBody),
            entityId: $entityId,
            occurredAt: $this->occurredAt($data),
            normalized: $normalized,
            raw: $payload,
            discriminator: is_scalar($discriminator) ? (string) $discriminator : null,
        );
    }

    public function fetchSubscription(string $gatewaySubscriptionId): array
    {
        $config = $this->gatewayConfig();
        $secret = (string) ($config['secret_key'] ?? '');
        if ($secret === '') {
            return ['status' => 'failed', 'message' => 'Missing Paystack secret key'];
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
        $timeout = (int) ($config['timeout'] ?? 15);
        $response = $this->httpClient->get($baseUrl . '/subscription/' . rawurlencode($gatewaySubscriptionId), [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Accept' => 'application/json',
            ],
            'timeout' => $timeout,
        ]);

        return $response->toArray();
    }

    public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): array
    {
        $config = $this->gatewayConfig();
        $secret = (string) ($config['secret_key'] ?? '');
        if ($secret === '') {
            return ['status' => 'failed', 'message' => 'Missing Paystack secret key'];
        }

        $subscription = $this->fetchSubscription($gatewaySubscriptionId);
        $subscriptionData = (array) ($subscription['data'] ?? $subscription);
        $token = $this->stringOrNull(
            $subscriptionData['email_token']
                ?? $subscriptionData['token']
                ?? $subscriptionData['emailToken']
                ?? null
        );
        if ($token === null) {
            return [
                'status' => 'failed',
                'message' => 'Paystack subscription cancellation requires an email token',
                'raw' => $subscription,
            ];
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
        $timeout = (int) ($config['timeout'] ?? 15);
        $response = $this->httpClient->post($baseUrl . '/subscription/disable', [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'code' => $gatewaySubscriptionId,
                'token' => $token,
            ],
            'timeout' => $timeout,
        ]);

        return $response->toArray();
    }

    /**
     * Paystack's subscription-disable API (behind the existing `cancelSubscription()`, driven
     * by its `atPeriodEnd` flag) only ever stops future renewal -- there is no Paystack API to
     * immediately revoke an in-progress billing period, so `immediate` is deliberately absent.
     *
     * @return list<'stop_renewal'|'immediate'>
     */
    public function cancellationModes(): array
    {
        return ['stop_renewal'];
    }

    /**
     * Initiate a Paystack transfer to $destination->accountRef (a
     * transfer-recipient code). $providerSafeRef is sent as the Paystack
     * `reference` -- the caller has already derived it to obey Paystack's
     * lowercase [a-z0-9_-]{16,50} constraint (see
     * {@see \Glueful\Extensions\Payvia\Support\ProviderSafeReference::forPaystack()});
     * the colon-delimited canonical key is never sent here.
     *
     * Returns `{status, provider_ref, failure_code, failure_reason, raw}`
     * with `status` one of PayoutResult's five constants. Network/timeout/
     * 5xx/unparseable responses throw instead of fabricating a status.
     *
     * @return array<string,mixed>
     */
    public function transfer(PayoutDestination $destination, PayoutRequest $request, string $providerSafeRef): array
    {
        $config = $this->gatewayConfig();
        $secret = (string) ($config['secret_key'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException(
                'Missing Paystack secret key (PAYVIA_PAYSTACK_SECRET_KEY / PAYSTACK_SECRET_KEY)'
            );
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
        $timeout = (int) ($config['timeout'] ?? 15);

        $json = array_filter([
            'source' => 'balance',
            'amount' => $request->amount,
            'recipient' => $destination->accountRef,
            'reference' => $providerSafeRef,
            'reason' => $request->reason,
        ], static fn(mixed $value): bool => $value !== null);

        $response = $this->httpClient->post($baseUrl . '/transfer', [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $json,
            'timeout' => $timeout,
        ]);

        [$statusCode, $decoded] = $this->decodeJsonResponse($response, 'Paystack transfer');

        return $this->classifyPaystackTransfer($statusCode, $decoded);
    }

    /**
     * Recover a possibly-completed transfer WITHOUT moving money twice:
     * Paystack rejects a duplicate `reference` on transfer(), so recovery
     * verifies the persisted provider-safe reference ($providerSafeRef) via
     * {@see transferStatus()} instead of replaying the create. Down-maps
     * transferStatus()'s six-state PayoutStatusResult vocabulary onto this
     * method's five-state PayoutResult shape: a settled or reversed verify
     * both classify as PAID (a later status() call reports the reversal);
     * pending/otp stays PENDING; failures map straight across.
     *
     * @return array<string,mixed>
     */
    public function recoverTransfer(
        PayoutDestination $destination,
        PayoutRequest $request,
        string $providerSafeRef,
        ?string $providerRef
    ): array {
        $result = $this->transferStatus($providerSafeRef, $providerRef);

        $status = match ($result['status']) {
            PayoutStatusResult::PAID, PayoutStatusResult::REVERSED => PayoutResult::PAID,
            PayoutStatusResult::RETRYABLE_FAILURE => PayoutResult::RETRYABLE_FAILURE,
            PayoutStatusResult::TERMINAL_FAILURE => PayoutResult::TERMINAL_FAILURE,
            default => PayoutResult::PENDING,
        };

        return [
            'status' => $status,
            'provider_ref' => $result['provider_ref'],
            'failure_code' => $result['failure_code'],
            'failure_reason' => $result['failure_reason'],
            'raw' => $result['raw'],
        ];
    }

    /**
     * Reconcile a transfer's current state via Paystack's verify-by-reference
     * endpoint, using $providerSafeRef (the reference Paystack was given at
     * initiate time) -- Paystack verifies by reference, not by its internal
     * transfer id, so $providerRef is not needed here.
     *
     * Returns `{status, reversed_amount, provider_ref, failure_code,
     * failure_reason, raw}` with `status` one of PayoutStatusResult's six
     * constants. Paystack's own `status` enum includes `reversed` directly;
     * Paystack does not document a partial-reversal concept the way Stripe
     * does, so a reversal is treated as full (reversed_amount = amount)
     * unless the response itself supplies a distinct reversed figure.
     *
     * @return array<string,mixed>
     */
    public function transferStatus(string $providerSafeRef, ?string $providerRef): array
    {
        $config = $this->gatewayConfig();
        $secret = (string) ($config['secret_key'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException(
                'Missing Paystack secret key (PAYVIA_PAYSTACK_SECRET_KEY / PAYSTACK_SECRET_KEY)'
            );
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
        $timeout = (int) ($config['timeout'] ?? 15);

        $response = $this->httpClient->get(
            $baseUrl . '/transfer/verify/' . rawurlencode($providerSafeRef),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret,
                    'Accept' => 'application/json',
                ],
                'timeout' => $timeout,
            ]
        );

        [$statusCode, $decoded] = $this->decodeJsonResponse($response, 'Paystack transfer status');

        return $this->classifyPaystackTransferStatus($statusCode, $decoded);
    }

    /**
     * Inspect a Paystack transfer recipient's readiness. Paystack recipients
     * have no multi-stage onboarding/approval flow the way Stripe connected
     * accounts do: `active` is the provider's own usability signal, so
     * `true` maps to READY and `false` maps to RESTRICTED. A recipient that
     * cannot be found at all is also RESTRICTED for this accountRef (it will
     * never become usable without creating a new recipient).
     *
     * @return array<string,mixed>
     */
    public function inspectAccount(string $accountRef): array
    {
        $config = $this->gatewayConfig();
        $secret = (string) ($config['secret_key'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException(
                'Missing Paystack secret key (PAYVIA_PAYSTACK_SECRET_KEY / PAYSTACK_SECRET_KEY)'
            );
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
        $timeout = (int) ($config['timeout'] ?? 15);

        $response = $this->httpClient->get(
            $baseUrl . '/transferrecipient/' . rawurlencode($accountRef),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret,
                    'Accept' => 'application/json',
                ],
                'timeout' => $timeout,
            ]
        );

        [, $decoded] = $this->decodeJsonResponse($response, 'Paystack recipient inspection');

        $apiStatus = (bool) ($decoded['status'] ?? false);
        if (!$apiStatus) {
            return ['state' => DestinationStatus::RESTRICTED, 'failure_code' => 'recipient_not_found'];
        }

        $data = (array) ($decoded['data'] ?? []);
        if (!array_key_exists('active', $data)) {
            return ['state' => DestinationStatus::PENDING, 'failure_code' => null];
        }

        $active = (bool) $data['active'];

        return [
            'state' => $active ? DestinationStatus::READY : DestinationStatus::RESTRICTED,
            'failure_code' => $active ? null : 'recipient_inactive',
        ];
    }

    /**
     * Decode a Paystack JSON response without letting a non-2xx status throw
     * (so a structured `{status:false,message}` error body can still be
     * classified) while a genuine server error still surfaces as a thrown
     * exception rather than a fabricated status.
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

    /** @param array<string,mixed> $decoded @return array<string,mixed> */
    private function classifyPaystackTransfer(int $statusCode, array $decoded): array
    {
        $apiStatus = (bool) ($decoded['status'] ?? false);
        $message = (string) ($decoded['message'] ?? '');

        if (!$apiStatus) {
            return [
                'status' => $this->isPaystackRetryable($statusCode, $message)
                    ? PayoutResult::RETRYABLE_FAILURE
                    : PayoutResult::TERMINAL_FAILURE,
                'provider_ref' => null,
                'failure_code' => $this->paystackFailureCode((array) ($decoded['data'] ?? []), $message),
                'failure_reason' => $message !== '' ? $message : 'Paystack transfer request failed.',
                'raw' => $decoded,
            ];
        }

        $data = (array) ($decoded['data'] ?? []);
        $providerStatus = (string) ($data['status'] ?? '');
        $providerRef = $this->paystackProviderRef($data);

        return match ($providerStatus) {
            'otp' => [
                'status' => PayoutResult::PENDING,
                'provider_ref' => $providerRef,
                'failure_code' => 'action_required',
                'failure_reason' => $message !== '' ? $message : 'Transfer requires OTP confirmation.',
                'raw' => $decoded,
            ],
            'pending' => [
                'status' => PayoutResult::PENDING,
                'provider_ref' => $providerRef,
                'failure_code' => null,
                'failure_reason' => null,
                'raw' => $decoded,
            ],
            // Paystack can report status=success at initiate time while the
            // transfer is still queued (transferred_at null); only a
            // populated transferred_at means it actually settled.
            'success' => [
                'status' => ($data['transferred_at'] ?? null) !== null ? PayoutResult::PAID : PayoutResult::PENDING,
                'provider_ref' => $providerRef,
                'failure_code' => null,
                'failure_reason' => null,
                'raw' => $decoded,
            ],
            'failed' => [
                'status' => PayoutResult::TERMINAL_FAILURE,
                'provider_ref' => $providerRef,
                'failure_code' => 'transfer_failed',
                'failure_reason' => $message !== '' ? $message : 'Paystack transfer failed.',
                'raw' => $decoded,
            ],
            // An unrecognized provider-side status must never be fabricated
            // into a terminal/paid outcome -- stay pending so the hold is
            // preserved until a reconcile call clarifies it.
            default => [
                'status' => PayoutResult::PENDING,
                'provider_ref' => $providerRef,
                'failure_code' => null,
                'failure_reason' => null,
                'raw' => $decoded,
            ],
        };
    }

    /** @param array<string,mixed> $decoded @return array<string,mixed> */
    private function classifyPaystackTransferStatus(int $statusCode, array $decoded): array
    {
        $apiStatus = (bool) ($decoded['status'] ?? false);
        $message = (string) ($decoded['message'] ?? '');

        if (!$apiStatus) {
            return [
                'status' => $this->isPaystackRetryable($statusCode, $message)
                    ? PayoutStatusResult::RETRYABLE_FAILURE
                    : PayoutStatusResult::TERMINAL_FAILURE,
                'reversed_amount' => 0,
                'provider_ref' => null,
                'failure_code' => $this->paystackFailureCode((array) ($decoded['data'] ?? []), $message),
                'failure_reason' => $message !== '' ? $message : 'Paystack transfer verification failed.',
                'raw' => $decoded,
            ];
        }

        $data = (array) ($decoded['data'] ?? []);
        $providerStatus = (string) ($data['status'] ?? '');
        $providerRef = $this->paystackProviderRef($data);
        $amount = isset($data['amount']) && is_numeric($data['amount']) ? (int) $data['amount'] : 0;
        $reversedAmount = isset($data['amount_reversed']) && is_numeric($data['amount_reversed'])
            ? (int) $data['amount_reversed']
            : 0;

        return match ($providerStatus) {
            'reversed' => [
                'status' => PayoutStatusResult::REVERSED,
                'reversed_amount' => $reversedAmount > 0 ? $reversedAmount : $amount,
                'provider_ref' => $providerRef,
                'failure_code' => null,
                'failure_reason' => null,
                'raw' => $decoded,
            ],
            'success' => [
                'status' => ($data['transferred_at'] ?? null) !== null
                    ? PayoutStatusResult::PAID
                    : PayoutStatusResult::PENDING,
                'reversed_amount' => $reversedAmount,
                'provider_ref' => $providerRef,
                'failure_code' => null,
                'failure_reason' => null,
                'raw' => $decoded,
            ],
            'otp' => [
                'status' => PayoutStatusResult::PENDING,
                'reversed_amount' => 0,
                'provider_ref' => $providerRef,
                'failure_code' => 'action_required',
                'failure_reason' => $message !== '' ? $message : 'Transfer requires OTP confirmation.',
                'raw' => $decoded,
            ],
            'pending' => [
                'status' => PayoutStatusResult::PENDING,
                'reversed_amount' => 0,
                'provider_ref' => $providerRef,
                'failure_code' => null,
                'failure_reason' => null,
                'raw' => $decoded,
            ],
            'failed' => [
                'status' => PayoutStatusResult::TERMINAL_FAILURE,
                'reversed_amount' => 0,
                'provider_ref' => $providerRef,
                'failure_code' => 'transfer_failed',
                'failure_reason' => $message !== '' ? $message : 'Paystack transfer failed.',
                'raw' => $decoded,
            ],
            default => [
                'status' => PayoutStatusResult::PENDING,
                'reversed_amount' => $reversedAmount,
                'provider_ref' => $providerRef,
                'failure_code' => null,
                'failure_reason' => null,
                'raw' => $decoded,
            ],
        };
    }

    private function isPaystackRetryable(int $statusCode, string $message): bool
    {
        if ($statusCode === 429) {
            return true;
        }

        return str_contains(strtolower($message), 'too many requests');
    }

    /** @param array<string,mixed> $data */
    private function paystackFailureCode(array $data, string $message): string
    {
        if (isset($data['code']) && is_scalar($data['code'])) {
            return (string) $data['code'];
        }

        $normalized = strtolower($message);
        if (str_contains($normalized, 'balance') && str_contains($normalized, 'not enough')) {
            return 'insufficient_balance';
        }

        if (str_contains($normalized, 'not found')) {
            return 'recipient_not_found';
        }

        return 'paystack_error';
    }

    /** @param array<string,mixed> $data */
    private function paystackProviderRef(array $data): ?string
    {
        if (isset($data['transfer_code']) && is_scalar($data['transfer_code'])) {
            return (string) $data['transfer_code'];
        }

        if (isset($data['id']) && is_scalar($data['id'])) {
            return (string) $data['id'];
        }

        return null;
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
     * `$resolution` is only meaningful for `charge.dispute.resolve` -- Paystack's Dispute
     * Resolution API defines it as `{merchant-accepted|declined}`: `merchant-accepted` means
     * the merchant accepted liability (the chargeback already dispatched at `.create` time
     * stands, funds stay withdrawn -- no new logical event), while `declined` means the
     * merchant contested and the dispute resolved in the merchant's favor (funds returned --
     * a reversal). `charge.dispute.remind` (the periodic reminder) is deliberately left
     * unrecognized (UNKNOWN) -- it carries no state change.
     */
    private function normalizeType(string $providerType, string $status, string $resolution = ''): string
    {
        return match ($providerType) {
            'charge.success' => $status === 'failed' ? EventType::PAYMENT_FAILED : EventType::PAYMENT_SUCCEEDED,
            'invoice.payment_failed' => EventType::INVOICE_PAYMENT_FAILED,
            'invoice.update', 'invoice.create' => $status === 'paid'
                ? EventType::INVOICE_PAID
                : EventType::UNKNOWN,
            'subscription.create' => EventType::SUBSCRIPTION_CREATED,
            'subscription.disable', 'subscription.not_renew' => EventType::SUBSCRIPTION_CANCELED,
            'subscription.expiring_cards' => EventType::SUBSCRIPTION_UPDATED,
            'charge.dispute.create' => EventType::CHARGEBACK_CREATED,
            'charge.dispute.resolve' => $resolution === 'declined'
                ? EventType::CHARGEBACK_REVERSED
                : EventType::UNKNOWN,
            default => EventType::UNKNOWN,
        };
    }

    /**
     * The per-attempt transaction reference: `{payable type}_{payable id}_{attempt uuid}`, kept
     * human-legible for the merchant's Paystack dashboard while being deterministic per attempt.
     *
     * The payable segments are truncated because `payment_intents.reference` is `varchar(100)`
     * and `payable_id` is `varchar(255)`: an over-long reference would only fail AFTER the
     * transaction had been created at Paystack, which is the worst possible moment. The attempt
     * uuid is globally unique on its own, so truncation can never make two payables collide.
     */
    private function attemptReference(PayableReference $payable, string $attemptUuid): string
    {
        $safeType = substr((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $payable->type), 0, 40);
        $safeId = substr((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $payable->id), 0, 40);

        return trim($safeType . '_' . $safeId, '_') . '_' . $attemptUuid;
    }

    /** @param array<string,mixed> $data */
    private function entityId(string $type, array $data): string
    {
        if (str_starts_with($type, 'subscription.')) {
            return (string) ($data['subscription_code'] ?? $data['id'] ?? 'unknown');
        }

        if (str_starts_with($type, 'invoice.')) {
            return (string) ($data['invoice_code'] ?? $data['id'] ?? $data['reference'] ?? 'unknown');
        }

        if (EventType::isChargeback($type)) {
            // The dispute's own id is stable across its create -> resolve lifecycle, so the
            // "created" and "reversed" logical events for the SAME dispute derive from the
            // same entityId -- they stay distinct logical keys only because $type differs.
            return (string) ($data['id'] ?? 'unknown');
        }

        return (string) ($data['reference'] ?? $data['id'] ?? 'unknown');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizePayload(string $type, array $data): array
    {
        if (EventType::isChargeback($type)) {
            return $this->normalizeDisputePayload($data);
        }

        // Paystack's wire amount is already an integer minor unit; pass it through
        // untouched — never float-divide by 100.
        $amount = isset($data['amount']) ? (int) $data['amount'] : null;
        $customer = (array) ($data['customer'] ?? []);

        return array_filter([
            'type' => $type,
            'reference' => $data['reference'] ?? null,
            'gateway_transaction_id' => isset($data['id']) ? (string) $data['id'] : null,
            'gateway_subscription_id' => $data['subscription_code']
                ?? $data['subscription']['subscription_code']
                ?? null,
            'gateway_customer_id' => $customer['customer_code'] ?? $data['customer_code'] ?? null,
            'billing_plan_uuid' => $data['metadata']['billing_plan_uuid'] ?? null,
            'amount' => $amount,
            // Forward-compat marker for any future consumer/re-normalizer; only set
            // when a numeric amount is actually present.
            'amount_unit' => $amount !== null ? 'minor' : null,
            'currency' => $data['currency'] ?? null,
            'status' => $data['status'] ?? null,
            'current_period_end' => $data['next_payment_date'] ?? null,
            'cancel_at_period_end' => null,
            'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
        ], static fn($value): bool => $value !== null);
    }

    /**
     * Normalize a Paystack Dispute resource (`charge.dispute.create`/`.resolve`). The
     * disputed transaction's own id -- not the dispute's own id -- is what
     * `payments.gateway_transaction_id` was stored as (see the `charge.success` normalization
     * above, which reads it from the same `transaction.id`-shaped field), so it is read from
     * the nested `transaction` object rather than the dispute's own top-level `id`.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizeDisputePayload(array $data): array
    {
        $transaction = (array) ($data['transaction'] ?? []);
        $amount = isset($data['refund_amount']) && is_numeric($data['refund_amount'])
            ? (int) $data['refund_amount']
            : (isset($transaction['amount']) && is_numeric($transaction['amount'])
                ? (int) $transaction['amount']
                : null);
        $disputeId = isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : null;
        $gatewayTransactionId = isset($transaction['id']) && is_scalar($transaction['id'])
            ? (string) $transaction['id']
            : null;
        $currency = isset($data['currency']) && is_scalar($data['currency'])
            ? (string) $data['currency']
            : (isset($transaction['currency']) && is_scalar($transaction['currency'])
                ? (string) $transaction['currency']
                : null);

        return array_filter([
            'gateway_transaction_id' => $gatewayTransactionId,
            'dispute_provider_event_id' => $disputeId,
            'amount' => $amount,
            'amount_unit' => $amount !== null ? 'minor' : null,
            'currency' => $currency,
            'reason_code' => isset($data['category']) && is_scalar($data['category'])
                ? (string) $data['category']
                : null,
            'status' => isset($data['status']) && is_scalar($data['status']) ? (string) $data['status'] : null,
            'resolution' => isset($data['resolution']) && is_scalar($data['resolution'])
                ? (string) $data['resolution']
                : null,
        ], static fn($value): bool => $value !== null);
    }

    /** @param array<string,mixed> $data */
    private function occurredAt(array $data): \DateTimeImmutable
    {
        foreach (['paid_at', 'updated_at', 'created_at'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                return new \DateTimeImmutable((string) $data[$key]);
            }
        }

        return new \DateTimeImmutable();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** @return array<string,mixed> Effective config — host settings overlays applied. */
    private function gatewayConfig(): array
    {
        return PayviaSettings::gatewayConfig($this->context, 'paystack');
    }
}
