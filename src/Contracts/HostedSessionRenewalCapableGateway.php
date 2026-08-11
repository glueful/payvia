<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * A driver that can PROVE a hosted session permanently dead, which is the only thing that ever
 * licenses replacing it (payment-links spec §2.1 / Rulings 5 and 6).
 *
 * Implementing this contract is a strong claim: it means the provider offers a way to make a
 * session definitively unpayable and to observe that fact afterwards (Stripe: expire, then
 * re-fetch the session). A driver whose provider offers no such signal MUST NOT implement it --
 * Paystack deliberately does not, because a fresh initialization does not invalidate an existing
 * authorization url and a late second settlement would only be visible after a double charge.
 * Missing the interface is the honest answer, and the collector turns it into a typed
 * renewal-unavailable failure rather than guessing at timeouts.
 *
 *  - {@see RENEWAL_CONFIRMED_DEAD} the ONLY value that frees the old intent for supersession.
 *  - {@see RENEWAL_STILL_LIVE}     the session survived (or had already completed) -- keep it.
 *  - {@see RENEWAL_UNKNOWN}        indeterminate; the collector fails closed and keeps the intent.
 */
interface HostedSessionRenewalCapableGateway
{
    public const RENEWAL_CONFIRMED_DEAD = 'confirmed_dead';
    public const RENEWAL_STILL_LIVE = 'still_live';
    public const RENEWAL_UNKNOWN = 'unknown';

    /** @return 'confirmed_dead'|'still_live'|'unknown' */
    public function abandonHostedSession(string $reference): string;
}
