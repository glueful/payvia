<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class WebhookIngestionTest extends PayviaTestCase
{
    private ProviderEventRepository $events;
    private FakeWebhookGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateProviderEventsTable());
        $this->events = new ProviderEventRepository($this->connection);
        $this->gateway = new FakeWebhookGateway();
        $this->bind(FakeWebhookGateway::class, $this->gateway);
    }

    private function service(array &$dispatched, bool $queue = false, ?callable $enqueue = null): WebhookService
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);

        return new WebhookService(
            $this->context,
            $manager,
            $this->events,
            static function (PaymentProviderEvent $event) use (&$dispatched): void {
                $dispatched[] = $event->event->logicalEventKey();
            },
            null,
            $queue,
            $enqueue
        );
    }

    public function testExactRedeliveryDoesNotDoubleDispatch(): void
    {
        $dispatched = [];
        $service = $this->service($dispatched);
        $body = json_encode([
            'type' => EventType::PAYMENT_SUCCEEDED,
            'entity_id' => 'REF_1',
            'delivery_key' => 'delivery-1',
            'normalized' => ['reference' => 'REF_1'],
        ], JSON_THROW_ON_ERROR);

        $service->ingest('fake', $body);
        $service->ingest('fake', $body);

        self::assertSame(['payment.succeeded:REF_1'], $dispatched);
    }

    public function testCrossPathLogicalDuplicateDispatchesOnce(): void
    {
        $dispatched = [];
        $service = $this->service($dispatched);

        $service->recordVerifyEvent(\Glueful\Extensions\Payvia\Events\ProviderEvent::create(
            'fake',
            EventType::PAYMENT_SUCCEEDED,
            'txn_1',
            'verify:REF_2',
            'REF_2',
            new \DateTimeImmutable(),
            ['reference' => 'REF_2'],
            []
        ));

        $body = json_encode([
            'type' => EventType::PAYMENT_SUCCEEDED,
            'entity_id' => 'REF_2',
            'delivery_key' => 'webhook:REF_2',
            'normalized' => ['reference' => 'REF_2'],
        ], JSON_THROW_ON_ERROR);
        $service->ingest('fake', $body);

        self::assertSame(['payment.succeeded:REF_2'], $dispatched);
    }

    public function testDuplicateVerifyEventDoesNotDispatchUnprocessedStoredRow(): void
    {
        $dispatched = [];
        $service = $this->service($dispatched);

        $uuid = $this->events->insertReceived([
            'gateway' => 'fake',
            'source' => 'verify',
            'delivery_key' => 'verify:REF_WAIT',
            'logical_event_key' => 'payment.succeeded:REF_WAIT',
            'type' => EventType::PAYMENT_SUCCEEDED,
            'signature_valid' => true,
            'normalized_payload' => ['reference' => 'REF_WAIT'],
            'raw_payload' => [],
        ]);
        self::assertNotNull($uuid);

        $service->recordVerifyEvent(\Glueful\Extensions\Payvia\Events\ProviderEvent::create(
            'fake',
            EventType::PAYMENT_SUCCEEDED,
            null,
            'verify:REF_WAIT',
            'REF_WAIT',
            new \DateTimeImmutable(),
            ['reference' => 'REF_WAIT'],
            []
        ));

        self::assertSame([], $dispatched);
    }

    public function testQueuedIngestPersistsButDoesNotDispatchUntilProcessed(): void
    {
        $dispatched = [];
        $queued = [];
        $service = $this->service($dispatched, true, static function (string $uuid) use (&$queued): void {
            $queued[] = $uuid;
        });

        $body = json_encode([
            'type' => EventType::PAYMENT_SUCCEEDED,
            'entity_id' => 'REF_3',
            'delivery_key' => 'delivery-3',
            'normalized' => ['reference' => 'REF_3'],
        ], JSON_THROW_ON_ERROR);

        $result = $service->ingest('fake', $body);

        self::assertSame(202, $result->httpStatus);
        self::assertCount(1, $queued);
        self::assertSame([], $dispatched);

        $service->processStored($queued[0]);
        self::assertSame(['payment.succeeded:REF_3'], $dispatched);
    }

    public function testInvalidSignatureReturns401AndDoesNotPersist(): void
    {
        $dispatched = [];
        $this->gateway->signatureValid = false;
        $service = $this->service($dispatched);
        $body = json_encode([
            'type' => EventType::PAYMENT_SUCCEEDED,
            'entity_id' => 'REF_BAD',
            'delivery_key' => 'delivery-bad',
            'normalized' => ['reference' => 'REF_BAD'],
        ], JSON_THROW_ON_ERROR);

        $result = $service->ingest('fake', $body);

        self::assertFalse($result->accepted);
        self::assertSame(401, $result->httpStatus);
        self::assertNull($this->events->findByDeliveryKey('fake', 'delivery-bad'));
        self::assertSame([], $dispatched);
    }

    public function testUnknownProviderEventsArePersistedButNotDispatched(): void
    {
        $dispatched = [];
        $service = $this->service($dispatched);
        $body = json_encode([
            'type' => EventType::UNKNOWN,
            'entity_id' => 'mystery',
            'delivery_key' => 'delivery-unknown',
            'normalized' => ['provider_type' => 'something.new'],
        ], JSON_THROW_ON_ERROR);

        $result = $service->ingest('fake', $body);
        $stored = $this->events->findByDeliveryKey('fake', 'delivery-unknown');

        self::assertSame(200, $result->httpStatus);
        self::assertSame([], $dispatched);
        self::assertSame('processed', $stored['status']);
        self::assertSame('dispatched', $stored['dispatch_status']);
    }
}
