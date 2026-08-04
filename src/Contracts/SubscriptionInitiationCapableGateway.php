<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutRequest;

/**
 * Additive capability: start a provider-hosted checkout for a NEW subscription (as opposed to
 * {@see SubscriptionCapableGateway}, which manages an already-existing gateway subscription).
 * A driver implements this ONLY when it can actually initiate hosted subscription checkout --
 * callers (the checkout service) must never fall back to one-time {@see InitiationCapableGateway}
 * when a gateway lacks it.
 */
interface SubscriptionInitiationCapableGateway
{
    /** @return array{reference:string, checkout_url:string, expires_at:?string, raw:array} */
    public function initializeSubscription(SubscriptionCheckoutRequest $request): array;
}
