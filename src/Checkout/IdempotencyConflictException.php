<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

/**
 * Thrown by {@see SubscriptionCheckoutService::prepare()} when a caller reuses an
 * `idempotencyKey` already bound to a stored origination whose request fingerprint (design
 * spec §3.2: SHA-256 over the canonical JSON of subjectKey, gateway, providerPlanIdentifier,
 * sorted consumerMetadata, customerEmail, returnUrl, cancelUrl, requiredProjectionConsumer)
 * disagrees with the current request. A matching fingerprint is a legitimate replay and returns
 * the stored claim instead; only a genuine mismatch -- the same key reused for a materially
 * different request -- reaches this exception.
 */
final class IdempotencyConflictException extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely.
     */
    public const MARKER = 'idempotency_conflict';

    public static function fingerprintMismatch(string $idempotencyKey): self
    {
        return new self(sprintf(
            '%s: idempotency key "%s" was reused with a different request payload.',
            self::MARKER,
            $idempotencyKey
        ));
    }
}
