<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

/**
 * The ONLY exception a gateway driver may throw from `initializeSubscription()` for a
 * validated, definitive provider rejection -- i.e. a parsed, well-formed provider error body
 * (a genuine `{error: {message: "..."}}` shape from Stripe, for example) whose outcome is
 * KNOWN: the provider refused to create the checkout session and nothing was left pending.
 *
 * Every other failure mode -- transport failures, timeouts, malformed/unparseable response
 * bodies (including a present-but-empty/unusable `error` object), and any unexpected
 * exception -- is an UNKNOWN outcome and must propagate as a plain exception instead. Widening
 * this boundary (e.g. throwing this type for a malformed body) would let a caller wrongly
 * treat an indeterminate attempt as definitively dead and free a guard/reservation that a
 * provider might still complete.
 */
final class DefinitiveSubscriptionCheckoutRejection extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely.
     */
    public const MARKER = 'definitive_subscription_checkout_rejection';

    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(
        string $message,
        public readonly string $gateway,
        public readonly ?string $providerCode = null,
        public readonly array $raw = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string,mixed> $error
     * @param array<string,mixed> $raw
     */
    public static function forStripeError(array $error, array $raw): self
    {
        $code = isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : null;
        $message = (string) $error['message'];

        return new self(
            sprintf('%s: %s', self::MARKER, $message),
            gateway: 'stripe',
            providerCode: $code,
            raw: $raw,
        );
    }
}
