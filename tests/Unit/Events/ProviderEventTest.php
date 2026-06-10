<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Events;

use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use PHPUnit\Framework\TestCase;

final class ProviderEventTest extends TestCase
{
    public function testImmutableLogicalKeyIsTypeColonEntity(): void
    {
        $event = ProviderEvent::create(
            gateway: 'paystack',
            type: EventType::PAYMENT_SUCCEEDED,
            providerEventId: null,
            deliveryKey: 'hash-of-body',
            entityId: 'REF_123',
            occurredAt: new \DateTimeImmutable('2026-06-09T00:00:00Z'),
            normalized: ['reference' => 'REF_123', 'status' => 'success'],
            raw: ['event' => 'charge.success'],
        );

        self::assertSame('payment.succeeded:REF_123', $event->logicalEventKey());
        self::assertSame('hash-of-body', $event->deliveryKey());
        self::assertNull($event->providerEventId());
    }

    public function testCrossPathImmutableEventsShareLogicalKey(): void
    {
        $fromVerify = ProviderEvent::create(
            'paystack',
            EventType::PAYMENT_SUCCEEDED,
            'txn_1',
            'txn_1',
            'REF_9',
            new \DateTimeImmutable(),
            [],
            []
        );
        $fromWebhook = ProviderEvent::create(
            'paystack',
            EventType::PAYMENT_SUCCEEDED,
            null,
            'evt_body_hash',
            'REF_9',
            new \DateTimeImmutable(),
            [],
            []
        );

        self::assertNotSame($fromVerify->deliveryKey(), $fromWebhook->deliveryKey());
        self::assertSame($fromVerify->logicalEventKey(), $fromWebhook->logicalEventKey());
    }

    public function testMutableLogicalKeyIncludesDiscriminator(): void
    {
        $v1 = ProviderEvent::create(
            'paystack',
            EventType::SUBSCRIPTION_UPDATED,
            null,
            'd1',
            'SUB_1',
            new \DateTimeImmutable(),
            ['status' => 'active'],
            [],
            discriminator: 'v7'
        );
        $v2 = ProviderEvent::create(
            'paystack',
            EventType::SUBSCRIPTION_UPDATED,
            null,
            'd2',
            'SUB_1',
            new \DateTimeImmutable(),
            ['status' => 'past_due'],
            [],
            discriminator: 'v8'
        );

        self::assertSame('subscription.updated:SUB_1:v7', $v1->logicalEventKey());
        self::assertNotSame($v1->logicalEventKey(), $v2->logicalEventKey());
    }

    public function testMutableWithoutDiscriminatorHashesNormalizedState(): void
    {
        $a = ProviderEvent::create(
            'paystack',
            EventType::SUBSCRIPTION_PAST_DUE,
            null,
            'd1',
            'SUB_2',
            new \DateTimeImmutable(),
            ['status' => 'past_due', 'attempt' => 1],
            []
        );
        $aAgain = ProviderEvent::create(
            'paystack',
            EventType::SUBSCRIPTION_PAST_DUE,
            null,
            'd2',
            'SUB_2',
            new \DateTimeImmutable(),
            ['status' => 'past_due', 'attempt' => 1],
            []
        );
        $b = ProviderEvent::create(
            'paystack',
            EventType::SUBSCRIPTION_PAST_DUE,
            null,
            'd3',
            'SUB_2',
            new \DateTimeImmutable(),
            ['status' => 'past_due', 'attempt' => 2],
            []
        );

        self::assertSame($a->logicalEventKey(), $aAgain->logicalEventKey());
        self::assertNotSame($a->logicalEventKey(), $b->logicalEventKey());
    }

    public function testBaseEventCarriesTypedVo(): void
    {
        $vo = ProviderEvent::create(
            'paystack',
            EventType::PAYMENT_SUCCEEDED,
            null,
            'd',
            'R',
            new \DateTimeImmutable(),
            [],
            []
        );
        $event = new PaymentProviderEvent($vo);

        self::assertSame($vo, $event->event);
        self::assertSame('payment.succeeded', $event->event->type());
        self::assertNotSame('', $event->getEventId());
    }
}
