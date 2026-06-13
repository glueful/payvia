<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\AddProviderEventsDispatchIndex;
use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Database\Migrations\ScopeBillingPlanNameUniquePerGateway;
use Glueful\Extensions\Payvia\Repositories\BillingPlanRepository;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class MigrationsTest extends PayviaTestCase
{
    public function testBillingPlansGainsNullableGatewayLinkageColumns(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);

        self::assertTrue($schema->hasColumn('billing_plans', 'gateway'));
        self::assertTrue($schema->hasColumn('billing_plans', 'gateway_product_id'));
        self::assertTrue($schema->hasColumn('billing_plans', 'gateway_price_id'));
        self::assertFalse($schema->hasColumn('billing_plans', 'features'));
    }

    public function testListReturnsGatewayLinkage(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);

        $repo = new BillingPlanRepository($this->connection);
        $repo->create([
            'name' => 'Pro',
            'amount' => 50.0,
            'currency' => 'GHS',
            'interval' => 'monthly',
            'gateway' => 'paystack',
            'gateway_price_id' => 'PLN_x',
            'status' => 'active',
        ]);

        $rows = $repo->list([]);

        self::assertSame('paystack', $rows[0]['gateway']);
        self::assertSame('PLN_x', $rows[0]['gateway_price_id']);
        self::assertArrayNotHasKey('features', $rows[0]);
    }

    public function testProviderEventsTableHasTwoKeysAndOutboxColumns(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateProviderEventsTable())->up($schema);

        self::assertTrue($schema->hasTable('provider_events'));
        foreach (
            [
            'delivery_key',
            'logical_event_key',
            'status',
            'dispatch_status',
            'dispatched_at',
            'dispatch_claimed_at',
            'attempts',
            'signature_valid',
            'normalized_payload',
            ] as $column
        ) {
            self::assertTrue($schema->hasColumn('provider_events', $column), "missing {$column}");
        }
    }

    public function testDispatchIndexMigrationAddsCompositeIndex(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateProviderEventsTable())->up($schema);
        (new AddProviderEventsDispatchIndex())->up($schema);

        self::assertTrue(
            $this->indexExists('provider_events', 'idx_provider_events_dispatch'),
            'composite dispatch index should exist after up()'
        );

        $columns = $this->indexColumns('idx_provider_events_dispatch');
        self::assertSame(
            ['status', 'dispatch_status', 'dispatch_claimed_at'],
            $columns,
            'index column order must be equality-then-range'
        );
    }

    public function testDispatchIndexMigrationIsIdempotent(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateProviderEventsTable())->up($schema);

        $migration = new AddProviderEventsDispatchIndex();
        $migration->up($schema);
        // Running up() a second time must not throw on a duplicate index.
        $migration->up($schema);

        self::assertTrue(
            $this->indexExists('provider_events', 'idx_provider_events_dispatch'),
            'index should still exist after a second up()'
        );
    }

    public function testDispatchIndexMigrationDownDropsIndex(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateProviderEventsTable())->up($schema);

        $migration = new AddProviderEventsDispatchIndex();
        $migration->up($schema);
        $migration->down($schema);

        self::assertFalse(
            $this->indexExists('provider_events', 'idx_provider_events_dispatch'),
            'index should be gone after down()'
        );

        // down() is guarded and safe to run again without an index present.
        $migration->down($schema);
        self::assertFalse(
            $this->indexExists('provider_events', 'idx_provider_events_dispatch')
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->connection->getPDO()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?"
        );
        $stmt->execute([$table, $index]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array<int, string>
     */
    private function indexColumns(string $index): array
    {
        $stmt = $this->connection->getPDO()->query(
            "PRAGMA index_info(" . $this->connection->getPDO()->quote($index) . ")"
        );
        if ($stmt === false) {
            return [];
        }

        $columns = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $columns[(int) $row['seqno']] = (string) $row['name'];
        }
        ksort($columns);

        return array_values($columns);
    }

    public function testScopedUniqueAllowsSameNameDifferentGateway(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);
        (new ScopeBillingPlanNameUniquePerGateway())->up($schema);

        $this->insertPlan(['uuid' => 'aaaaaaaaaaaa', 'name' => 'Pro', 'gateway' => 'paystack']);
        // Same name under a different gateway must be allowed.
        $this->insertPlan(['uuid' => 'bbbbbbbbbbbb', 'name' => 'Pro', 'gateway' => 'stripe']);

        self::assertSame(2, $this->planCount());
    }

    public function testScopedUniqueRejectsSameNameSameGateway(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);
        (new ScopeBillingPlanNameUniquePerGateway())->up($schema);

        $this->insertPlan(['uuid' => 'aaaaaaaaaaaa', 'name' => 'Pro', 'gateway' => 'paystack']);

        $this->expectException(\PDOException::class);
        $this->insertPlan(['uuid' => 'bbbbbbbbbbbb', 'name' => 'Pro', 'gateway' => 'paystack']);
    }

    public function testRebuildPreservesAllRowData(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);

        // Insert BEFORE the rebuild so survival is provable.
        $this->insertPlan([
            'uuid' => 'aaaaaaaaaaaa',
            'name' => 'Pro',
            'description' => 'Pro plan',
            'amount' => 50.0,
            'currency' => 'USD',
            'interval' => 'yearly',
            'trial_days' => 14,
            'gateway' => 'paystack',
            'gateway_product_id' => 'PROD_x',
            'gateway_price_id' => 'PLN_x',
            'metadata' => '{"tier":"gold"}',
            'status' => 'active',
        ]);
        $this->insertPlan([
            'uuid' => 'bbbbbbbbbbbb',
            'name' => 'Basic',
            'amount' => 10.0,
            'gateway' => null,
        ]);

        (new ScopeBillingPlanNameUniquePerGateway())->up($schema);

        self::assertSame(2, $this->planCount());

        $row = $this->fetchPlan('aaaaaaaaaaaa');
        self::assertSame('Pro', $row['name']);
        self::assertSame('Pro plan', $row['description']);
        self::assertSame(50.0, (float) $row['amount']);
        self::assertSame('USD', $row['currency']);
        self::assertSame('yearly', $row['interval']);
        self::assertSame(14, (int) $row['trial_days']);
        self::assertSame('paystack', $row['gateway']);
        self::assertSame('PROD_x', $row['gateway_product_id']);
        self::assertSame('PLN_x', $row['gateway_price_id']);
        self::assertSame('{"tier":"gold"}', $row['metadata']);
        self::assertSame('active', $row['status']);

        $basic = $this->fetchPlan('bbbbbbbbbbbb');
        self::assertSame('Basic', $basic['name']);
        self::assertNull($basic['gateway']);
    }

    public function testScopedUniqueAllowsNullGatewayDuplicateNames(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);
        (new ScopeBillingPlanNameUniquePerGateway())->up($schema);

        // NULLs do not collide in a unique index: same name, both NULL gateway.
        $this->insertPlan(['uuid' => 'aaaaaaaaaaaa', 'name' => 'Pro', 'gateway' => null]);
        $this->insertPlan(['uuid' => 'bbbbbbbbbbbb', 'name' => 'Pro', 'gateway' => null]);

        self::assertSame(2, $this->planCount());
    }

    public function testUpIsIdempotent(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);

        $this->insertPlan(['uuid' => 'aaaaaaaaaaaa', 'name' => 'Pro', 'gateway' => 'paystack']);

        $migration = new ScopeBillingPlanNameUniquePerGateway();
        $migration->up($schema);
        // Second up() must be a guarded no-op (no throw, no data loss).
        $migration->up($schema);

        self::assertSame(1, $this->planCount());

        // Scoped uniqueness is still in force.
        $this->insertPlan(['uuid' => 'bbbbbbbbbbbb', 'name' => 'Pro', 'gateway' => 'stripe']);
        self::assertSame(2, $this->planCount());
    }

    public function testDownRestoresGlobalUnique(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);

        $migration = new ScopeBillingPlanNameUniquePerGateway();
        $migration->up($schema);
        $migration->down($schema);

        $this->insertPlan(['uuid' => 'aaaaaaaaaaaa', 'name' => 'Pro', 'gateway' => 'paystack']);

        // After down(), name is globally unique again: same name under a
        // different gateway must now be rejected.
        $this->expectException(\PDOException::class);
        $this->insertPlan(['uuid' => 'bbbbbbbbbbbb', 'name' => 'Pro', 'gateway' => 'stripe']);
    }

    public function testDownIsIdempotent(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);

        $migration = new ScopeBillingPlanNameUniquePerGateway();
        $migration->up($schema);
        $migration->down($schema);
        // Second down() is a guarded no-op.
        $migration->down($schema);

        $this->insertPlan(['uuid' => 'aaaaaaaaaaaa', 'name' => 'Pro', 'gateway' => 'paystack']);
        self::assertSame(1, $this->planCount());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertPlan(array $overrides): void
    {
        $row = array_merge([
            'uuid' => 'aaaaaaaaaaaa',
            'name' => 'Plan',
            'description' => null,
            'amount' => 10.0,
            'currency' => 'GHS',
            'interval' => 'monthly',
            'trial_days' => null,
            'gateway' => null,
            'gateway_product_id' => null,
            'gateway_price_id' => null,
            'metadata' => null,
            'status' => 'active',
        ], $overrides);

        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO billing_plans (' . implode(', ', $columns) . ') '
            . 'VALUES (' . implode(', ', $placeholders) . ')'
        );

        $params = [];
        foreach ($row as $key => $value) {
            $params[':' . $key] = $value;
        }
        $stmt->execute($params);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPlan(string $uuid): array
    {
        $stmt = $this->connection->getPDO()->prepare(
            'SELECT * FROM billing_plans WHERE uuid = ?'
        );
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row, "no billing_plans row for uuid {$uuid}");

        return $row;
    }

    private function planCount(): int
    {
        $stmt = $this->connection->getPDO()->query('SELECT COUNT(*) FROM billing_plans');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    public function testGatewaySubscriptionsTableShape(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateGatewaySubscriptionsTable())->up($schema);

        self::assertTrue($schema->hasTable('gateway_subscriptions'));
        foreach (
            [
            'gateway',
            'gateway_subscription_id',
            'gateway_customer_id',
            'gateway_price_id',
            'billing_plan_uuid',
            'status',
            'current_period_end',
            'cancel_at_period_end',
            'metadata',
            'raw_payload',
            ] as $column
        ) {
            self::assertTrue($schema->hasColumn('gateway_subscriptions', $column), "missing {$column}");
        }
    }
}
