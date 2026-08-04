<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Support;

use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutRequest;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionInitiationCapableGateway;

/**
 * Test double for {@see SubscriptionInitiationCapableGateway}, used by
 * SubscriptionCheckoutServiceTest to exercise Task 5's initializeClaim() recovery matrix without
 * a real provider. Records every call it receives (so tests can assert exactly-once-call /
 * zero-call and inspect the request that was actually built from the persisted origination row)
 * and can be configured to either return a canned success payload or throw a configured
 * throwable on its NEXT call.
 */
final class FakeSubscriptionInitiationGateway implements PaymentGatewayInterface, SubscriptionInitiationCapableGateway
{
    public int $calls = 0;

    /** @var list<SubscriptionCheckoutRequest> */
    public array $requests = [];

    /** @var array{reference:string, checkout_url:string, expires_at:?string, raw:array<string,mixed>}|null */
    public ?array $result = null;

    public ?\Throwable $throw = null;

    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    public function initializeSubscription(SubscriptionCheckoutRequest $request): array
    {
        $this->calls++;
        $this->requests[] = $request;

        if ($this->throw !== null) {
            $throw = $this->throw;
            $this->throw = null;

            throw $throw;
        }

        return $this->result ?? [
            'reference' => 'cs_fake_' . $request->originationUuid,
            'checkout_url' => 'https://checkout.fake.test/' . $request->originationUuid,
            'expires_at' => null,
            'raw' => [],
        ];
    }
}
