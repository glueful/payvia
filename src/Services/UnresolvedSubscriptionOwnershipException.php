<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

/**
 * Thrown by `GatewaySubscriptionService::applyProviderEvent()` when the tenant owner of a
 * subscription projection cannot be resolved under the global (gateway,
 * gateway_subscription_id) identity: there is no existing projection on file, and either no
 * `billing_plan_uuid` correlates to a known `billing_plans` row, or an explicit metadata
 * `tenant_uuid` hint disagrees with that plan's own owner.
 *
 * This is a fail-closed rejection: no sentinel `gateway_subscriptions` row is ever written for
 * the triggering event. `WebhookService`'s generic `\Throwable` handling in `processStored()`
 * already marks the owning `provider_events` row failed/retryable and rethrows -- this
 * exception rides that existing mechanism rather than requiring a special case there.
 */
final class UnresolvedSubscriptionOwnershipException extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely from `provider_events.error`, rather than guessing
     * at "unresolved ownership" from event type + status alone.
     */
    public const MARKER = 'unresolved_subscription_ownership';

    public static function noPlanCorrelation(
        string $gateway,
        string $gatewaySubscriptionId,
        ?string $planUuid,
    ): self {
        $detail = $planUuid !== null
            ? sprintf('billing_plan_uuid "%s" does not match any billing plan', $planUuid)
            : 'no billing_plan_uuid was present in the normalized provider metadata';

        return new self(sprintf(
            '%s: no existing projection for %s subscription %s and %s',
            self::MARKER,
            $gateway,
            $gatewaySubscriptionId,
            $detail
        ));
    }

    public static function metadataTenantMismatch(
        string $gateway,
        string $gatewaySubscriptionId,
        string $metadataTenantUuid,
        string $planOwnerTenantUuid,
    ): self {
        return new self(sprintf(
            '%s: metadata tenant_uuid "%s" disagrees with billing plan owner "%s" for %s subscription %s',
            self::MARKER,
            $metadataTenantUuid,
            $planOwnerTenantUuid,
            $gateway,
            $gatewaySubscriptionId
        ));
    }
}
