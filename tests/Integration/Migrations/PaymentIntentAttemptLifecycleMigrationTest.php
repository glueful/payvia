<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Migrations;

use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Database\Migrations\AddPaymentIntentAttemptLifecycle;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Payment-links Task 1 (§2.1 migration block): `payment_intents.reference` becomes nullable and
 * gains a portable `UNIQUE(tenant_uuid, gateway, reference)`. This suite fresh-migrates 007 ->
 * 012 (new), and separately proves the REAL upgrade path -- seed under the 007 shape, migrate,
 * assert the existing row survived untouched -- on both SQLite (always) and PostgreSQL (gated on
 * a real, reachable instance via `DB_PGSQL_*` env vars, mirroring
 * CheckoutOriginationLedgerTest's own convention; skips cleanly when unreachable).
 */
final class PaymentIntentAttemptLifecycleMigrationTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePaymentIntentsTable());
    }

    public function testFreshMigrationMakesReferenceNullable(): void
    {
        $this->runMigration(new AddPaymentIntentAttemptLifecycle());

        $this->insert($this->connection, ['uuid' => 'aaaaaaaaaaaa', 'reference' => null]);

        self::assertSame(1, $this->rowCount($this->connection));
        self::assertNull($this->fetchReference($this->connection, 'aaaaaaaaaaaa'));
    }

    public function testMultipleNullReferencesCoexist(): void
    {
        $this->runMigration(new AddPaymentIntentAttemptLifecycle());

        $this->insert($this->connection, [
            'uuid' => 'aaaaaaaaaaaa',
            'payable_id' => '1',
            'idempotency_key' => 'invoice:1',
            'reference' => null,
        ]);
        $this->insert($this->connection, [
            'uuid' => 'bbbbbbbbbbbb',
            'payable_id' => '2',
            'idempotency_key' => 'invoice:2',
            'reference' => null,
        ]);

        self::assertSame(2, $this->rowCount($this->connection));
    }

    public function testDuplicateNonNullTenantGatewayReferenceIsRejected(): void
    {
        $this->runMigration(new AddPaymentIntentAttemptLifecycle());

        $this->insert($this->connection, [
            'uuid' => 'aaaaaaaaaaaa',
            'payable_id' => '1',
            'idempotency_key' => 'invoice:1',
            'reference' => 'shared-ref',
        ]);

        $this->expectException(\PDOException::class);
        $this->insert($this->connection, [
            'uuid' => 'bbbbbbbbbbbb',
            'payable_id' => '2',
            'idempotency_key' => 'invoice:2',
            'reference' => 'shared-ref',
        ]);
    }

    public function testDifferentGatewaysMaySharePlainReferenceUnderTheCompositeUnique(): void
    {
        $this->runMigration(new AddPaymentIntentAttemptLifecycle());

        $this->insert($this->connection, [
            'uuid' => 'aaaaaaaaaaaa',
            'payable_id' => '1',
            'idempotency_key' => 'invoice:1',
            'gateway' => 'stripe',
            'reference' => 'shared-ref',
        ]);
        $this->insert($this->connection, [
            'uuid' => 'bbbbbbbbbbbb',
            'payable_id' => '2',
            'idempotency_key' => 'invoice:2',
            'gateway' => 'paystack',
            'reference' => 'shared-ref',
        ]);

        self::assertSame(2, $this->rowCount($this->connection));
    }

    public function testRealUpgradePreservesExistingRows(): void
    {
        // Seed under the 007 shape BEFORE the new migration ever runs -- exactly what a real
        // v2.5.0 database looks like.
        $this->insert($this->connection, [
            'uuid' => 'legacyuuid01',
            'payable_id' => 'ord1',
            'idempotency_key' => 'commerce_order:ord1',
            'gateway' => 'paystack',
            'reference' => 'legacy-ref',
            'status' => 'open',
        ]);

        $this->runMigration(new AddPaymentIntentAttemptLifecycle());

        $row = $this->connection->table('payment_intents')->where(['uuid' => 'legacyuuid01'])->first();
        self::assertNotNull($row, 'the pre-existing row must survive the upgrade');
        self::assertSame('legacy-ref', $row['reference']);
        self::assertSame('open', $row['status']);
        self::assertSame('commerce_order:ord1', $row['idempotency_key']);
        self::assertSame('paystack', $row['gateway']);
        self::assertSame(4999, (int) $row['amount']);
        self::assertSame('GHS', $row['currency']);

        // And the new shape is genuinely in effect: a fresh initializing-style row with a NULL
        // reference for a DIFFERENT payable inserts cleanly alongside it.
        $this->insert($this->connection, [
            'uuid' => 'freshuuid001',
            'payable_id' => 'ord2',
            'idempotency_key' => 'commerce_order:ord2',
            'status' => 'initializing',
            'reference' => null,
        ]);
        self::assertSame(2, $this->rowCount($this->connection));
    }

    /**
     * The CRITICAL pre-flight case: 007 never rejected a duplicate `(tenant, gateway, reference)`
     * -- a real database may already contain one (e.g. Stripe's ~24h fixed-idempotency-key replay
     * handing the same reference to a retried attempt on an already-retired payable). The
     * migration must resolve this itself rather than hard-fail partway through.
     */
    public function testDuplicateGroupIsResolvedKeepingTheNewestRowsReference(): void
    {
        $this->insert($this->connection, [
            'uuid' => 'dupuuid00001',
            'payable_id' => 'ord-dup-1',
            'idempotency_key' => 'commerce_order:ord-dup-1',
            'gateway' => 'stripe',
            'reference' => 'cs_test_dup',
            'status' => 'closed',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->insert($this->connection, [
            'uuid' => 'dupuuid00002',
            'payable_id' => 'ord-dup-1',
            'idempotency_key' => 'commerce_order:ord-dup-1:dupuuid00002',
            'gateway' => 'stripe',
            'reference' => 'cs_test_dup',
            'status' => 'closed',
            'created_at' => '2026-01-02 00:00:00',
        ]);
        $this->insert($this->connection, [
            'uuid' => 'dupuuid00003',
            'payable_id' => 'ord-dup-1',
            'idempotency_key' => 'commerce_order:ord-dup-1:dupuuid00003',
            'gateway' => 'stripe',
            'reference' => 'cs_test_dup',
            'status' => 'open',
            'created_at' => '2026-01-03 00:00:00',
        ]);

        $this->runMigration(new AddPaymentIntentAttemptLifecycle());

        self::assertSame(3, $this->rowCount($this->connection), 'no row may be lost during dedup');

        $newest = $this->connection->table('payment_intents')->where(['uuid' => 'dupuuid00003'])->first();
        $middle = $this->connection->table('payment_intents')->where(['uuid' => 'dupuuid00002'])->first();
        $oldest = $this->connection->table('payment_intents')->where(['uuid' => 'dupuuid00001'])->first();

        self::assertSame('cs_test_dup', $newest['reference'], 'the newest row keeps the reference');
        self::assertNull($middle['reference'], 'older duplicates are nulled out');
        self::assertNull($oldest['reference'], 'older duplicates are nulled out');

        // The constraint is genuinely live afterward: a brand-new duplicate is rejected.
        $this->expectException(\PDOException::class);
        $this->insert($this->connection, [
            'uuid' => 'dupuuid00004',
            'payable_id' => 'ord-dup-2',
            'idempotency_key' => 'commerce_order:ord-dup-2',
            'gateway' => 'stripe',
            'reference' => 'cs_test_dup',
        ]);
    }

    public function testMigrationIsIdempotent(): void
    {
        $migration = new AddPaymentIntentAttemptLifecycle();
        $migration->up($this->connection->getSchemaBuilder());
        // Running up() a second time must not throw, and must not re-migrate an already-migrated
        // shape (no duplicate temp table, no double column change).
        $migration->up($this->connection->getSchemaBuilder());

        $this->insert($this->connection, ['uuid' => 'aaaaaaaaaaaa', 'reference' => null]);
        self::assertSame(1, $this->rowCount($this->connection));
    }

    public function testDownRemovesTheCompositeUniqueIndexSoDuplicateReferencesAreAllowedAgain(): void
    {
        $migration = new AddPaymentIntentAttemptLifecycle();
        $migration->up($this->connection->getSchemaBuilder());
        $migration->down($this->connection->getSchemaBuilder());

        $this->insert($this->connection, [
            'uuid' => 'aaaaaaaaaaaa',
            'payable_id' => '1',
            'idempotency_key' => 'invoice:1',
            'reference' => 'shared-ref',
        ]);
        // Reverted: the new UNIQUE(tenant_uuid, gateway, reference) is gone, so a duplicate
        // reference (007's actual shape all along) is no longer rejected.
        $this->insert($this->connection, [
            'uuid' => 'bbbbbbbbbbbb',
            'payable_id' => '2',
            'idempotency_key' => 'invoice:2',
            'reference' => 'shared-ref',
        ]);

        self::assertSame(2, $this->rowCount($this->connection));
    }

    // ==================================================================
    // PostgreSQL-gated: proves the same portable shape on the second supported engine.
    // ==================================================================

    public function testFreshMigrationMakesReferenceNullableOnPostgres(): void
    {
        $pg = $this->pgsqlConnection();
        $uuid = $this->pgUuid('a');

        $this->insert($pg, ['uuid' => $uuid, 'reference' => null]);

        self::assertNull($this->fetchReference($pg, $uuid));
    }

    public function testMultipleNullReferencesCoexistOnPostgres(): void
    {
        $pg = $this->pgsqlConnection();

        $this->insert($pg, [
            'uuid' => $this->pgUuid('a'),
            'payable_id' => 'pg-1',
            'idempotency_key' => $this->pgKey('pg-1'),
            'reference' => null,
        ]);
        $this->insert($pg, [
            'uuid' => $this->pgUuid('b'),
            'payable_id' => 'pg-2',
            'idempotency_key' => $this->pgKey('pg-2'),
            'reference' => null,
        ]);

        self::assertSame(2, $this->rowCount($pg));
    }

    public function testDuplicateNonNullTenantGatewayReferenceIsRejectedOnPostgres(): void
    {
        $pg = $this->pgsqlConnection();
        $ref = $this->pgKey('dupref');

        $this->insert($pg, [
            'uuid' => $this->pgUuid('a'),
            'payable_id' => 'pg-1',
            'idempotency_key' => $this->pgKey('pg-1'),
            'reference' => $ref,
        ]);

        $this->expectException(\PDOException::class);
        $this->insert($pg, [
            'uuid' => $this->pgUuid('b'),
            'payable_id' => 'pg-2',
            'idempotency_key' => $this->pgKey('pg-2'),
            'reference' => $ref,
        ]);
    }

    public function testDuplicateGroupIsResolvedKeepingTheNewestRowsReferenceOnPostgres(): void
    {
        $pg = $this->pgsqlLegacyConnection();
        $ref = $this->pgKey('dupgroup');
        $oldestUuid = $this->pgUuid('old');
        $middleUuid = $this->pgUuid('mid');
        $newestUuid = $this->pgUuid('new');

        $this->insert($pg, [
            'uuid' => $oldestUuid,
            'payable_id' => 'pg-dup-1',
            'idempotency_key' => $this->pgKey('pg-dup-1'),
            'gateway' => 'stripe',
            'reference' => $ref,
            'status' => 'closed',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->insert($pg, [
            'uuid' => $middleUuid,
            'payable_id' => 'pg-dup-1',
            'idempotency_key' => $this->pgKey('pg-dup-1'),
            'gateway' => 'stripe',
            'reference' => $ref,
            'status' => 'closed',
            'created_at' => '2026-01-02 00:00:00',
        ]);
        $this->insert($pg, [
            'uuid' => $newestUuid,
            'payable_id' => 'pg-dup-1',
            'idempotency_key' => $this->pgKey('pg-dup-1'),
            'gateway' => 'stripe',
            'reference' => $ref,
            'status' => 'open',
            'created_at' => '2026-01-03 00:00:00',
        ]);

        (new AddPaymentIntentAttemptLifecycle())->up($pg->getSchemaBuilder());

        self::assertSame(3, $this->rowCount($pg), 'no row may be lost during dedup');

        $newest = $pg->table('payment_intents')->where(['uuid' => $newestUuid])->first();
        $middle = $pg->table('payment_intents')->where(['uuid' => $middleUuid])->first();
        $oldest = $pg->table('payment_intents')->where(['uuid' => $oldestUuid])->first();

        self::assertSame($ref, $newest['reference'], 'the newest row keeps the reference');
        self::assertNull($middle['reference'], 'older duplicates are nulled out');
        self::assertNull($oldest['reference'], 'older duplicates are nulled out');
    }

    public function testRealUpgradePreservesExistingRowsOnPostgres(): void
    {
        // A fresh pgsqlConnection() already carries the NEW shape (see helper docblock): to
        // exercise a REAL upgrade on Postgres too, migrate a second, independent connection
        // starting from the 007 shape only.
        $pg = $this->pgsqlLegacyConnection();

        $uuid = $this->pgUuid('legacy');
        $this->insert($pg, [
            'uuid' => $uuid,
            'payable_id' => 'pg-legacy',
            'idempotency_key' => $this->pgKey('pg-legacy'),
            'gateway' => 'paystack',
            'reference' => 'pg-legacy-ref',
            'status' => 'open',
        ]);

        (new AddPaymentIntentAttemptLifecycle())->up($pg->getSchemaBuilder());

        $row = $pg->table('payment_intents')->where(['uuid' => $uuid])->first();
        self::assertNotNull($row);
        self::assertSame('pg-legacy-ref', $row['reference']);
        self::assertSame('open', $row['status']);

        $this->insert($pg, [
            'uuid' => $this->pgUuid('fresh'),
            'payable_id' => 'pg-fresh',
            'idempotency_key' => $this->pgKey('pg-fresh'),
            'status' => 'initializing',
            'reference' => null,
        ]);
        self::assertSame(2, $this->rowCount($pg));
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /** @param array<string,mixed> $overrides */
    private function insert(Connection $connection, array $overrides): void
    {
        $row = array_merge([
            'uuid' => 'aaaaaaaaaaaa',
            'tenant_uuid' => '',
            'payable_type' => 'invoice',
            'payable_id' => '1',
            'idempotency_key' => 'invoice:1',
            'gateway' => 'stripe',
            'reference' => 'ref-1',
            'status' => 'open',
            'amount' => 4999,
            'currency' => 'GHS',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], $overrides);

        $connection->table('payment_intents')->insert($row);
    }

    private function rowCount(Connection $connection): int
    {
        return $connection->table('payment_intents')->count();
    }

    private function fetchReference(Connection $connection, string $uuid): mixed
    {
        $row = $connection->table('payment_intents')->where(['uuid' => $uuid])->first();
        self::assertIsArray($row);

        return $row['reference'];
    }

    private static int $pgSeq = 0;

    private function pgUuid(string $label): string
    {
        self::$pgSeq++;
        return substr('pg' . self::$pgSeq . $label . str_repeat('0', 12), 0, 12);
    }

    private function pgKey(string $label): string
    {
        self::$pgSeq++;
        return 'pgidem-' . $label . '-' . self::$pgSeq . '-' . bin2hex(random_bytes(4));
    }

    /**
     * A real, reachable PostgreSQL connection already migrated to the NEW (nullable-reference)
     * shape, or a skip. Configurable via `DB_PGSQL_*` env vars (mirrors
     * CheckoutOriginationLedgerTest / thallo's own race-test convention); defaults to a local
     * `payvia_test` database as user `postgres`.
     */
    private function pgsqlConnection(): Connection
    {
        $connection = $this->rawPgsqlConnection();
        (new AddPaymentIntentAttemptLifecycle())->up($connection->getSchemaBuilder());

        return $connection;
    }

    /** A real PostgreSQL connection left at the 007 (pre-Task-1) shape, for upgrade proof. */
    private function pgsqlLegacyConnection(): Connection
    {
        return $this->rawPgsqlConnection();
    }

    private function rawPgsqlConnection(): Connection
    {
        try {
            $connection = new Connection([
                'engine' => 'pgsql',
                'pgsql' => [
                    'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                    'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                    'db' => getenv('DB_PGSQL_DATABASE') ?: 'payvia_test',
                    'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                    'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                    'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
                ],
                'pooling' => ['enabled' => false],
            ]);
            // Unlike CreateCheckoutOriginations, 007's CreatePaymentIntentsTable has no
            // hasTable() guard, and this suite's various tests each need a deterministic
            // starting shape (007's, not whatever a prior run/test left behind on this
            // persistent database) -- so always drop and recreate fresh.
            $connection->getPDO()->exec('DROP TABLE IF EXISTS payment_intents');
            (new CreatePaymentIntentsTable())->up($connection->getSchemaBuilder());

            return $connection;
        } catch (\Throwable $e) {
            self::markTestSkipped(
                'PostgreSQL not reachable (set DB_PGSQL_* env vars to run this test): ' . $e->getMessage()
            );
        }
    }
}
