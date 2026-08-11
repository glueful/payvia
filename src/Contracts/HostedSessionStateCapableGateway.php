<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * A driver that can answer, WITHOUT mutating anything, whether a hosted session it previously
 * created is still usable. This is the read half of ensure-live (payment-links spec §2.1 /
 * Ruling 5): `PayviaPaymentCollector::initiate()` probes an already-open intent through this
 * contract and hands back the SAME checkout url when the provider confirms the session live.
 *
 * The four-value enum is deliberately not a boolean:
 *
 *  - {@see STATE_LIVE}      the session is still awaiting payment -- reuse its url verbatim.
 *  - {@see STATE_COMPLETED} payment has already been made/settled at the provider. NOT a reason
 *                           to replace anything: settlement is the webhook's business, and
 *                           minting a second session here would invite a double charge.
 *  - {@see STATE_DEAD}      the provider says this session can no longer be paid. Note this is a
 *                           read, not proof -- a renewal still requires
 *                           {@see HostedSessionRenewalCapableGateway::abandonHostedSession()}.
 *  - {@see STATE_UNKNOWN}   the provider's answer was unparseable, contradictory, or absent. The
 *                           collector fails CLOSED on this; it never guesses.
 *
 * Implementations MUST NOT invent a state: an unreachable provider throws (the collector converts
 * that into its typed unknown-state failure) or returns {@see STATE_UNKNOWN} -- never
 * {@see STATE_DEAD}.
 */
interface HostedSessionStateCapableGateway
{
    public const STATE_LIVE = 'live';
    public const STATE_COMPLETED = 'completed';
    public const STATE_DEAD = 'dead';
    public const STATE_UNKNOWN = 'unknown';

    /** @return 'live'|'completed'|'dead'|'unknown' */
    public function hostedSessionState(string $reference): string;
}
