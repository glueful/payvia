<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Gateways;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
use Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Http\Client as HttpClient;

final class PaystackGateway implements PaymentGatewayInterface, WebhookCapableGateway, SubscriptionCapableGateway
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
        $config = (array) config($this->context, 'payvia.gateways.paystack', []);

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

        $amount = isset($data['amount']) ? ((float) $data['amount'] / 100.0) : 0.0;
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

    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        $signature = $this->header($headers, 'x-paystack-signature');
        if ($signature === '') {
            return false;
        }

        $config = (array) config($this->context, 'payvia.gateways.paystack', []);
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
        $type = $this->normalizeType($providerType, (string) ($data['status'] ?? ''));
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
        $config = (array) config($this->context, 'payvia.gateways.paystack', []);
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
        $config = (array) config($this->context, 'payvia.gateways.paystack', []);
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

    private function normalizeType(string $providerType, string $status): string
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
            default => EventType::UNKNOWN,
        };
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

        return (string) ($data['reference'] ?? $data['id'] ?? 'unknown');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizePayload(string $type, array $data): array
    {
        $amount = isset($data['amount']) ? ((float) $data['amount'] / 100.0) : null;
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
            'currency' => $data['currency'] ?? null,
            'status' => $data['status'] ?? null,
            'current_period_end' => $data['next_payment_date'] ?? null,
            'cancel_at_period_end' => null,
            'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
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
}
