<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

/**
 * The existing hosted session is no longer usable, but this driver cannot prove a session
 * permanently dead, so Payvia will not mint a replacement (Ruling 6 -- Paystack in 2.6.0).
 *
 * A new initialization does NOT invalidate an outstanding authorization url: issuing one would
 * leave two payable checkouts alive, and the second settlement would only surface after a double
 * charge. No timeout guessing is permitted. The documented recoveries are offline completion
 * (mark paid) or an explicit, risk-acknowledged cancellation -- never a silent re-initiation.
 */
final class SessionRenewalUnavailableException extends EnsureLiveSessionException
{
    public static function for(
        string $gateway,
        string $payableType,
        string $payableId,
        string $reference,
    ): self {
        return new self(
            $gateway,
            $payableType,
            $payableId,
            $reference,
            sprintf(
                "The existing '%s' session for %s:%s (reference %s) is no longer usable and this "
                . 'gateway cannot prove a session dead, so Payvia will not replace it.',
                $gateway,
                $payableType,
                $payableId,
                $reference !== '' ? $reference : 'unknown',
            ),
        );
    }
}
