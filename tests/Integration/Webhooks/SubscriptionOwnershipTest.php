<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Webhooks;

use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\GatewaySubscriptionService;
use Glueful\Extensions\Payvia\Services\UnresolvedSubscriptionOwnershipException;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Task 5: subscription-webhook ownership predicates. Drives the REAL `WebhookService` +
 * `GatewaySubscriptionService` + `ProviderCorrelationRepository` stack (not a stub applier), so
 * these assertions cover the actual production wiring `PayviaServiceProvider::makeWebhookService`
 * builds: the subscription applier is the only one in the graph, and an unresolved ownership
 * failure must surface as a failed/retryable `provider_events` row with zero
 * `gateway_subscriptions` rows ever written.
 */
final class SubscriptionOwnershipTest extends PayviaTestCase
{
    private ProviderEventRepository $events;
    private ProviderCorrelationRepository $correlation;
    private FakeWebhookGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = $this->connection->getSchemaBuilder();
        (new CreateProviderEventsTable())->up($schema);
        (new CreateGatewaySubscriptionsTable())->up($schema);
        (new CreateBillingPlansTable())->up($schema);

        $this->events = new ProviderEventRepository($this->connection);
        $this->correlation = new ProviderCorrelationRepository($this->connection);
        $this->gateway = new FakeWebhookGateway();
        $this->bind(FakeWebhookGateway::class, $this->gateway);
    }

    public function testExistingOwnerUpdateIgnoresConflictingMetadataHint(): void
    {
        $this->correlation->upsertGatewaySubscription([
            'gateway' => 'fake',
            'gateway_subscription_id' => 'SUB_EXISTING',
            'tenant_uuid' => 'tenantAAAA01',
            'status' => 'active',
        ]);

        $body = json_encode([
            'type' => EventType::SUBSCRIPTION_PAST_DUE,
            'entity_id' => 'SUB_EXISTING',
            'delivery_key' => 'delivery-existing',
            'normalized' => [
                'gateway_subscription_id' => 'SUB_EXISTING',
                'status' => 'past_due',
                // A conflicting tenant hint riding along in provider metadata must never move
                // ownership away from the already-persisted owner.
                'metadata' => ['tenant_uuid' => 'tenantBBBB02'],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->service()->ingest('fake', $body);

        self::assertTrue($result->accepted);
        $row = $this->correlation->findGatewaySubscriptionByGatewayId('fake', 'SUB_EXISTING');
        self::assertSame('tenantAAAA01', $row['tenant_uuid'], 'the hint must never displace the existing owner');
        self::assertSame('past_due', $row['status'], 'the update itself must still apply');
    }

    public function testFirstProjectionDerivesOwnerFromBillingPlan(): void
    {
        $this->seedPlan('planAAAAAAAA', 'tenantCCCC03');

        $body = json_encode([
            'type' => EventType::SUBSCRIPTION_CREATED,
            'entity_id' => 'SUB_NEW',
            'delivery_key' => 'delivery-new',
            'normalized' => [
                'gateway_subscription_id' => 'SUB_NEW',
                'billing_plan_uuid' => 'planAAAAAAAA',
                'status' => 'active',
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->service()->ingest('fake', $body);

        self::assertTrue($result->accepted);
        $row = $this->correlation->findGatewaySubscriptionByGatewayId('fake', 'SUB_NEW');
        self::assertNotNull($row);
        self::assertSame('tenantCCCC03', $row['tenant_uuid']);
        self::assertSame('planAAAAAAAA', $row['billing_plan_uuid']);
    }

    public function testMetadataTenantDisagreeingWithPlanOwnerIsRejectedWithZeroRows(): void
    {
        $this->seedPlan('planAAAAAAAA', 'tenantCCCC03');

        $body = json_encode([
            'type' => EventType::SUBSCRIPTION_CREATED,
            'entity_id' => 'SUB_MISMATCH',
            'delivery_key' => 'delivery-mismatch',
            'normalized' => [
                'gateway_subscription_id' => 'SUB_MISMATCH',
                'billing_plan_uuid' => 'planAAAAAAAA',
                'status' => 'active',
                // Disagrees with the plan's real owner (tenantCCCC03) -- must fail closed.
                'metadata' => ['tenant_uuid' => 'tenantDDDD04'],
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->service()->ingest('fake', $body);
            self::fail('Expected UnresolvedSubscriptionOwnershipException to propagate');
        } catch (UnresolvedSubscriptionOwnershipException $e) {
            self::assertStringContainsString(UnresolvedSubscriptionOwnershipException::MARKER, $e->getMessage());
        }

        self::assertNull($this->correlation->findGatewaySubscriptionByGatewayId('fake', 'SUB_MISMATCH'));

        $stored = $this->events->findByDeliveryKey('fake', 'delivery-mismatch');
        self::assertSame('failed', $stored['status']);
        self::assertStringContainsString(UnresolvedSubscriptionOwnershipException::MARKER, (string) $stored['error']);
    }

    public function testNoProjectionAndNoPlanCorrelationFailsClosedAndStaysRetryableOnReplay(): void
    {
        $body = json_encode([
            'type' => EventType::SUBSCRIPTION_CREATED,
            'entity_id' => 'SUB_UNRESOLVED',
            'delivery_key' => 'delivery-unresolved',
            'normalized' => [
                'gateway_subscription_id' => 'SUB_UNRESOLVED',
                'status' => 'active',
                // No billing_plan_uuid at all, and no existing projection: ownership cannot be
                // resolved. No sentinel row may ever be written.
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->service()->ingest('fake', $body);
            self::fail('Expected UnresolvedSubscriptionOwnershipException to propagate');
        } catch (UnresolvedSubscriptionOwnershipException) {
            // expected
        }

        self::assertNull($this->correlation->findGatewaySubscriptionByGatewayId('fake', 'SUB_UNRESOLVED'));
        $stored = $this->events->findByDeliveryKey('fake', 'delivery-unresolved');
        self::assertSame('failed', $stored['status']);
        self::assertSame(1, (int) $stored['attempts']);

        // Replay: the event is still retryable (status !== 'processed'), so processStored()
        // re-attempts the applier. Ownership is still unresolved, so it fails closed again --
        // never falling open to a sentinel row on a retry.
        try {
            $this->service()->processStored((string) $stored['uuid']);
            self::fail('Expected the replay to fail closed again');
        } catch (UnresolvedSubscriptionOwnershipException) {
            // expected
        }

        $replayed = $this->events->findByUuid((string) $stored['uuid']);
        self::assertSame('failed', $replayed['status']);
        self::assertSame(2, (int) $replayed['attempts']);
        self::assertNull($this->correlation->findGatewaySubscriptionByGatewayId('fake', 'SUB_UNRESOLVED'));
    }

    public function testReconciliationReturnsExistingOwnerAndNeverMovesIt(): void
    {
        $this->correlation->upsertGatewaySubscription([
            'gateway' => 'fake',
            'gateway_subscription_id' => 'SUB_RECONCILE',
            'tenant_uuid' => 'tenantAAAA01',
            'status' => 'active',
            'gateway_customer_id' => 'CUS_OLD',
        ]);

        $this->gateway->fetchResult = [
            'subscription_code' => 'SUB_RECONCILE',
            'customer' => ['customer_code' => 'CUS_NEW'],
            'plan' => ['plan_code' => 'PLN_NEW'],
            'status' => 'active',
        ];

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);
        $service = new GatewaySubscriptionService($this->context, $this->correlation, $manager);

        $row = $service->reconcile('fake', 'SUB_RECONCILE');

        self::assertSame('tenantAAAA01', $row['tenant_uuid'], 'reconciliation must never adopt/move ownership');
        self::assertSame('CUS_NEW', $row['gateway_customer_id']);
    }

    private function service(): WebhookService
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);
        $subscriptions = new GatewaySubscriptionService($this->context, $this->correlation, $manager);

        return new WebhookService(
            $this->context,
            $manager,
            $this->events,
            null,
            static function ($event) use ($subscriptions): void {
                $subscriptions->applyProviderEvent($event);
            },
        );
    }

    private function seedPlan(string $uuid, string $tenantUuid): void
    {
        $this->connection->table('billing_plans')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenantUuid,
            'name' => 'Plan ' . $uuid,
            'amount' => 1000,
            'currency' => 'GHS',
        ]);
    }
}
