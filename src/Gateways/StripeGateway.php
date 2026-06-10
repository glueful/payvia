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

final class StripeGateway implements PaymentGatewayInterface, WebhookCapableGateway, SubscriptionCapableGateway
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
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private function normalizePaymentIntentVerification(string $reference, array $decoded): array
    {
        $status = (string) ($decoded['status'] ?? 'failed');
        $amount = isset($decoded['amount_received'])
            ? ((float) $decoded['amount_received'] / 100.0)
            : ((float) ($decoded['amount'] ?? 0) / 100.0);

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
            'amount' => ((float) ($decoded['amount_total'] ?? 0) / 100.0),
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
            'invoice.paid' => EventType::INVOICE_PAID,
            'invoice.payment_failed' => EventType::INVOICE_PAYMENT_FAILED,
            'customer.subscription.created' => EventType::SUBSCRIPTION_CREATED,
            'customer.subscription.updated' => $status === 'past_due'
                ? EventType::SUBSCRIPTION_PAST_DUE
                : EventType::SUBSCRIPTION_UPDATED,
            'customer.subscription.deleted' => EventType::SUBSCRIPTION_CANCELED,
            default => EventType::UNKNOWN,
        };
    }

    /**
     * @param array<string,mixed> $object
     * @return array<string,mixed>
     */
    private function normalizePayload(string $type, array $object): array
    {
        $currency = isset($object['currency']) ? strtoupper((string) $object['currency']) : null;

        return array_filter([
            'type' => $type,
            'reference' => $this->reference($object),
            'gateway_transaction_id' => $this->transactionId($object),
            'gateway_subscription_id' => $this->subscriptionId($object),
            'gateway_customer_id' => isset($object['customer']) && is_scalar($object['customer'])
                ? (string) $object['customer']
                : null,
            'gateway_price_id' => $this->priceId($object),
            'amount' => $this->amount($object),
            'currency' => $currency,
            'status' => $object['status'] ?? $object['payment_status'] ?? null,
            'current_period_start' => $this->timestamp($object['current_period_start'] ?? null),
            'current_period_end' => $this->timestamp($object['current_period_end'] ?? null),
            'cancel_at_period_end' => $object['cancel_at_period_end'] ?? null,
            'canceled_at' => $this->timestamp($object['canceled_at'] ?? null),
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

    /** @param array<string,mixed> $object */
    private function amount(array $object): ?float
    {
        foreach (['amount_received', 'amount_paid', 'amount_due', 'amount_total', 'amount'] as $key) {
            if (isset($object[$key]) && is_numeric($object[$key])) {
                return ((float) $object[$key]) / 100.0;
            }
        }

        return null;
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
        return (array) config($this->context, 'payvia.gateways.stripe', []);
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
