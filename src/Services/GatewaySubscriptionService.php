<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\GatewaySubscriptionRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\GatewayManager;

final class GatewaySubscriptionService
{
    public function __construct(
        private ApplicationContext $context,
        private GatewaySubscriptionRepositoryInterface $subscriptions,
        private GatewayManager $gateways,
    ) {
    }

    public function applyProviderEvent(PaymentProviderEventInterface $event): void
    {
        $normalized = $event->normalized();
        $gatewaySubscriptionId = $normalized['gateway_subscription_id'] ?? null;
        if (!is_scalar($gatewaySubscriptionId) || (string) $gatewaySubscriptionId === '') {
            return;
        }

        $this->subscriptions->upsertByGatewayId($this->rowFromNormalized(
            $event->gateway(),
            (string) $gatewaySubscriptionId,
            $normalized,
            $event->raw()
        ));
    }

    /** @return array<string,mixed>|null */
    public function reconcile(string $gateway, string $gatewaySubscriptionId): ?array
    {
        $driver = $this->gateways->subscriptionGateway($gateway);
        $raw = $driver->fetchSubscription($gatewaySubscriptionId);
        $normalized = $this->normalizeProviderSubscription($raw);
        $uuid = $this->subscriptions->upsertByGatewayId($this->rowFromNormalized(
            $gateway,
            $gatewaySubscriptionId,
            $normalized,
            $raw
        ));

        return $this->subscriptions->findByGatewaySubscription($gateway, $gatewaySubscriptionId)
            ?? ['uuid' => $uuid];
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function rowFromNormalized(
        string $gateway,
        string $gatewaySubscriptionId,
        array $normalized,
        array $raw,
    ): array {
        return [
            'gateway' => $gateway,
            'gateway_subscription_id' => $gatewaySubscriptionId,
            'gateway_customer_id' => $this->stringOrNull($normalized['gateway_customer_id'] ?? null),
            'gateway_price_id' => $this->stringOrNull($normalized['gateway_price_id'] ?? null),
            'status' => (string) ($normalized['status'] ?? 'active'),
            'current_period_start' => $this->stringOrNull($normalized['current_period_start'] ?? null),
            'current_period_end' => $this->stringOrNull($normalized['current_period_end'] ?? null),
            'cancel_at_period_end' => (bool) ($normalized['cancel_at_period_end'] ?? false),
            'canceled_at' => $this->stringOrNull($normalized['canceled_at'] ?? null),
            'raw_payload' => config($this->context, 'payvia.features.store_raw_payload', true) ? $raw : null,
        ];
    }

    /** @param array<string,mixed> $raw */
    private function normalizeProviderSubscription(array $raw): array
    {
        $data = (array) ($raw['data'] ?? $raw);
        $customer = (array) ($data['customer'] ?? []);
        $plan = (array) ($data['plan'] ?? []);

        return [
            'gateway_subscription_id' => $data['subscription_code'] ?? $data['id'] ?? null,
            'gateway_customer_id' => $customer['customer_code'] ?? $data['customer_code'] ?? null,
            'gateway_price_id' => $plan['plan_code'] ?? $data['plan_code'] ?? null,
            'status' => $data['status'] ?? 'active',
            'current_period_end' => $data['next_payment_date'] ?? null,
            'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
            'canceled_at' => $data['canceled_at'] ?? null,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
