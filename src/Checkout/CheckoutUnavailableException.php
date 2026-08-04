<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

/**
 * Thrown by {@see SubscriptionCheckoutService::prepare()} BEFORE any write is ever attempted:
 * the requested gateway does not implement subscription checkout at all, or the request is
 * missing a plan identifier a provider could ever act on. Also thrown by
 * {@see SubscriptionCheckoutService::initializeClaim()} when the referenced origination simply
 * does not exist.
 *
 * Distinct from {@see IdempotencyConflictException} (a same-key replay with a mismatched
 * request shape) and {@see OriginationLiveException} (a live subject guard held by another
 * origination): this exception means the request could never have succeeded regardless of any
 * concurrent state.
 */
final class CheckoutUnavailableException extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely.
     */
    public const MARKER = 'checkout_unavailable';

    public static function gatewayDoesNotSupportSubscriptionCheckout(string $gateway): self
    {
        return new self(sprintf(
            '%s: gateway "%s" does not support subscription checkout.',
            self::MARKER,
            $gateway
        ));
    }

    public static function missingProviderPlanIdentifier(): self
    {
        return new self(sprintf(
            '%s: providerPlanIdentifier must not be empty.',
            self::MARKER
        ));
    }

    public static function unknownOrigination(string $originationUuid): self
    {
        return new self(sprintf(
            '%s: no checkout origination found for uuid "%s".',
            self::MARKER,
            $originationUuid
        ));
    }
}
