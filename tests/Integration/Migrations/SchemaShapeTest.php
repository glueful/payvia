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
 * Verifies the folded (final, house pre-launch) money schema shape:
 *
 *  - `amount` is an integer column on payments/invoices/billing_plans
 *    (no more DECIMAL(12,2)).
 *  - billing_plans is uniquely scoped to (gateway, name) directly from
 *    creation; there is no separate scoping migration and no global
 *    UNIQUE(name) left behind.
 *  - payment_intents exists, created by what is now migration 007.
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

    private function planCount(): int
    {
        $stmt = $this->connection->getPDO()->query('SELECT COUNT(*) FROM billing_plans');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }
}
