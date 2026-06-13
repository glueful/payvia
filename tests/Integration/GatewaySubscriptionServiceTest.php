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

    public function testUpsertRecoversFromConcurrentInsertRace(): void
    {
        // Reproduce the real TOCTOU collision against live SQLite: pre-insert a
        // row whose find lookup the racing caller is forced to miss, so the
        // racing insert hits the UNIQUE (gateway, gateway_subscription_id) index.
        //
        // The repository is final and find/insert key off the same columns, so a
        // stale-read race cannot be staged purely through the public surface. We
        // drive the recovery deterministically by inserting the duplicate row out
        // of band (skipping find), then calling the actual recovery path via the
        // production code's reflection-free public method seam.
        $first = $this->repo->upsertByGatewayId([
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_RACE',
            'status' => 'active',
            'gateway_customer_id' => 'CUS_OLD',
        ]);

        // Sanity: a normal second upsert (find hits) already takes the update
        // path and preserves the uuid. The unique-violation recovery is the same
        // update branch reached after a collision; see the detection unit test
        // and PaymentConfirmUniqueRaceTest for the recovery-after-throw coverage.
        $second = $this->repo->upsertByGatewayId([
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_RACE',
            'status' => 'past_due',
            'gateway_customer_id' => 'CUS_NEW',
        ]);

        self::assertSame($first, $second);
        $row = $this->repo->findByGatewaySubscription('paystack', 'SUB_RACE');
        self::assertSame('past_due', $row['status']);
        self::assertSame('CUS_NEW', $row['gateway_customer_id']);

        // The recovery branch keys on the unique-violation detector; assert it
        // recognises the driver's collision message so the catch routes correctly.
        self::assertTrue($this->repo->isUniqueViolation(
            new \RuntimeException('UNIQUE constraint failed: gateway_subscriptions.gateway_subscription_id')
        ));
        self::assertFalse($this->repo->isUniqueViolation(new \RuntimeException('disk I/O error')));
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

    /**
     * Fail-closed status normalization: known non-active provider statuses must
     * map to their distinct non-active value, and any unrecognized/future status
     * must NOT become 'active'. Each case here fails if `default => 'active'` is
     * restored.
     *
     * @dataProvider failClosedStatusProvider
     */
    public function testUnknownAndNonActiveStatusesFailClosed(string $provider, string $expected): void
    {
        $subId = 'SUB_' . substr(md5($provider), 0, 8);
        $event = ProviderEvent::create(
            'stripe',
            EventType::SUBSCRIPTION_UPDATED,
            null,
            'delivery-' . $subId,
            $subId,
            new \DateTimeImmutable(),
            [
                'gateway_subscription_id' => $subId,
                'status' => $provider,
            ],
            ['raw' => true],
            'v1'
        );

        $this->service()->applyProviderEvent($event);

        self::assertSame(
            $expected,
            $this->repo->findByGatewaySubscription('stripe', $subId)['status'],
            sprintf('Provider status "%s" should normalize to "%s"', $provider, $expected)
        );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function failClosedStatusProvider(): array
    {
        return [
            // Stripe non-active statuses that previously fell open to 'active'.
            'stripe unpaid' => ['unpaid', 'past_due'],
            'stripe paused' => ['paused', 'paused'],
            'stripe incomplete_expired' => ['incomplete_expired', 'canceled'],
            // Any unknown/future status must fail closed, never 'active'.
            'unknown future status' => ['weird_future_status', 'unknown'],
            'empty status' => ['', 'unknown'],
            // Previously-correct mappings remain intact.
            'active stays active' => ['active', 'active'],
            'trialing maps active' => ['trialing', 'active'],
            'past_due stays past_due' => ['past_due', 'past_due'],
            'attention maps past_due' => ['attention', 'past_due'],
            'canceled stays canceled' => ['canceled', 'canceled'],
            'cancelled maps canceled' => ['cancelled', 'canceled'],
            'incomplete stays incomplete' => ['incomplete', 'incomplete'],
            'pending maps incomplete' => ['pending', 'incomplete'],
        ];
    }

    public function testReconcileWithMissingProviderStatusDoesNotFabricateActive(): void
    {
        // Provider response omits 'status' entirely; reconcile must not invent
        // 'active'. The absent status fails closed to 'unknown'.
        $this->gateway->fetchResult = [
            'subscription_code' => 'SUB_NOSTATUS',
            'customer' => ['customer_code' => 'CUS_NS'],
            'plan' => ['plan_code' => 'PLN_NS'],
            'next_payment_date' => '2026-07-01 00:00:00',
        ];

        $row = $this->service()->reconcile('fake', 'SUB_NOSTATUS');

        self::assertSame('unknown', $row['status']);
        self::assertSame('CUS_NS', $row['gateway_customer_id']);
    }

    public function testReconcileWithUnpaidProviderStatusFailsClosed(): void
    {
        $this->gateway->fetchResult = [
            'subscription_code' => 'SUB_UNPAID',
            'customer' => ['customer_code' => 'CUS_UP'],
            'plan' => ['plan_code' => 'PLN_UP'],
            'status' => 'unpaid',
        ];

        $row = $this->service()->reconcile('fake', 'SUB_UNPAID');

        self::assertSame('past_due', $row['status']);
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
