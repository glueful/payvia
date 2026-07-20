<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Support;

/**
 * Derives provider-safe transfer references from Commerce's canonical
 * per-attempt idempotency key (e.g. "{payoutUuid}:attempt:{n}").
 *
 * The colon-delimited canonical key is never sent to a provider directly --
 * some providers constrain reference characters/length. Derivation is
 * deterministic: the same canonical key always yields the same provider-safe
 * reference, which is what makes replaying an attempt safely idempotent at
 * the provider. Callers (the payout collector) derive the reference once and
 * pass it into the gateway's transfer()/transferStatus() as `$providerSafeRef`
 * -- gateways never re-derive it themselves.
 */
final class ProviderSafeReference
{
    /**
     * Stripe's Idempotency-Key header accepts an arbitrary string (colons
     * included) up to 255 characters, so the canonical key is used as-is.
     */
    public static function forStripe(string $canonicalKey): string
    {
        return $canonicalKey;
    }

    /**
     * Paystack transfer references must be lowercase, 16-50 characters, and
     * contain only [a-z0-9_-]. The colon-delimited canonical key violates
     * this directly, so it is deterministically hashed into a compliant,
     * fixed-length ("py_" + 32 lowercase hex chars = 35 chars) reference.
     */
    public static function forPaystack(string $canonicalKey): string
    {
        return 'py_' . substr(hash('sha256', $canonicalKey), 0, 32);
    }
}
