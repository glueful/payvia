<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * Additive capability: move a live subscription to another plan in place. A gateway that can
 * (Stripe) implements it; one that cannot switch a live plan (Paystack) does not, and callers
 * probe for it. The local subscription follows the provider's own subscription-updated event,
 * never this call's answer.
 */
interface SubscriptionPlanChangeCapableGateway
{
    /**
     * @param string $providerPlanIdentifier the new plan's price or plan id at this provider
     * @return array{status: 'changed'}|array{status: 'failed', message: string}
     */
    public function changeSubscriptionPlan(string $gatewaySubscriptionId, string $providerPlanIdentifier): array;
}
