<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

/**
 * Thrown by {@see SubscriptionCheckoutService::prepare()} when a genuinely NEW checkout
 * origination attempt cannot claim its subject's live guard because a DIFFERENT origination
 * already holds it (design spec §3.3: `subscription_checkout_subject_guards.state = 'live'`).
 * The whole owning transaction rolls back when this is thrown -- the freshly inserted
 * `preparing` origination row this call attempted never persists.
 */
final class OriginationLiveException extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely.
     */
    public const MARKER = 'origination_live';

    public static function subjectAlreadyLive(string $subjectKey): self
    {
        return new self(sprintf(
            '%s: subject "%s" already has a live checkout origination.',
            self::MARKER,
            $subjectKey
        ));
    }
}
