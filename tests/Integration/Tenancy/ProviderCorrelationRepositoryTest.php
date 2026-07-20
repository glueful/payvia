<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Tenancy;

use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentsTable;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class ProviderCorrelationRepositoryTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->connection->getSchemaBuilder();
        (new CreateGatewaySubscriptionsTable())->up($schema);
        (new CreateBillingPlansTable())->up($schema);
        (new CreatePaymentsTable())->up($schema);
    }

    /** @param array<string,mixed> $overrides */
    private function insertPayment(array $overrides = []): void
    {
        $this->connection->table('payments')->insert(array_merge([
            'uuid' => 'payAAAAAAAA1',
            'tenant_uuid' => 'tenantAAAA01',
            'gateway' => 'stripe',
            'gateway_transaction_id' => 'txn_1',
            'reference' => 'refAAAAAAAA1',
            'payable_type' => 'order',
            'payable_id' => 'order_1',
            'amount' => 5000,
            'currency' => 'GHS',
            'status' => 'succeeded',
        ], $overrides));
    }

    public function testFindsGatewaySubscriptionByGlobalKeyRegardlessOfTenant(): void
    {
        $repo = new ProviderCorrelationRepository($this->connection);

        $uuid = $repo->upsertGatewaySubscription([
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_1',
            'tenant_uuid' => 'tenantAAAA01',
            'status' => 'active',
        ]);

        $found = $repo->findGatewaySubscriptionByGatewayId('stripe', 'sub_1');
        self::assertIsArray($found);
        self::assertSame($uuid, $found['uuid']);
        self::assertSame('tenantAAAA01', $found['tenant_uuid']);
        self::assertNull($repo->findGatewaySubscriptionByGatewayId('stripe', 'sub_missing'));
        self::assertNull($repo->findGatewaySubscriptionByGatewayId('', ''));
    }

    public function testFindsBillingPlanByGlobalUuidRegardlessOfTenant(): void
    {
        $repo = new ProviderCorrelationRepository($this->connection);
        $this->connection->table('billing_plans')->insert([
            'uuid' => 'planAAAAAAAA',
            'tenant_uuid' => 'tenantAAAA01',
            'name' => 'Pro',
            'amount' => 5000,
            'currency' => 'GHS',
        ]);

        $found = $repo->findBillingPlanByUuid('planAAAAAAAA');
        self::assertIsArray($found);
        self::assertSame('tenantAAAA01', $found['tenant_uuid']);
        self::assertNull($repo->findBillingPlanByUuid('missing'));
        self::assertNull($repo->findBillingPlanByUuid(''));
    }

    public function testFindsPaymentOwnerByGatewayTxnRegardlessOfTenant(): void
    {
        // No request tenant is bound anywhere in this harness -- a dispute webhook arrives
        // with no caller tenant, and this correlation must still resolve the persisted owner.
        $repo = new ProviderCorrelationRepository($this->connection);
        $this->insertPayment();

        $found = $repo->findPaymentOwnerByGatewayTxn('stripe', 'txn_1');

        self::assertSame([
            'tenant_uuid' => 'tenantAAAA01',
            'reference' => 'refAAAAAAAA1',
            'payable_type' => 'order',
            'payable_id' => 'order_1',
            'amount' => 5000,
            'currency' => 'GHS',
        ], $found);
    }

    public function testFindPaymentOwnerByGatewayTxnReturnsNullOnZeroMatches(): void
    {
        $repo = new ProviderCorrelationRepository($this->connection);

        self::assertNull($repo->findPaymentOwnerByGatewayTxn('stripe', 'txn_missing'));
        self::assertNull($repo->findPaymentOwnerByGatewayTxn('', ''));
    }

    public function testFindPaymentOwnerByGatewayTxnFailsClosedWhenMultipleTenantsShareTheSameKey(): void
    {
        $repo = new ProviderCorrelationRepository($this->connection);

        // Two payments collide on the same (gateway, gateway_transaction_id) but are owned by
        // DIFFERENT tenants. The correlation must never guess -- it returns null rather than
        // picking either row.
        $this->insertPayment([
            'uuid' => 'payAAAAAAAA1',
            'tenant_uuid' => 'tenantAAAA01',
            'gateway_transaction_id' => 'txn_dupe',
            'reference' => 'refAAAAAAAA1',
        ]);
        $this->insertPayment([
            'uuid' => 'payAAAAAAAA2',
            'tenant_uuid' => 'tenantBBBB02',
            'gateway_transaction_id' => 'txn_dupe',
            'reference' => 'refAAAAAAAA2',
        ]);

        self::assertNull($repo->findPaymentOwnerByGatewayTxn('stripe', 'txn_dupe'));
    }

    public function testFindPaymentOwnerByGatewayTxnInvokesRunAsSystemWhenARunnerIsSupplied(): void
    {
        $systemCalls = 0;
        $runner = $this->countingRunner($systemCalls);
        $repo = new ProviderCorrelationRepository($this->connection, tenancyResolverPresent: true, runner: $runner);
        $this->insertPayment();

        $found = $repo->findPaymentOwnerByGatewayTxn('stripe', 'txn_1');

        self::assertIsArray($found);
        self::assertGreaterThan(0, $systemCalls);
    }

    public function testUpsertNeverMovesOwnershipToADifferentCallerSuppliedTenant(): void
    {
        $repo = new ProviderCorrelationRepository($this->connection);

        $uuid = $repo->upsertGatewaySubscription([
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_2',
            'tenant_uuid' => 'tenantAAAA01',
            'status' => 'active',
        ]);

        // A second upsert for the SAME global key supplying a DIFFERENT tenant_uuid must not
        // move ownership -- the update path is owner-qualified against the row's own
        // persisted tenant, never the caller's payload.
        $again = $repo->upsertGatewaySubscription([
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_2',
            'tenant_uuid' => 'tenantBBBB02',
            'status' => 'past_due',
        ]);

        self::assertSame($uuid, $again);
        $row = $repo->findGatewaySubscriptionByGatewayId('stripe', 'sub_2');
        self::assertSame('tenantAAAA01', $row['tenant_uuid']);
        self::assertSame('past_due', $row['status']);
    }

    public function testUpdateGatewaySubscriptionOwnedRefusesAWrongTenantPredicate(): void
    {
        $repo = new ProviderCorrelationRepository($this->connection);
        $uuid = $repo->upsertGatewaySubscription([
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_3',
            'tenant_uuid' => 'tenantAAAA01',
            'status' => 'active',
        ]);

        $ok = $repo->updateGatewaySubscriptionOwned('tenantBBBB02', $uuid, ['status' => 'canceled']);
        self::assertFalse($ok);

        $row = $repo->findGatewaySubscriptionByGatewayId('stripe', 'sub_3');
        self::assertSame('active', $row['status']);

        // Owner-qualified with the CORRECT tenant succeeds.
        self::assertTrue($repo->updateGatewaySubscriptionOwned('tenantAAAA01', $uuid, ['status' => 'canceled']));
        self::assertSame('canceled', $repo->findGatewaySubscriptionByGatewayId('stripe', 'sub_3')['status']);
    }

    public function testConstructionThrowsWhenResolverPresentButRunnerMissing(): void
    {
        $this->expectException(\RuntimeException::class);

        new ProviderCorrelationRepository($this->connection, tenancyResolverPresent: true);
    }

    public function testConstructionAllowsNullRunnerWhenResolverAbsent(): void
    {
        $repo = new ProviderCorrelationRepository($this->connection, tenancyResolverPresent: false);

        self::assertInstanceOf(ProviderCorrelationRepository::class, $repo);
    }

    public function testConstructionAllowsResolverPresentWhenARunnerIsSupplied(): void
    {
        $repo = new ProviderCorrelationRepository(
            $this->connection,
            tenancyResolverPresent: true,
            runner: $this->noopRunner()
        );

        self::assertInstanceOf(ProviderCorrelationRepository::class, $repo);
    }

    public function testRunAsSystemIsInvokedForEveryQueryWhenARunnerIsSupplied(): void
    {
        $systemCalls = 0;
        $runner = $this->countingRunner($systemCalls);
        $repo = new ProviderCorrelationRepository($this->connection, tenancyResolverPresent: true, runner: $runner);

        $repo->upsertGatewaySubscription([
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_4',
            'tenant_uuid' => 'tenantAAAA01',
            'status' => 'active',
        ]);
        $repo->findGatewaySubscriptionByGatewayId('stripe', 'sub_4');

        self::assertGreaterThan(0, $systemCalls);
    }

    private function noopRunner(): TenantContextRunner
    {
        return new class implements TenantContextRunner {
            public function runAsTenant(string $tenantUuid, callable $fn): mixed
            {
                return $fn();
            }

            public function runAsSystem(callable $fn): mixed
            {
                return $fn();
            }

            public function forEachTenant(callable $fn): void
            {
            }
        };
    }

    private function countingRunner(int &$calls): TenantContextRunner
    {
        $counter = static function () use (&$calls): void {
            $calls++;
        };

        return new class ($counter) implements TenantContextRunner {
            public function __construct(private \Closure $onSystem)
            {
            }

            public function runAsTenant(string $tenantUuid, callable $fn): mixed
            {
                return $fn();
            }

            public function runAsSystem(callable $fn): mixed
            {
                ($this->onSystem)();

                return $fn();
            }

            public function forEachTenant(callable $fn): void
            {
            }
        };
    }
}
