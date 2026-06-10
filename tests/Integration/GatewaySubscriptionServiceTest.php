<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\GatewaySubscriptionRepository;
use Glueful\Extensions\Payvia\Services\GatewaySubscriptionService;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class GatewaySubscriptionServiceTest extends PayviaTestCase
{
    private GatewaySubscriptionRepository $repo;
    private FakeWebhookGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateGatewaySubscriptionsTable());
        $this->repo = new GatewaySubscriptionRepository($this->connection);
        $this->gateway = new FakeWebhookGateway();
        $this->bind(FakeWebhookGateway::class, $this->gateway);
    }

    public function testRepositoryUpsertsByGatewayId(): void
    {
        $uuid = $this->repo->upsertByGatewayId([
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_1',
            'status' => 'active',
        ]);
        $again = $this->repo->upsertByGatewayId([
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_1',
            'status' => 'past_due',
        ]);

        self::assertSame($uuid, $again);
        self::assertSame('past_due', $this->repo->findByGatewaySubscription('paystack', 'SUB_1')['status']);
    }

    public function testApplyProviderEventUpsertsSubscriptionProjection(): void
    {
        $service = $this->service();
        $event = ProviderEvent::create(
            'paystack',
            EventType::SUBSCRIPTION_UPDATED,
            null,
            'delivery',
            'SUB_2',
            new \DateTimeImmutable(),
            [
                'gateway_subscription_id' => 'SUB_2',
                'gateway_customer_id' => 'CUS_1',
                'gateway_price_id' => 'PLN_1',
                'status' => 'active',
                'current_period_end' => '2026-07-01 00:00:00',
            ],
            ['raw' => true],
            'v1'
        );

        $service->applyProviderEvent($event);

        $row = $this->repo->findByGatewaySubscription('paystack', 'SUB_2');
        self::assertSame('CUS_1', $row['gateway_customer_id']);
        self::assertSame('PLN_1', $row['gateway_price_id']);
        self::assertSame('active', $row['status']);
    }

    public function testNonSubscriptionEventsDoNotMutateSubscriptionProjection(): void
    {
        $this->repo->upsertByGatewayId([
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_1',
            'gateway_customer_id' => 'cus_1',
            'gateway_price_id' => 'price_1',
            'status' => 'active',
            'current_period_end' => '2026-07-01 00:00:00',
        ]);

        $event = ProviderEvent::create(
            'stripe',
            EventType::INVOICE_PAID,
            'evt_invoice',
            'evt_invoice',
            'in_1',
            new \DateTimeImmutable(),
            [
                'gateway_subscription_id' => 'sub_1',
                'status' => 'paid',
            ],
            ['raw' => true]
        );

        $this->service()->applyProviderEvent($event);

        $row = $this->repo->findByGatewaySubscription('stripe', 'sub_1');
        self::assertSame('active', $row['status']);
        self::assertSame('price_1', $row['gateway_price_id']);
        self::assertSame('2026-07-01 00:00:00', $row['current_period_end']);
    }

    public function testPartialSubscriptionUpdatePreservesExistingFieldsAndPersistsCorrelation(): void
    {
        $this->repo->upsertByGatewayId([
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_2',
            'gateway_customer_id' => 'cus_2',
            'gateway_price_id' => 'price_2',
            'billing_plan_uuid' => 'plan12345678',
            'status' => 'active',
            'current_period_end' => '2026-07-01 00:00:00',
            'metadata' => ['tenant_uuid' => 'tenant_1'],
        ]);

        $event = ProviderEvent::create(
            'stripe',
            EventType::SUBSCRIPTION_PAST_DUE,
            'evt_sub',
            'evt_sub',
            'sub_2',
            new \DateTimeImmutable(),
            [
                'gateway_subscription_id' => 'sub_2',
                'status' => 'past_due',
                'metadata' => ['tenant_uuid' => 'tenant_1', 'source' => 'stripe'],
            ],
            ['raw' => true],
            'v2'
        );

        $this->service()->applyProviderEvent($event);

        $row = $this->repo->findByGatewaySubscription('stripe', 'sub_2');
        self::assertSame('past_due', $row['status']);
        self::assertSame('price_2', $row['gateway_price_id']);
        self::assertSame('plan12345678', $row['billing_plan_uuid']);
        self::assertSame('2026-07-01 00:00:00', $row['current_period_end']);
        self::assertSame(
            ['tenant_uuid' => 'tenant_1', 'source' => 'stripe'],
            json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testProviderStatusIsNormalizedBeforePersistence(): void
    {
        $event = ProviderEvent::create(
            'paystack',
            EventType::SUBSCRIPTION_UPDATED,
            null,
            'delivery-status',
            'SUB_attention',
            new \DateTimeImmutable(),
            [
                'gateway_subscription_id' => 'SUB_attention',
                'status' => 'attention',
            ],
            ['raw' => true],
            'v1'
        );

        $this->service()->applyProviderEvent($event);

        self::assertSame(
            'past_due',
            $this->repo->findByGatewaySubscription('paystack', 'SUB_attention')['status']
        );
    }

    public function testReconcileFetchesProviderAndPersistsProjection(): void
    {
        $this->gateway->fetchResult = [
            'subscription_code' => 'SUB_3',
            'customer' => ['customer_code' => 'CUS_3'],
            'plan' => ['plan_code' => 'PLN_3'],
            'status' => 'active',
            'next_payment_date' => '2026-07-01 00:00:00',
        ];

        $row = $this->service()->reconcile('fake', 'SUB_3');

        self::assertSame('active', $row['status']);
        self::assertSame('CUS_3', $row['gateway_customer_id']);
        self::assertSame('PLN_3', $row['gateway_price_id']);
    }

    private function service(): GatewaySubscriptionService
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);

        return new GatewaySubscriptionService($this->context, $this->repo, $manager);
    }
}
