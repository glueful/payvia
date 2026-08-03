<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * Additive capability declaring which self-serve cancellation modes a gateway's {@see
 * SubscriptionCapableGateway::cancelSubscription()} actually honors. This is deliberately
 * separate from (and does not modify) `SubscriptionCapableGateway` itself -- existing
 * third-party subscription drivers that only implement that interface remain fully
 * source-compatible; they simply expose no self-serve cancellation modes to callers that
 * probe for this capability.
 */
interface SubscriptionCancellationModeProvider
{
    /** @return list<'stop_renewal'|'immediate'> */
    public function cancellationModes(): array;
}
