<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

use Glueful\Extensions\Contracts\Payments\PayableReference;

interface InitiationCapableGateway
{
    /**
     * Start a hosted payment flow for a payable.
     *
     * Well-known option keys, all supplied by {@see \Glueful\Extensions\Payvia\Services
     * \PayviaPaymentCollector}: `email`, `callback_url`, `cancel_url` (lifted verbatim from the
     * payable's metadata), plus `attempt_uuid`.
     *
     * `attempt_uuid` identifies the payment-intent ATTEMPT the collector claimed before calling
     * this method, and the built-in drivers derive their provider idempotency mechanism from it
     * (Stripe's Idempotency-Key, Paystack's transaction reference) rather than from the payable.
     * That is what makes replaying a timed-out attempt converge on the SAME provider session
     * while a later, provider-proven renewal gets a genuinely new one. A driver that has any
     * idempotency mechanism at all MUST derive it from this value and MUST refuse (before any
     * provider I/O) when it is missing — a caller with no attempt identity has no way to retry
     * safely.
     *
     * Implementations must also validate the provider's returned checkout URL against their own
     * trusted host set before returning it; see {@see \Glueful\Extensions\Payvia\Support
     * \HostedCheckoutUrl}.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed> At least reference and checkout_url when available.
     */
    public function initialize(PayableReference $payable, array $options = []): array;
}
