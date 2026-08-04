<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Support;

use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Payvia\Contracts\SubscriptionProjectionAcknowledger;
use Glueful\Extensions\Payvia\Events\EventType;

/**
 * Stands in for subscriptions 2.2's strict bridge (design spec §3.6): on every
 * `subscription.created` delivery it "projects" (records that it was called) then, unless
 * configured to simulate an unmapped/transient projection failure (`$outcome === null`, meaning
 * "crashed/threw before ever acknowledging" -- see `crashBeforeAck`), calls the injected
 * {@see SubscriptionProjectionAcknowledger} with the configured outcome, reading
 * `origination_uuid` straight off the event's normalized metadata exactly like the real
 * finalizer does.
 */
final class RecordingAckListener implements StrictPaymentEventListener
{
    /** @var list<string> */
    public array $handled = [];

    public function __construct(
        private SubscriptionProjectionAcknowledger $acknowledger,
        private string $consumer,
        private ?string $outcome,
        private ?string $reason = null,
        private bool $crashBeforeAck = false,
    ) {
    }

    public function supports(PaymentProviderEventInterface $event): bool
    {
        return $event->type() === EventType::SUBSCRIPTION_CREATED;
    }

    public function handle(PaymentProviderEventInterface $event): void
    {
        $this->handled[] = $event->logicalEventKey();

        if ($this->crashBeforeAck) {
            throw new \RuntimeException('simulated crash before acknowledgement');
        }

        if ($this->outcome === null) {
            // Simulates an unmapped/transient projection failure the projector itself already
            // swallowed (or a projector that legitimately has nothing to say yet) -- no
            // acknowledgement is ever written.
            return;
        }

        $normalized = $event->normalized();
        $originationUuid = (string) ($normalized['origination_uuid'] ?? '');

        $this->acknowledger->acknowledge(
            $originationUuid,
            $this->consumer,
            $event->logicalEventKey(),
            $this->outcome,
            $this->reason,
        );
    }
}
