<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
use Glueful\Extensions\Payvia\Tests\Support\FakeStrictPaymentEventListener;
use PHPUnit\Framework\TestCase;

/**
 * `PayviaServiceProvider::composeStrictLane()` turns the raw, arbitrarily-ordered iterable a
 * container tag resolves into into the deterministic, validated lane `makeWebhookService()`'s
 * composed dispatcher iterates: FQCN-sorted, one instance per concrete class, and loudly
 * rejecting anything that isn't actually a {@see \Glueful\Extensions\Payvia\Contracts\
 * StrictPaymentEventListener} rather than silently skipping it.
 */
final class StrictLaneCompositionTest extends TestCase
{
    public function testEmptyIterableReturnsEmptyArray(): void
    {
        self::assertSame([], PayviaServiceProvider::composeStrictLane([]));
    }

    public function testResultIsSortedByFqcnRegardlessOfInputOrder(): void
    {
        $b = new class () implements StrictPaymentEventListener {
            public function supports(PaymentProviderEventInterface $event): bool
            {
                return true;
            }

            public function handle(PaymentProviderEventInterface $event): void
            {
            }
        };
        $a = new FakeStrictPaymentEventListener();

        // Input order is deliberately the reverse of FQCN order.
        $result = PayviaServiceProvider::composeStrictLane([$b, $a]);

        $classes = array_map(get_class(...), $result);
        $sorted = $classes;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $classes);
        self::assertCount(2, $result);
    }

    public function testDuplicateConcreteClassThrows(): void
    {
        $listener = new FakeStrictPaymentEventListener();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(FakeStrictPaymentEventListener::class);

        PayviaServiceProvider::composeStrictLane([$listener, new FakeStrictPaymentEventListener()]);
    }

    public function testNonImplementingItemThrowsNamingTheOffender(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('stdClass');

        PayviaServiceProvider::composeStrictLane([new \stdClass()]);
    }
}
