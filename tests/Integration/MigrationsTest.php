<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\AddProviderEventsDispatchIndex;
use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
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
            'amount' => 5000,
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
