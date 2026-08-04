<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * Additive capability: reconcile a subscription-checkout attempt that {@see
 * SubscriptionInitiationCapableGateway::initializeSubscription()} already created, WITHOUT
 * waiting for a webhook. A driver without this capability supports neither on-demand status
 * lookups nor operator-triggered abandonment -- callers must treat both as `'unknown'` /
 * `'unsupported'` rather than fabricating an outcome.
 */
interface SubscriptionCheckoutLifecycleCapableGateway
{
    /** @return 'pending'|'completed'|'expired'|'canceled'|'unknown' */
    public function subscriptionCheckoutStatus(string $reference): string;

    /**
     * Attempt to definitively kill a checkout attempt that is presumed abandoned. Only
     * `'confirmed_dead'` may ever free the matching subject guard -- `'still_live'` means the
     * attempt actually completed or is still open, `'unsupported'` means this gateway cannot
     * abandon a checkout at all, and `'unknown'` means the outcome could not be established.
     *
     * @return 'confirmed_dead'|'still_live'|'unsupported'|'unknown'
     */
    public function abandonSubscriptionCheckout(string $reference): string;
}
