<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\CreatePayviaTransfersTable;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Shape proof for `payvia_transfers` -- the durable pre-provider-I/O payout
 * transfer attempt record (Commerce Marketplace MV4, spec §3.4). Verifies
 * columns, defaults, and uniqueness/index constraints via driver
 * introspection, and that the migration is safe to re-run.
 */
final class PayviaTransfersShapeTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new CreatePayviaTransfersTable())->up($this->connection->getSchemaBuilder());
    }

    public function testTableExistsWithExpectedColumns(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        self::assertTrue($schema->hasTable('payvia_transfers'));

        foreach (
            [
                'id',
                'uuid',
                'tenant_uuid',
                'gateway',
                'idempotency_key',
                'provider_reference',
                'provider_ref',
                'destination_ref',
                'amount',
                'currency',
                'status',
                'message',
                'request_payload',
                'raw_payload',
                'created_at',
                'updated_at',
            ] as $column
        ) {
            self::assertTrue($schema->hasColumn('payvia_transfers', $column), "missing column {$column}");
        }
    }

    public function testAmountIsIntegerTyped(): void
    {
        self::assertSame('INTEGER', $this->columnType('payvia_transfers', 'amount'));
    }

    public function testTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->insertTransfer(['uuid' => 'aaaaaaaaaaaa']);

        self::assertSame('', $this->fetchValue('uuid', 'aaaaaaaaaaaa', 'tenant_uuid'));
    }

    public function testTenantUuidIsIndexed(): void
    {
        self::assertTrue(
            $this->hasIndexCoveringColumn('tenant_uuid'),
            'payvia_transfers must have an index covering tenant_uuid'
        );
    }

    public function testProviderRefIsNullableUntilKnown(): void
    {
        $this->insertTransfer(['uuid' => 'aaaaaaaaaaaa']);

        self::assertNull($this->fetchValue('uuid', 'aaaaaaaaaaaa', 'provider_ref'));
    }

    // -- uniqueness: per-attempt idempotency is tenant-scoped --

    public function testIdempotencyKeyUniqueIsScopedByTenant(): void
    {
        $base = [
            'gateway' => 'paystack',
            'idempotency_key' => 'payoutAAAA01:attempt:1',
        ];

        $this->insertTransfer(
            ['uuid' => 'aaaaaaaaaaaa', 'tenant_uuid' => 'tenantAAAA01', 'provider_reference' => 'ref-a'] + $base
        );
        $this->insertTransfer(
            ['uuid' => 'bbbbbbbbbbbb', 'tenant_uuid' => 'tenantBBBB02', 'provider_reference' => 'ref-b'] + $base
        );

        self::assertSame(2, $this->countAll());

        $this->expectException(\PDOException::class);
        $this->insertTransfer(
            ['uuid' => 'cccccccccccc', 'tenant_uuid' => 'tenantAAAA01', 'provider_reference' => 'ref-c'] + $base
        );
    }

    // -- uniqueness: provider_reference stays globally unique per gateway --

    public function testProviderReferenceStaysGloballyUniqueAcrossTenants(): void
    {
        $this->insertTransfer([
            'uuid' => 'aaaaaaaaaaaa',
            'tenant_uuid' => 'tenantAAAA01',
            'gateway' => 'paystack',
            'idempotency_key' => 'payoutAAAA01:attempt:1',
            'provider_reference' => 'DUPREF',
        ]);

        $this->expectException(\PDOException::class);
        $this->insertTransfer([
            'uuid' => 'bbbbbbbbbbbb',
            'tenant_uuid' => 'tenantBBBB02',
            'gateway' => 'paystack',
            'idempotency_key' => 'payoutBBBB02:attempt:1',
            'provider_reference' => 'DUPREF',
        ]);
    }

    public function testProviderReferenceUniqueIsPerGateway(): void
    {
        // Same provider_reference value under a DIFFERENT gateway is not a
        // collision -- the unique is (gateway, provider_reference).
        $this->insertTransfer([
            'uuid' => 'aaaaaaaaaaaa',
            'gateway' => 'paystack',
            'idempotency_key' => 'payoutAAAA01:attempt:1',
            'provider_reference' => 'SHAREDREF',
        ]);
        $this->insertTransfer([
            'uuid' => 'bbbbbbbbbbbb',
            'gateway' => 'stripe',
            'idempotency_key' => 'payoutBBBB02:attempt:1',
            'provider_reference' => 'SHAREDREF',
        ]);

        self::assertSame(2, $this->countAll());
    }

    // -- uniqueness: provider_ref tolerates many NULLs but rejects a real duplicate --

    public function testMultipleNullProviderRefsAreAllowedPerGateway(): void
    {
        // Every attempt starts with provider_ref = NULL (pre-I/O). NULLs
        // never collide in a unique index on SQLite/PostgreSQL/MySQL, so
        // many pending attempts under the same gateway must coexist.
        $this->insertTransfer([
            'uuid' => 'aaaaaaaaaaaa',
            'gateway' => 'paystack',
            'idempotency_key' => 'payoutAAAA01:attempt:1',
            'provider_reference' => 'ref-a',
        ]);
        $this->insertTransfer([
            'uuid' => 'bbbbbbbbbbbb',
            'gateway' => 'paystack',
            'idempotency_key' => 'payoutBBBB02:attempt:1',
            'provider_reference' => 'ref-b',
        ]);

        self::assertSame(2, $this->countAll());
        self::assertNull($this->fetchValue('uuid', 'aaaaaaaaaaaa', 'provider_ref'));
        self::assertNull($this->fetchValue('uuid', 'bbbbbbbbbbbb', 'provider_ref'));
    }

    public function testDuplicateProviderRefUnderSameGatewayIsRejected(): void
    {
        $this->insertTransfer([
            'uuid' => 'aaaaaaaaaaaa',
            'gateway' => 'paystack',
            'idempotency_key' => 'payoutAAAA01:attempt:1',
            'provider_reference' => 'ref-a',
            'provider_ref' => 'PS_TRF_DUP',
        ]);

        $this->expectException(\PDOException::class);
        $this->insertTransfer([
            'uuid' => 'bbbbbbbbbbbb',
            'gateway' => 'paystack',
            'idempotency_key' => 'payoutBBBB02:attempt:1',
            'provider_reference' => 'ref-b',
            'provider_ref' => 'PS_TRF_DUP',
        ]);
    }

    // -- uuid stays globally unique --

    public function testUuidStaysGloballyUniqueAcrossTenants(): void
    {
        $this->insertTransfer([
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantAAAA01',
            'gateway' => 'paystack',
            'idempotency_key' => 'payoutAAAA01:attempt:1',
            'provider_reference' => 'ref-a',
        ]);

        $this->expectException(\PDOException::class);
        $this->insertTransfer([
            'uuid' => 'sameuuid1234',
            'tenant_uuid' => 'tenantBBBB02',
            'gateway' => 'paystack',
            'idempotency_key' => 'payoutBBBB02:attempt:1',
            'provider_reference' => 'ref-b',
        ]);
    }

    // -- re-running the migration is a no-op --

    public function testMigrationIsIdempotent(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        $this->insertTransfer(['uuid' => 'aaaaaaaaaaaa']);

        (new CreatePayviaTransfersTable())->up($schema);

        self::assertTrue($schema->hasTable('payvia_transfers'));
        self::assertSame(1, $this->countAll());
    }

    // -- down() drops the table --

    public function testDownDropsTable(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        (new CreatePayviaTransfersTable())->down($schema);

        self::assertFalse($schema->hasTable('payvia_transfers'));
    }

    // -- no commerce-domain reference anywhere in the migration source --

    public function testMigrationSourceHasNoCommerceReference(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../migrations/008_CreatePayviaTransfersTable.php'
        );

        self::assertStringNotContainsStringIgnoringCase('commerce', $source);
    }

    // -- fixtures / helpers --

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertTransfer(array $overrides): void
    {
        $row = array_merge(
            [
                'uuid' => 'aaaaaaaaaaaa',
                'gateway' => 'paystack',
                'idempotency_key' => 'payoutAAAA01:attempt:1',
                'provider_reference' => 'ref-' . bin2hex(random_bytes(4)),
                'destination_ref' => 'acct_dest_1',
                'amount' => 1000,
                'currency' => 'GHS',
                'request_payload' => json_encode(['amount' => 1000]),
            ],
            $overrides
        );

        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO payvia_transfers (' . implode(', ', $columns) . ') '
            . 'VALUES (' . implode(', ', $placeholders) . ')'
        );

        $params = [];
        foreach ($row as $key => $value) {
            $params[':' . $key] = $value;
        }
        $stmt->execute($params);
    }

    private function fetchValue(string $whereColumn, mixed $whereValue, string $column): mixed
    {
        $stmt = $this->connection->getPDO()->prepare(
            'SELECT ' . $column . ' FROM payvia_transfers WHERE ' . $whereColumn . ' = :val'
        );
        $stmt->execute([':val' => $whereValue]);

        return $stmt->fetchColumn();
    }

    private function countAll(): int
    {
        $stmt = $this->connection->getPDO()->query('SELECT COUNT(*) FROM payvia_transfers');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
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

    private function hasIndexCoveringColumn(string $column): bool
    {
        $pdo = $this->connection->getPDO();

        $listStmt = $pdo->query('PRAGMA index_list("payvia_transfers")');
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
