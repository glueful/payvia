<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class RelayEventsTest extends PayviaTestCase
{
    private ProviderEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateProviderEventsTable());
        $this->events = new ProviderEventRepository($this->connection);
        $this->bind(FakeWebhookGateway::class, new FakeWebhookGateway());
    }

    private function service(array &$dispatched): WebhookService
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);

        return new WebhookService(
            $this->context,
            $manager,
            $this->events,
            static function (PaymentProviderEvent $event) use (&$dispatched): void {
                $dispatched[] = $event->event->logicalEventKey();
            }
        );
    }

    public function testRelayReDispatchesProcessedPendingRows(): void
    {
        $dispatched = [];
        $service = $this->service($dispatched);
        $event = ProviderEvent::create('fake', EventType::PAYMENT_SUCCEEDED, null, 'd1', 'R1', new \DateTimeImmutable(), [], []);
        $uuid = $this->events->insertReceived([
            'gateway' => $event->gateway(),
            'source' => 'webhook',
            'delivery_key' => $event->deliveryKey(),
            'logical_event_key' => $event->logicalEventKey(),
            'type' => $event->type(),
            'signature_valid' => true,
            'normalized_payload' => [],
            'raw_payload' => [],
        ]);
        self::assertNotNull($uuid);
        $this->events->markProcessed($uuid);

        self::assertSame(1, $service->relayPending());
        self::assertSame(['payment.succeeded:R1'], $dispatched);
    }

    public function testConcurrentRelayForSameLogicalKeyEmitsExactlyOnce(): void
    {
        $dispatched = [];
        $service = $this->service($dispatched);

        $u1 = $this->events->insertReceived([
            'gateway' => 'fake',
            'source' => 'verify',
            'delivery_key' => 'verify:R2',
            'logical_event_key' => 'payment.succeeded:R2',
            'type' => EventType::PAYMENT_SUCCEEDED,
            'signature_valid' => true,
        ]);
        $u2 = $this->events->insertReceived([
            'gateway' => 'fake',
            'source' => 'webhook',
            'delivery_key' => 'webhook:R2',
            'logical_event_key' => 'payment.succeeded:R2',
            'type' => EventType::PAYMENT_SUCCEEDED,
            'signature_valid' => true,
        ]);
        self::assertNotNull($u1);
        self::assertNotNull($u2);
        $this->events->markProcessed($u1);
        $this->events->markProcessed($u2);

        $service->relayPending();
        $service->relayPending();

        self::assertSame(['payment.succeeded:R2'], $dispatched);
    }

    public function testRelayRecoversStaleDispatchClaimExactlyOnce(): void
    {
        $dispatched = [];
        $service = $this->service($dispatched);
        $uuid = $this->events->insertReceived([
            'gateway' => 'fake',
            'source' => 'webhook',
            'delivery_key' => 'stale:R3',
            'logical_event_key' => 'payment.succeeded:R3',
            'type' => EventType::PAYMENT_SUCCEEDED,
            'signature_valid' => true,
            'normalized_payload' => [],
            'raw_payload' => [],
        ]);
        self::assertNotNull($uuid);

        $this->events->markProcessed($uuid);
        self::assertGreaterThanOrEqual(1, $this->events->claimLogicalForDispatch('fake', 'payment.succeeded:R3'));

        $this->connection->table('provider_events')
            ->where(['uuid' => $uuid])
            ->update([
                'dispatch_claimed_at' => $this->connection->getDriver()
                    ->formatDateTime((new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s')),
            ]);

        self::assertSame(1, $service->relayPending(staleSeconds: 300));
        self::assertSame(['payment.succeeded:R3'], $dispatched);
        self::assertSame(0, $service->relayPending(staleSeconds: 300));
    }
}
