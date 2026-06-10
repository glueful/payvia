<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\GatewaySubscriptionRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Events\EventType;
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
        if (!$this->isSubscriptionEvent($event->type())) {
            return;
        }

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
        $row = [
            'gateway' => $gateway,
            'gateway_subscription_id' => $gatewaySubscriptionId,
        ];

        if (array_key_exists('status', $normalized)) {
            $row['status'] = $this->normalizeStatus($this->stringOrNull($normalized['status']));
        }

        foreach (
            [
            'gateway_customer_id',
            'gateway_price_id',
            'billing_plan_uuid',
            'current_period_start',
            'current_period_end',
            'canceled_at',
            ] as $key
        ) {
            $value = $this->stringOrNull($normalized[$key] ?? null);
            if ($value !== null) {
                $row[$key] = $value;
            }
        }

        if (array_key_exists('cancel_at_period_end', $normalized)) {
            $row['cancel_at_period_end'] = (bool) $normalized['cancel_at_period_end'];
        }

        if (isset($normalized['metadata']) && is_array($normalized['metadata'])) {
            $row['metadata'] = $normalized['metadata'];
        }

        if (config($this->context, 'payvia.features.store_raw_payload', true)) {
            $row['raw_payload'] = $raw;
        }

        return $row;
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
            'billing_plan_uuid' => $data['billing_plan_uuid'] ?? null,
            'status' => $data['status'] ?? 'active',
            'current_period_end' => $data['next_payment_date'] ?? null,
            'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
            'canceled_at' => $data['canceled_at'] ?? null,
            'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
        ];
    }

    private function isSubscriptionEvent(string $type): bool
    {
        return in_array($type, [
            EventType::SUBSCRIPTION_CREATED,
            EventType::SUBSCRIPTION_UPDATED,
            EventType::SUBSCRIPTION_PAST_DUE,
            EventType::SUBSCRIPTION_CANCELED,
        ], true);
    }

    private function normalizeStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'past_due', 'attention', 'payment_failed' => 'past_due',
            'canceled', 'cancelled', 'disabled', 'not_renew', 'not_renewing', 'non-renewing' => 'canceled',
            'incomplete', 'pending' => 'incomplete',
            default => 'active',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
