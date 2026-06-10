<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\PaymentRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class PaymentServiceOutboxTest extends PayviaTestCase
{
    public function testVerifyOriginEventDedupesWithLaterWebhook(): void
    {
        $this->runMigration(new CreatePaymentsTable());
        $this->runMigration(new CreateProviderEventsTable());

        $fake = new FakeWebhookGateway();
        $this->bind(FakeWebhookGateway::class, $fake);
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);
        $events = new ProviderEventRepository($this->connection);

        $dispatched = [];
        $webhooks = new WebhookService(
            $this->context,
            $manager,
            $events,
            static function (PaymentProviderEvent $event) use (&$dispatched): void {
                $dispatched[] = $event->event->logicalEventKey();
            }
        );
        $payments = new PaymentService($this->context, new PaymentRepository($this->connection), $manager, $webhooks);

        $payments->confirmAndRecord('REF_9', 'fake');

        $body = json_encode([
            'gateway' => 'fake',
            'type' => EventType::PAYMENT_SUCCEEDED,
            'entity_id' => 'REF_9',
            'delivery_key' => 'webhook:REF_9',
            'normalized' => ['reference' => 'REF_9'],
        ], JSON_THROW_ON_ERROR);
        $webhooks->ingest('fake', $body);

        self::assertSame(['payment.succeeded:REF_9'], $dispatched);
    }
}
