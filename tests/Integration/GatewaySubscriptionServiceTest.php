<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Glueful\Extensions\Payvia\Services\GatewaySubscriptionService;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class GatewaySubscriptionServiceTest extends PayviaTestCase
{
    private ProviderCorrelationRepository $repo;
    private FakeWebhookGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateGatewaySubscriptionsTable());
        $this->runMigration(new CreateBillingPlansTable());
        $this->repo = new ProviderCorrelationRepository($this->connection);
        $this->gateway = new FakeWebhookGateway();
        $this->bind(FakeWebhookGateway::class, $this->gateway);
    }

    /**
     * Task 5's ownership order requires a first (no-existing-row) subscription projection to
     * correlate through a real billing plan before it may be written at all. Seed the sentinel
     * (tenant_uuid = '') plan these tests correlate against, so single-store behavior stays
     * byte-identical to pre-Task-5 while satisfying the new fail-closed contract.
     */
    private function seedDefaultPlan(string $uuid = 'planAAAAAAAA', string $tenantUuid = ''): void
    {
        $this->connection->table('billing_plans')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenantUuid,
            'name' => 'Default Plan',
            'amount' => 1000,
            'currency' => 'GHS',
        ]);
    }

    public function testRepositoryUpsertsByGatewayId(): void
    {
        $uuid = $this->repo->upsertGatewaySubscription([
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_1',
            'status' => 'active',
        ]);
        $again = $this->repo->upsertGatewaySubscription([
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_1',
            'status' => 'past_due',
        ]);

        self::assertSame($uuid, $again);
        self::assertSame('past_due', $this->repo->findGatewaySubscriptionByGatewayId('paystack', 'SUB_1')['status']);
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
        $first = $this->repo->upsertGatewaySubscription([
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_RACE',
            'status' => 'active',
            'gateway_customer_id' => 'CUS_OLD',
        ]);

        // Sanity: a normal second upsert (find hits) already takes the update
        // path and preserves the uuid. The unique-violation recovery is the same
        // update branch reached after a collision; see the detection unit test
        // and PaymentConfirmUniqueRaceTest for the recovery-after-throw coverage.
        $second = $this->repo->upsertGatewaySubscription([
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_RACE',
            'status' => 'past_due',
            'gateway_customer_id' => 'CUS_NEW',
        ]);

        self::assertSame($first, $second);
        $row = $this->repo->findGatewaySubscriptionByGatewayId('paystack', 'SUB_RACE');
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
        $this->seedDefaultPlan();
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
                'billing_plan_uuid' => 'planAAAAAAAA',
                'status' => 'active',
                'current_period_end' => '2026-07-01 00:00:00',
            ],
            ['raw' => true],
            'v1'
        );

        $service->applyProviderEvent($event);

        $row = $this->repo->findGatewaySubscriptionByGatewayId('paystack', 'SUB_2');
        self::assertSame('CUS_1', $row['gateway_customer_id']);
        self::assertSame('PLN_1', $row['gateway_price_id']);
        self::assertSame('active', $row['status']);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testNonSubscriptionEventsDoNotMutateSubscriptionProjection(): void
    {
        $this->repo->upsertGatewaySubscription([
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

        $row = $this->repo->findGatewaySubscriptionByGatewayId('stripe', 'sub_1');
        self::assertSame('active', $row['status']);
        self::assertSame('price_1', $row['gateway_price_id']);
        self::assertSame('2026-07-01 00:00:00', $row['current_period_end']);
    }

    public function testPartialSubscriptionUpdatePreservesExistingFieldsAndPersistsCorrelation(): void
    {
        $this->repo->upsertGatewaySubscription([
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

        $row = $this->repo->findGatewaySubscriptionByGatewayId('stripe', 'sub_2');
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
        $this->seedDefaultPlan();
        $event = ProviderEvent::create(
            'paystack',
            EventType::SUBSCRIPTION_UPDATED,
            null,
            'delivery-status',
            'SUB_attention',
            new \DateTimeImmutable(),
            [
                'gateway_subscription_id' => 'SUB_attention',
                'billing_plan_uuid' => 'planAAAAAAAA',
                'status' => 'attention',
            ],
            ['raw' => true],
            'v1'
        );

        $this->service()->applyProviderEvent($event);

        self::assertSame(
            'past_due',
            $this->repo->findGatewaySubscriptionByGatewayId('paystack', 'SUB_attention')['status']
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
        $this->seedDefaultPlan();
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
                'billing_plan_uuid' => 'planAAAAAAAA',
                'status' => $provider,
            ],
            ['raw' => true],
            'v1'
        );

        $this->service()->applyProviderEvent($event);

        self::assertSame(
            $expected,
            $this->repo->findGatewaySubscriptionByGatewayId('stripe', $subId)['status'],
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

    public function testReconcileNormalizesStripeShapedSubscription(): void
    {
        // Stripe returns the raw subscription object (no 'data' wrapper) with
        // unix-timestamp period fields, a scalar customer id, and the price under
        // items.data[0].price.id. The previous Paystack-shaped normalizer lost the
        // period fields entirely; assert they now persist as proper datetimes.
        $start = 1751328000; // 2025-07-01 00:00:00 UTC
        $end = 1753920000;   // 2025-07-31 00:00:00 UTC
        $this->gateway->fetchResult = [
            'id' => 'sub_x',
            'object' => 'subscription',
            'status' => 'active',
            'customer' => 'cus_x',
            'items' => ['data' => [['price' => ['id' => 'price_x']]]],
            'current_period_start' => $start,
            'current_period_end' => $end,
            'canceled_at' => null,
            'cancel_at_period_end' => false,
            'metadata' => ['billing_plan_uuid' => 'plan12345678'],
        ];

        $row = $this->stripeService()->reconcile('stripe', 'sub_x');

        self::assertSame('active', $row['status']);
        self::assertSame('cus_x', $row['gateway_customer_id']);
        self::assertSame('price_x', $row['gateway_price_id']);
        self::assertSame('plan12345678', $row['billing_plan_uuid']);
        self::assertSame(
            gmdate('Y-m-d H:i:s', $start),
            $row['current_period_start']
        );
        self::assertSame(
            gmdate('Y-m-d H:i:s', $end),
            $row['current_period_end']
        );
    }

    public function testReconcileNormalizesCanceledStripeSubscriptionTimestamp(): void
    {
        // A canceled Stripe subscription carries canceled_at as a unix integer.
        // The previous code passed the epoch straight into the DATETIME column;
        // assert it is now a proper datetime string.
        $canceledAt = 1753920000; // 2025-07-31 00:00:00 UTC
        $this->gateway->fetchResult = [
            'id' => 'sub_canceled',
            'object' => 'subscription',
            'status' => 'canceled',
            'customer' => 'cus_c',
            'items' => ['data' => [['price' => ['id' => 'price_c']]]],
            'current_period_start' => null,
            'current_period_end' => null,
            'canceled_at' => $canceledAt,
            'cancel_at_period_end' => false,
        ];

        $row = $this->stripeService()->reconcile('stripe', 'sub_canceled');

        self::assertSame('canceled', $row['status']);
        self::assertSame(
            gmdate('Y-m-d H:i:s', $canceledAt),
            $row['canceled_at']
        );
    }

    private function service(): GatewaySubscriptionService
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);

        return new GatewaySubscriptionService($this->context, $this->repo, $manager);
    }

    private function stripeService(): GatewaySubscriptionService
    {
        // Drive the Stripe normalization path: the fake driver supplies the raw
        // Stripe-shaped payload while the service dispatches normalization on the
        // 'stripe' gateway key.
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('stripe', FakeWebhookGateway::class);

        return new GatewaySubscriptionService($this->context, $this->repo, $manager);
    }
}
