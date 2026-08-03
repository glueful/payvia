<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

/**
 * Immutable request driving {@see
 * \Glueful\Extensions\Payvia\Contracts\SubscriptionInitiationCapableGateway::initializeSubscription()}.
 *
 * `idempotencyKey` is the CALLER's durable local attempt identity -- Payvia derives its own
 * provider-facing idempotency reference from `originationUuid` (see the Stripe driver's
 * `payvia-subinit-<originationUuid>` header), so this key is never sent to a provider; it stays
 * part of the public request/DTO contract for callers that need to correlate a replayed request
 * back to the same local attempt.
 */
final class SubscriptionCheckoutRequest
{
    /**
     * @param array<string,string> $consumerMetadata
     */
    public function __construct(
        public readonly string $originationUuid,
        public readonly string $tenantUuid,
        public readonly string $subjectKey,
        public readonly string $gateway,
        public readonly string $providerPlanIdentifier,
        public readonly array $consumerMetadata,
        public readonly string $customerEmail,
        public readonly string $returnUrl,
        public readonly string $cancelUrl,
        public readonly string $idempotencyKey,
        public readonly ?string $requiredProjectionConsumer,
    ) {
    }
}
