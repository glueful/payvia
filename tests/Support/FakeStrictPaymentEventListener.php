<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Support;

use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;

/**
 * One focused fake covering every strict-lane behavior the Task 3 tests need: a configurable
 * `supports()` answer, a configurable "fail the first N `handle()` calls" pattern (0 = never
 * fail), and a record of every event actually handled so tests can assert `supports()`-false
 * events never reach `handle()`.
 */
final class FakeStrictPaymentEventListener implements StrictPaymentEventListener
{
    private int $calls = 0;

    /** @var list<PaymentProviderEventInterface> */
    public array $handled = [];

    public function __construct(
        private bool $supportsResult = true,
        private int $failFirstN = 0,
        private ?\Throwable $failWith = null,
    ) {
    }

    public function supports(PaymentProviderEventInterface $event): bool
    {
        return $this->supportsResult;
    }

    public function handle(PaymentProviderEventInterface $event): void
    {
        $this->calls++;
        if ($this->calls <= $this->failFirstN) {
            throw $this->failWith ?? new \RuntimeException('strict listener failed');
        }
        $this->handled[] = $event;
    }

    public function callCount(): int
    {
        return $this->calls;
    }
}
