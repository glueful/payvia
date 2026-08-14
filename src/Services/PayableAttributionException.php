<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

/**
 * Thrown by {@see PaymentService::confirmAndRecord()} when the caller-supplied payable
 * disagrees with the payable the reference's OWN `payment_intents` row is bound to.
 *
 * Without this refusal, an authenticated caller could confirm a reference they legitimately
 * paid against a DIFFERENT pending payable: downstream confirmation handlers refuse a
 * mismatched amount/currency, but two equal-amount, equal-currency payables are
 * indistinguishable to that guard -- the intent row is the only authority that knows which
 * payable the reference was actually originated for.
 *
 * Deliberately non-revealing: the message names neither the bound payable nor the fact that a
 * row exists in another tenant. The lookup is tenant-scoped, so another tenant's reference is
 * simply invisible and takes the unbound (no intent row) path instead of ever producing this
 * refusal -- a confirmation would otherwise be a cross-tenant existence oracle.
 */
final class PayableAttributionException extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely, mirroring
     * {@see \Glueful\Extensions\Payvia\Exceptions\UnresolvedPaymentOwnershipException::MARKER}
     * and {@see \Glueful\Extensions\Payvia\Checkout\IdempotencyConflictException::MARKER}.
     */
    public const MARKER = 'payable_mismatch';

    public static function forReference(string $gateway, string $reference): self
    {
        return new self(sprintf(
            '%s: %s reference "%s" is bound to a different payable than the one supplied.',
            self::MARKER,
            $gateway,
            $reference
        ));
    }
}
