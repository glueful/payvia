<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

/**
 * The immutable outcome of {@see SubscriptionCheckoutService::prepare()} (design spec §3.2).
 *
 * `originationUuid` is the permanent correlation identity -- either the row this call itself
 * just claimed (a genuinely new attempt), or the pre-existing row a same-idempotency-key replay
 * resolved to. `status` mirrors the origination ledger's own status column at the moment this
 * claim was produced: `initializing` for a freshly prepared claim (the caller must now call
 * {@see SubscriptionCheckoutService::initializeClaim()} to actually reach the provider), or
 * whatever status a replayed row had already reached (including a terminal one). `checkoutUrl`
 * is only ever non-null for a replay of a row that already completed initialization.
 *
 * `replayed` distinguishes a same-key replay (`true`) from a genuinely new claim this call
 * itself just created (`false`) -- callers use it to decide whether the local reservation they
 * would otherwise bind was actually (re)used or skipped entirely.
 */
final class SubscriptionCheckoutClaim
{
    public function __construct(
        public readonly string $originationUuid,
        public readonly string $status,
        public readonly ?string $checkoutUrl,
        public readonly bool $replayed,
    ) {
    }
}
