<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

/**
 * The outcome of {@see SubscriptionCheckoutService::initializeClaim()} (design spec §3.2).
 *
 * `status` mirrors the origination ledger's status at the moment this result was produced:
 * `initializing` for a concurrent loser that performed zero provider I/O (another attempt
 * currently holds -- or just held -- the execution lease), `pending` once the provider hosted
 * checkout session has actually been created, `failed` once a definitive provider rejection was
 * observed, or whatever other status a caller happens to observe on a later, already-resolved
 * replay. `checkoutUrl` is non-null only once the provider has actually returned one (`pending`
 * and later live statuses); it is always null for `initializing`/`failed`.
 */
final class SubscriptionCheckoutResult
{
    public function __construct(
        public readonly string $originationUuid,
        public readonly ?string $checkoutUrl,
        public readonly string $status,
    ) {
    }
}
