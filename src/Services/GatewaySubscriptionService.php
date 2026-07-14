<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;

final class GatewaySubscriptionService
{
    public function __construct(
        private ApplicationContext $context,
        private ProviderCorrelationRepository $subscriptions,
        private GatewayManager $gateways,
    ) {
    }

    /**
     * Resolve subscription ownership before persisting, in the binding order the spec
     * requires under the global (gateway, gateway_subscription_id) identity:
     *
     * 1. Existing projection: its persisted `tenant_uuid` is authoritative. The update is
     *    always owner-qualified (see `ProviderCorrelationRepository::upsertGatewaySubscription`),
     *    so a conflicting metadata tenant hint can never move it -- it is diagnosed and ignored.
     * 2. First projection: the tenant is derived from the globally unique `billing_plan_uuid`
     *    carried in normalized provider metadata, re-read via the correlation surface. An
     *    explicit metadata `tenant_uuid` hint must equal that plan's owner or the event is
     *    rejected.
     * 3. No existing projection and no valid local plan correlation: fail closed. No sentinel
     *    row is ever written; `UnresolvedSubscriptionOwnershipException` propagates so
     *    `WebhookService` records the provider event failed/retryable.
     */
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

        $gateway = $event->gateway();
        $gatewaySubscriptionId = (string) $gatewaySubscriptionId;
        $hint = $this->metadataTenantHint($normalized);
        $row = $this->rowFromNormalized($gateway, $gatewaySubscriptionId, $normalized, $event->raw());

        $existing = $this->subscriptions->findGatewaySubscriptionByGatewayId($gateway, $gatewaySubscriptionId);
        if ($existing !== null) {
            $owner = (string) ($existing['tenant_uuid'] ?? '');
            if ($hint !== null && $hint !== $owner) {
                $this->diagnoseOwnershipHintMismatch($gateway, $gatewaySubscriptionId, $owner, $hint);
            }

            // Owner-qualified update: any tenant_uuid this payload carries is discarded by the
            // repository, so ownership cannot move here regardless of the hint above.
            $this->subscriptions->upsertGatewaySubscription($row);
            return;
        }

        $planUuid = $this->stringOrNull($normalized['billing_plan_uuid'] ?? null);
        $plan = $planUuid !== null ? $this->subscriptions->findBillingPlanByUuid($planUuid) : null;
        if ($plan === null) {
            throw UnresolvedSubscriptionOwnershipException::noPlanCorrelation(
                $gateway,
                $gatewaySubscriptionId,
                $planUuid
            );
        }

        $owner = (string) ($plan['tenant_uuid'] ?? '');
        if ($hint !== null && $hint !== $owner) {
            throw UnresolvedSubscriptionOwnershipException::metadataTenantMismatch(
                $gateway,
                $gatewaySubscriptionId,
                $hint,
                $owner
            );
        }

        $row['tenant_uuid'] = $owner;
        $this->subscriptions->upsertGatewaySubscription($row);
    }

    /** @return array<string,mixed>|null */
    public function reconcile(string $gateway, string $gatewaySubscriptionId): ?array
    {
        $driver = $this->gateways->subscriptionGateway($gateway);
        $raw = $driver->fetchSubscription($gatewaySubscriptionId);
        $normalized = $this->normalizeProviderSubscription($gateway, $raw);
        $uuid = $this->subscriptions->upsertGatewaySubscription($this->rowFromNormalized(
            $gateway,
            $gatewaySubscriptionId,
            $normalized,
            $raw
        ));

        return $this->subscriptions->findGatewaySubscriptionByGatewayId($gateway, $gatewaySubscriptionId)
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

    /**
     * Normalize a provider's raw subscription fetch into the shape consumed by
     * rowFromNormalized(). Different gateways return very different shapes (e.g.
     * Stripe returns the raw subscription object with unix-timestamp period
     * fields, whereas Paystack wraps data under 'data' with a date-string
     * next_payment_date), so normalization is gateway-aware.
     *
     * Unknown gateways fall back to the generic (Paystack-shaped) normalizer so
     * third-party subscription drivers continue to work.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeProviderSubscription(string $gateway, array $raw): array
    {
        return match ($gateway) {
            'stripe' => $this->normalizeStripeSubscription($raw),
            default => $this->normalizeGenericSubscription($raw),
        };
    }

    /**
     * Generic / Paystack-shaped normalization. Behaves exactly as the historical
     * single-track normalizer.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeGenericSubscription(array $raw): array
    {
        $data = (array) ($raw['data'] ?? $raw);
        $customer = (array) ($data['customer'] ?? []);
        $plan = (array) ($data['plan'] ?? []);

        return [
            'gateway_subscription_id' => $data['subscription_code'] ?? $data['id'] ?? null,
            'gateway_customer_id' => $customer['customer_code'] ?? $data['customer_code'] ?? null,
            'gateway_price_id' => $plan['plan_code'] ?? $data['plan_code'] ?? null,
            'billing_plan_uuid' => $data['billing_plan_uuid'] ?? null,
            // Do not fabricate 'active' when the provider omits a status; an
            // absent status normalizes to 'unknown' (fail closed) downstream.
            'status' => $data['status'] ?? null,
            'current_period_end' => $data['next_payment_date'] ?? null,
            'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
            'canceled_at' => $data['canceled_at'] ?? null,
            'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
        ];
    }

    /**
     * Stripe-shaped normalization. Stripe's subscription fetch returns the raw
     * subscription object (no 'data' wrapper): the customer is a scalar id, the
     * price lives at items.data[0].price.id, and the period/cancellation fields
     * are unix timestamps that must be converted to 'Y-m-d H:i:s' before they
     * reach the DATETIME columns. Status is passed through raw — normalizeStatus
     * handles the mapping (and fails closed when absent).
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeStripeSubscription(array $raw): array
    {
        $price = (array) (((array) ($raw['items']['data'] ?? []))[0]['price'] ?? []);
        $metadata = isset($raw['metadata']) && is_array($raw['metadata']) ? $raw['metadata'] : null;
        $billingPlanUuid = $metadata !== null && isset($metadata['billing_plan_uuid'])
            && is_scalar($metadata['billing_plan_uuid'])
            ? (string) $metadata['billing_plan_uuid']
            : null;

        return [
            'gateway_subscription_id' => $raw['id'] ?? null,
            'gateway_customer_id' => isset($raw['customer']) && is_scalar($raw['customer'])
                ? (string) $raw['customer']
                : null,
            'gateway_price_id' => isset($price['id']) && is_scalar($price['id']) ? (string) $price['id'] : null,
            'billing_plan_uuid' => $billingPlanUuid,
            // Pass status through raw; normalizeStatus maps it and fails closed
            // when absent (never fabricating 'active').
            'status' => $raw['status'] ?? null,
            'current_period_start' => $this->unixToDateTime($raw['current_period_start'] ?? null),
            'current_period_end' => $this->unixToDateTime($raw['current_period_end'] ?? null),
            'canceled_at' => $this->unixToDateTime($raw['canceled_at'] ?? null),
            'cancel_at_period_end' => (bool) ($raw['cancel_at_period_end'] ?? false),
            'metadata' => $metadata,
        ];
    }

    private function unixToDateTime(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (new \DateTimeImmutable('@' . (string) $value))->format('Y-m-d H:i:s');
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
        // Fail closed: only explicitly known active-ish statuses become 'active'.
        // Any unrecognized, future, or empty status maps to 'unknown' so that a
        // delinquent/paused/expired provider subscription is never treated as live.
        return match (strtolower((string) $status)) {
            'active', 'trialing' => 'active',
            'past_due', 'attention', 'payment_failed', 'unpaid' => 'past_due',
            'canceled', 'cancelled', 'disabled', 'not_renew', 'not_renewing',
            'non-renewing', 'incomplete_expired' => 'canceled',
            'incomplete', 'pending' => 'incomplete',
            'paused' => 'paused',
            default => 'unknown',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * A metadata `tenant_uuid` hint is never an ownership authority by itself -- it is only
     * ever compared against an already-resolved owner (the existing projection's own tenant,
     * or the correlated billing plan's owner) and rejected on disagreement.
     *
     * @param array<string,mixed> $normalized
     */
    private function metadataTenantHint(array $normalized): ?string
    {
        $metadata = $normalized['metadata'] ?? null;
        if (!is_array($metadata)) {
            return null;
        }

        return $this->stringOrNull($metadata['tenant_uuid'] ?? null);
    }

    /**
     * Rule 1 never obeys a conflicting ownership hint, but the attempt is still worth
     * surfacing to operators -- log it (best-effort PSR-3, falling back to error_log the same
     * way Payvia's controllers already do) rather than silently dropping it.
     */
    private function diagnoseOwnershipHintMismatch(
        string $gateway,
        string $gatewaySubscriptionId,
        string $owner,
        string $hint,
    ): void {
        $message = sprintf(
            '[Payvia] subscription ownership hint mismatch ignored: %s subscription %s is owned by '
                . 'tenant "%s" but the provider metadata carried a conflicting tenant_uuid hint "%s"; the '
                . 'existing owner is authoritative and the hint was never obeyed.',
            $gateway,
            $gatewaySubscriptionId,
            $owner,
            $hint
        );

        try {
            app($this->context, \Psr\Log\LoggerInterface::class)->warning($message, [
                'gateway' => $gateway,
                'gateway_subscription_id' => $gatewaySubscriptionId,
                'owner_tenant_uuid' => $owner,
                'hint_tenant_uuid' => $hint,
            ]);
        } catch (\Throwable) {
            error_log($message);
        }
    }
}
