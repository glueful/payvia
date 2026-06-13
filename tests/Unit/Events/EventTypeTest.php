<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Events;

use Glueful\Extensions\Payvia\Events\EventType;
use PHPUnit\Framework\TestCase;

final class EventTypeTest extends TestCase
{
    public function testImmutableTypesAreImmutable(): void
    {
        self::assertTrue(EventType::isImmutable(EventType::PAYMENT_SUCCEEDED));
        self::assertTrue(EventType::isImmutable(EventType::INVOICE_PAID));
        self::assertTrue(EventType::isImmutable(EventType::SUBSCRIPTION_CREATED));
        self::assertTrue(EventType::isImmutable(EventType::SUBSCRIPTION_CANCELED));
    }

    public function testMutableTypesAreNotImmutable(): void
    {
        self::assertFalse(EventType::isImmutable(EventType::SUBSCRIPTION_UPDATED));
        self::assertFalse(EventType::isImmutable(EventType::SUBSCRIPTION_PAST_DUE));
    }

    public function testPaymentFailedIsMutableForLogicalDedup(): void
    {
        // Repeat failures for the same entity must not be deduplicated away,
        // so payment.failed is treated as mutable (still a known event).
        self::assertFalse(EventType::isImmutable(EventType::PAYMENT_FAILED));
        self::assertTrue(EventType::isKnown(EventType::PAYMENT_FAILED));
    }

    public function testKnownVsUnknown(): void
    {
        self::assertTrue(EventType::isKnown('payment.succeeded'));
        self::assertFalse(EventType::isKnown('something.weird'));
        self::assertSame('unknown', EventType::UNKNOWN);
    }
}
