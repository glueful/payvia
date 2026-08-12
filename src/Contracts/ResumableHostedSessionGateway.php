<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

use Glueful\Extensions\Contracts\Payments\PayableReference;

/**
 * A driver whose session-creation call is NOT safely repeatable, and which therefore needs to be
 * asked what happened before an interrupted attempt is retried (payment-links Task 2, fix round 1).
 *
 * Stripe does not implement this: its Idempotency-Key makes re-POSTing an identical create
 * request the documented recovery — Stripe replays the ORIGINAL session rather than making a
 * second one. Paystack is the opposite case: `reference` is a permanent per-integration
 * uniqueness constraint, not an idempotency key, so re-POSTing `/transaction/initialize` with a
 * reference that already exists returns HTTP 400 "Duplicate Transaction Reference" — forever.
 * Blindly retrying a claimed attempt there would wedge the payable permanently.
 *
 * The collector calls this ONLY when {@see \Glueful\Extensions\Payvia\Repositories
 * \PaymentIntentRepository::claimAttempt()} recovered an attempt that a previous call had already
 * claimed (i.e. a create was, or may have been, sent under this attempt's derived reference and
 * its outcome was lost). A brand-new attempt never goes through here.
 *
 * Outcomes:
 *  - {@see RESUME_ABSENT}  nothing exists provider-side; creating under the SAME attempt (and
 *                          therefore the same derived reference) is safe.
 *  - {@see RESUME_ADOPT}   a session/transaction already exists under this attempt and must NOT
 *                          be created again; `session` carries what the driver could recover of
 *                          it (`reference`, and `checkout_url` only when genuinely recoverable).
 *  - {@see RESUME_REPLACE} something exists but this attempt can never yield a usable checkout;
 *                          the attempt must be failed and a fresh one claimed (new identity ⇒ new
 *                          provider reference). Drivers may only return this when they know no
 *                          checkout URL for the old attempt was ever exposed to a payer.
 *
 * An indeterminate answer (transport failure, unparseable body) MUST throw — the collector turns
 * that into its typed fail-closed unknown-state error and leaves the attempt exactly as it was,
 * retryable later.
 */
interface ResumableHostedSessionGateway
{
    public const RESUME_ABSENT = 'absent';
    public const RESUME_ADOPT = 'adopt';
    public const RESUME_REPLACE = 'replace';

    /**
     * @param array<string,mixed> $options the same options {@see InitiationCapableGateway::initialize()}
     *                                     would receive, including the claimed `attempt_uuid`
     * @return array{outcome: 'absent'|'adopt'|'replace', session?: array<string,mixed>}
     */
    public function resumeHostedSession(PayableReference $payable, array $options): array;
}
