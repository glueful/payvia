<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Migrations;

use Glueful\Extensions\Payvia\Database\Migrations\AddProviderEventsDispatchIndex;
use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateInvoicesTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Verifies the folded (final, house pre-launch) money + tenancy schema shape:
 *
 *  - `amount` is an integer column on payments/invoices/billing_plans
 *    (no more DECIMAL(12,2)).
 *  - billing_plans is uniquely scoped to (tenant_uuid, gateway, name) directly
 *    from creation; there is no separate scoping migration and no global
 *    UNIQUE(name) or UNIQUE(gateway, name) left behind.
 *  - payment_intents exists, created by what is now migration 007.
 *  - the five domain tables (payments, billing_plans, invoices,
 *    gateway_subscriptions, payment_intents) carry a sentinel `tenant_uuid`
 *    column (default '') plus an index, folded directly into their
 *    create-table migrations. `provider_events` deliberately carries no
 *    tenant column (global transport/inbox).
 *  - tenant business keys are composite: invoices (tenant_uuid, number),
 *    billing_plans (tenant_uuid, gateway, name), payment_intents
 *    (tenant_uuid, idempotency_key). Globally-unique correlation identities
 *    stay global: every table's uuid, payments.reference, and
 *    (gateway, gateway_subscription_id).
 *
 * Fresh-migrates the full folded sequence 001 -> 007(new) against an
 * in-memory sqlite connection, then asserts on the resulting shape.
 */
final class SchemaShapeTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->connection->getSchemaBuilder();

        // Fresh-run the full folded migration sequence: 001 -> 007 (new).
        (new CreatePaymentsTable())->up($schema);
        (new CreateBillingPlansTable())->up($schema);
        (new CreateInvoicesTable())->up($schema);
        (new CreateProviderEventsTable())->up($schema);
        (new CreateGatewaySubscriptionsTable())->up($schema);
        (new AddProviderEventsDispatchIndex())->up($schema);
        (new CreatePaymentIntentsTable())->up($schema);
    }

    public function testPaymentsAmountIsIntegerTyped(): void
    {
        self::assertSame('INTEGER', $this->columnType('payments', 'amount'));
    }

    public function testInvoicesAmountIsIntegerTyped(): void
    {
        self::assertSame('INTEGER', $this->columnType('invoices', 'amount'));
    }

    public function testBillingPlansAmountIsIntegerTyped(): void
    {
        self::assertSame('INTEGER', $this->columnType('billing_plans', 'amount'));
    }

    public function testBillingPlansAllowsSameNameAcrossDifferentGateways(): void
    {
        // A global UNIQUE(name) would reject this outright, regardless of
        // gateway. Succeeding here demonstrates the old global unique is
        // gone and the composite (gateway, name) shape is in effect.
        $this->insertPlan(['uuid' => 'aaaaaaaaaaaa', 'name' => 'Pro', 'gateway' => 'paystack']);
        $this->insertPlan(['uuid' => 'bbbbbbbbbbbb', 'name' => 'Pro', 'gateway' => 'stripe']);

        self::assertSame(2, $this->planCount());
    }

    public function testBillingPlansRejectsSameNameSameGateway(): void
    {
        $this->insertPlan(['uuid' => 'aaaaaaaaaaaa', 'name' => 'Pro', 'gateway' => 'paystack']);

        $this->expectException(\PDOException::class);
        $this->insertPlan(['uuid' => 'bbbbbbbbbbbb', 'name' => 'Pro', 'gateway' => 'paystack']);
    }

    public function testBillingPlansAllowsNullGatewayDuplicateNames(): void
    {
        // NULLs never collide in a unique index on any of the three
        // supported drivers, so two NULL-gateway rows may share a name.
        $this->insertPlan(['uuid' => 'aaaaaaaaaaaa', 'name' => 'Pro', 'gateway' => null]);
        $this->insertPlan(['uuid' => 'bbbbbbbbbbbb', 'name' => 'Pro', 'gateway' => null]);

        self::assertSame(2, $this->planCount());
    }

    public function testPaymentIntentsTableExistsFromRenamedMigration(): void
    {
        self::assertTrue($this->connection->getSchemaBuilder()->hasTable('payment_intents'));
    }

    // -- tenant_uuid: present + defaulted + indexed on the five domain tables --

    /**
     * @dataProvider domainTableFixtures
     * @param array<string, mixed> $row
     */
    public function testTenantUuidDefaultsToEmptyStringWhenOmitted(string $table, array $row): void
    {
        // Insert without ever mentioning tenant_uuid: single-store callers
        // must keep working unmodified after the fold.
        self::assertArrayNotHasKey('tenant_uuid', $row);

        $this->insertRow($table, $row);

        self::assertSame('', $this->fetchValue($table, 'tenant_uuid', 'uuid', $row['uuid']));
    }

    /**
     * @dataProvider domainTableNames
     */
    public function testTenantUuidIsIndexed(string $table): void
    {
        self::assertTrue(
            $this->hasIndexCoveringColumn($table, 'tenant_uuid'),
            "{$table} must have an index covering tenant_uuid"
        );
    }

    public function testProviderEventsHasNoTenantUuidColumn(): void
    {
        // provider_events is a deliberately tenantless global transport/
        // inbox table: signed webhooks arrive without request tenant
        // context, and each domain applier resolves ownership itself.
        self::assertFalse(
            $this->connection->getSchemaBuilder()->hasColumn('provider_events', 'tenant_uuid')
        );
    }

    // -- composite (tenant-scoped) business keys --

    public function testInvoicesNumberUniqueIsScopedByTenant(): void
    {
        $this->insertRow('invoices', [
            'uuid' => 'aaaaaaaaaaaa',
            'tenant_uuid' => 'tenantAAAA01',
            'number' => 'INV-1',
            'amount' => 1000,
        ]);
        $this->insertRow('invoices', [
            'uuid' => 'bbbbbbbbbbbb',
            'tenant_uuid' => 'tenantBBBB02',
            'number' => 'INV-1',
            'amount' => 1000,
        ]);

        self::assertSame(2, $this->countAll('invoices'));

        $this->expectException(\PDOException::class);
        $this->insertRow('invoices', [
            'uuid' => 'cccccccccccc',
            'tenant_uuid' => 'tenantAAAA01',
            'number' => 'INV-1',
            'amount' => 1000,
        ]);
    }

    public function testBillingPlansSameGatewayAndNameAcrossTenantsSucceeds(): void
    {
        // With the tenant dimension folded into the composite unique, two
        // tenants may each have their own "Pro" plan under the same gateway
        // — proving the old global (gateway, name) shape is gone.
        $this->insertPlan([
            'uuid' => 'aaaaaaaaaaaa',
            'tenant_uuid' => 'tenantAAAA01',
            'name' => 'Pro',
            'gateway' => 'paystack',
        ]);
        $this->insertPlan([
            'uuid' => 'bbbbbbbbbbbb',
            'tenant_uuid' => 'tenantBBBB02',
            'name' => 'Pro',
            'gateway' => 'paystack',
        ]);

        self::assertSame(2, $this->planCount());
    }

    public function testPaymentIntentsIdempotencyKeyUniqueIsScopedByTenant(): void
    {
        $base = [
            'payable_type' => 'invoice',
            'payable_id' => '1',
            'idempotency_key' => 'invoice:1',
            'gateway' => 'stripe',
            'reference' => 'ref-shared',
            'amount' => 1000,
            'currency' => 'GHS',
        ];

        $this->insertRow('payment_intents', ['uuid' => 'aaaaaaaaaaaa', 'tenant_uuid' => 'tenantAAAA01'] + $base);
        $this->insertRow(
            'payment_intents',
            ['uuid' => 'bbbbbbbbbbbb', 'tenant_uuid' => 'tenantBBBB02', 'reference' => 'ref-other'] + $base
        );

        self::assertSame(2, $this->countAll('payment_intents'));

        $this->expectException(\PDOException::class);
        $this->insertRow(
            'payment_intents',
            ['uuid' => 'cccccccccccc', 'tenant_uuid' => 'tenantAAAA01', 'reference' => 'ref-dup'] + $base
        );
    }

    // -- global (tenantless) correlation identities stay global --

    public function testPaymentsReferenceStaysGloballyUniqueAcrossTenants(): void
    {
        $this->insertRow('payments', [
            'uuid' => 'aaaaaaaaaaaa',
            'tenant_uuid' => 'tenantAAAA01',
            'gateway' => 'paystack',
            'reference' => 'DUPREF',
            'amount' => 1000,
        ]);

        $this->expectException(\PDOException::class);
        $this->insertRow('payments', [
            'uuid' => 'bbbbbbbbbbbb',
            'tenant_uuid' => 'tenantBBBB02',
            'gateway' => 'paystack',
            'reference' => 'DUPREF',
            'amount' => 1000,
        ]);
    }

    public function testGatewaySubscriptionsCompositeKeyStaysGloballyUniqueAcrossTenants(): void
    {
        $this->insertRow('gateway_subscriptions', [
            'uuid' => 'aaaaaaaaaaaa',
            'tenant_uuid' => 'tenantAAAA01',
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_dup',
            'status' => 'active',
        ]);

        $this->expectException(\PDOException::class);
        $this->insertRow('gateway_subscriptions', [
            'uuid' => 'bbbbbbbbbbbb',
            'tenant_uuid' => 'tenantBBBB02',
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_dup',
            'status' => 'active',
        ]);
    }

    public function testInvoicesUuidStaysGloballyUniqueAcrossTenants(): void
    {
        $this->insertRow('invoices', [
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantAAAA01',
            'number' => 'INV-1',
            'amount' => 1000,
        ]);

        $this->expectException(\PDOException::class);
        $this->insertRow('invoices', [
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantBBBB02',
            'number' => 'INV-2',
            'amount' => 1000,
        ]);
    }

    public function testPaymentsUuidStaysGloballyUniqueAcrossTenants(): void
    {
        $this->insertRow('payments', [
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantAAAA01',
            'gateway' => 'paystack',
            'reference' => 'ref-A',
            'amount' => 1000,
        ]);

        $this->expectException(\PDOException::class);
        $this->insertRow('payments', [
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantBBBB02',
            'gateway' => 'paystack',
            'reference' => 'ref-B',
            'amount' => 1000,
        ]);
    }

    public function testBillingPlansUuidStaysGloballyUniqueAcrossTenants(): void
    {
        $this->insertRow('billing_plans', [
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantAAAA01',
            'name' => 'Pro',
            'amount' => 1000,
        ]);

        $this->expectException(\PDOException::class);
        $this->insertRow('billing_plans', [
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantBBBB02',
            'name' => 'Elite',
            'amount' => 1000,
        ]);
    }

    public function testGatewaySubscriptionsUuidStaysGloballyUniqueAcrossTenants(): void
    {
        $this->insertRow('gateway_subscriptions', [
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantAAAA01',
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_a',
            'status' => 'active',
        ]);

        $this->expectException(\PDOException::class);
        $this->insertRow('gateway_subscriptions', [
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantBBBB02',
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_b',
            'status' => 'active',
        ]);
    }

    public function testPaymentIntentsUuidStaysGloballyUniqueAcrossTenants(): void
    {
        $this->insertRow('payment_intents', [
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantAAAA01',
            'payable_type' => 'invoice',
            'payable_id' => '1',
            'idempotency_key' => 'invoice:1',
            'gateway' => 'stripe',
            'reference' => 'ref-A',
            'amount' => 1000,
            'currency' => 'GHS',
        ]);

        $this->expectException(\PDOException::class);
        $this->insertRow('payment_intents', [
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantBBBB02',
            'payable_type' => 'invoice',
            'payable_id' => '2',
            'idempotency_key' => 'invoice:2',
            'gateway' => 'stripe',
            'reference' => 'ref-B',
            'amount' => 1000,
            'currency' => 'GHS',
        ]);
    }

    // -- fixtures / helpers --

    /**
     * Minimal insertable rows for the five tenant-scoped domain tables,
     * deliberately omitting tenant_uuid so the default kicks in.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function domainTableFixtures(): array
    {
        return [
            'payments' => ['payments', [
                'uuid' => 'aaaaaaaaaaaa',
                'gateway' => 'paystack',
                'reference' => 'ref-1',
                'amount' => 1000,
            ]],
            'billing_plans' => ['billing_plans', [
                'uuid' => 'aaaaaaaaaaaa',
                'name' => 'Pro',
                'amount' => 1000,
            ]],
            'invoices' => ['invoices', [
                'uuid' => 'aaaaaaaaaaaa',
                'number' => 'INV-1',
                'amount' => 1000,
            ]],
            'gateway_subscriptions' => ['gateway_subscriptions', [
                'uuid' => 'aaaaaaaaaaaa',
                'gateway' => 'stripe',
                'gateway_subscription_id' => 'sub_1',
                'status' => 'active',
            ]],
            'payment_intents' => ['payment_intents', [
                'uuid' => 'aaaaaaaaaaaa',
                'payable_type' => 'invoice',
                'payable_id' => '1',
                'idempotency_key' => 'invoice:1',
                'gateway' => 'stripe',
                'reference' => 'ref-1',
                'amount' => 1000,
                'currency' => 'GHS',
            ]],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function domainTableNames(): array
    {
        $names = [];
        foreach (self::domainTableFixtures() as $key => [$table, $row]) {
            $names[$key] = [$table];
        }

        return $names;
    }

    private function columnType(string $table, string $column): string
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach ($schema->getTableColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return (string) $definition['type'];
            }
        }

        self::fail("column {$column} not found on {$table}");
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertPlan(array $overrides): void
    {
        $row = array_merge([
            'uuid' => 'aaaaaaaaaaaa',
            'name' => 'Plan',
            'amount' => 1000,
            'currency' => 'GHS',
            'gateway' => null,
        ], $overrides);

        $this->insertRow('billing_plans', $row);
    }

    private function planCount(): int
    {
        return $this->countAll('billing_plans');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertRow(string $table, array $row): void
    {
        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') '
            . 'VALUES (' . implode(', ', $placeholders) . ')'
        );

        $params = [];
        foreach ($row as $key => $value) {
            $params[':' . $key] = $value;
        }
        $stmt->execute($params);
    }

    private function fetchValue(string $table, string $column, string $whereColumn, mixed $whereValue): mixed
    {
        $stmt = $this->connection->getPDO()->prepare(
            'SELECT ' . $column . ' FROM ' . $table . ' WHERE ' . $whereColumn . ' = :val'
        );
        $stmt->execute([':val' => $whereValue]);

        return $stmt->fetchColumn();
    }

    private function countAll(string $table): int
    {
        $stmt = $this->connection->getPDO()->query('SELECT COUNT(*) FROM ' . $table);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function hasIndexCoveringColumn(string $table, string $column): bool
    {
        $pdo = $this->connection->getPDO();

        $listStmt = $pdo->query('PRAGMA index_list("' . $table . '")');
        self::assertNotFalse($listStmt);

        foreach ($listStmt->fetchAll(\PDO::FETCH_ASSOC) as $index) {
            $infoStmt = $pdo->query('PRAGMA index_info("' . $index['name'] . '")');
            self::assertNotFalse($infoStmt);

            $columns = array_column($infoStmt->fetchAll(\PDO::FETCH_ASSOC), 'name');
            if (in_array($column, $columns, true)) {
                return true;
            }
        }

        return false;
    }
}
