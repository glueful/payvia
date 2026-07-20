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

    public function testRepeatPaymentFailuresForSameEntityGetDistinctLogicalKeys(): void
    {
        // payment.failed is mutable: a second failure for the same payment_intent
        // (different normalized state) must not collapse onto the first's key.
        $first = ProviderEvent::create(
            'stripe',
            EventType::PAYMENT_FAILED,
            null,
            'd1',
            'pi_1',
            new \DateTimeImmutable(),
            ['status' => 'failed', 'attempt' => 1],
            []
        );
        $second = ProviderEvent::create(
            'stripe',
            EventType::PAYMENT_FAILED,
            null,
            'd2',
            'pi_1',
            new \DateTimeImmutable(),
            ['status' => 'failed', 'attempt' => 2],
            []
        );

        self::assertNotSame($first->logicalEventKey(), $second->logicalEventKey());
    }

    public function testChargebackCreatedIsImmutableAndLogicalKeyIsTypeColonDisputeId(): void
    {
        self::assertTrue(EventType::isImmutable(EventType::CHARGEBACK_CREATED));

        $event = ProviderEvent::create(
            gateway: 'stripe',
            type: EventType::CHARGEBACK_CREATED,
            providerEventId: 'evt_1',
            deliveryKey: 'evt_1',
            entityId: 'dp_1',
            occurredAt: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            normalized: ['dispute_provider_event_id' => 'dp_1'],
            raw: [],
        );

        self::assertSame('chargeback.created:dp_1', $event->logicalEventKey());
    }

    public function testChargebackReversedIsImmutableAndSharesEntityWithDistinctLogicalKey(): void
    {
        self::assertTrue(EventType::isImmutable(EventType::CHARGEBACK_REVERSED));

        $created = ProviderEvent::create(
            'stripe',
            EventType::CHARGEBACK_CREATED,
            'evt_1',
            'evt_1',
            'dp_1',
            new \DateTimeImmutable(),
            [],
            []
        );
        $reversed = ProviderEvent::create(
            'stripe',
            EventType::CHARGEBACK_REVERSED,
            'evt_2',
            'evt_2',
            'dp_1',
            new \DateTimeImmutable(),
            [],
            []
        );

        // Same dispute (entityId), but the two lifecycle events are DISTINCT logical events --
        // each still dispatches exactly once, independent of the other.
        self::assertSame('chargeback.created:dp_1', $created->logicalEventKey());
        self::assertSame('chargeback.reversed:dp_1', $reversed->logicalEventKey());
        self::assertNotSame($created->logicalEventKey(), $reversed->logicalEventKey());
    }

    public function testIsChargebackHelpersClassifyOnlyChargebackTypes(): void
    {
        self::assertTrue(EventType::isChargeback(EventType::CHARGEBACK_CREATED));
        self::assertTrue(EventType::isChargeback(EventType::CHARGEBACK_REVERSED));
        self::assertFalse(EventType::isChargeback(EventType::PAYMENT_SUCCEEDED));
        self::assertFalse(EventType::isChargeback(EventType::UNKNOWN));

        self::assertTrue(EventType::isChargebackReversal(EventType::CHARGEBACK_REVERSED));
        self::assertFalse(EventType::isChargebackReversal(EventType::CHARGEBACK_CREATED));
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
