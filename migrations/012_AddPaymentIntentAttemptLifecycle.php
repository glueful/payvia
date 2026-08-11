<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderInterface;

/**
 * Payment-links Task 1: reference-addressable session attempts with durable idempotency ports.
 *
 * `payment_intents` (007) modeled exactly one lifecycle: insert `open` (only once the gateway
 * had already handed back a `reference`), then `close()`. That required calling the gateway
 * BEFORE any row existed, so a crash between the provider call and the insert lost the
 * provider's reference entirely and left the next retry to mint a brand-new one.
 *
 * This migration folds in an earlier, poorer-information phase -- `initializing` -- so a row can
 * be claimed (and its idempotency port held) BEFORE provider I/O ever happens:
 *
 *  - `reference` must permit NULL: an `initializing` attempt has no provider reference yet.
 *    (Empirically, every column this framework's schema builder generates defaults to nullable
 *    unless a migration explicitly calls `notNullable()` -- confirmed by inspecting both
 *    `PRAGMA table_info` on SQLite and `information_schema.columns` on PostgreSQL against a
 *    freshly-migrated 007 table -- so 007's `reference` was ALREADY nullable at the actual
 *    constraint level despite its column comment's intent. This migration does not need to
 *    "relax" a constraint that was never truly enforced; its job is the NEW composite unique
 *    below, plus making the nullability explicit/intentional in the schema definition itself
 *    rather than leaving it an accidental side effect of an unset default.)
 *  - `status` stays a plain, service-enforced string (no DB enum, matching every other status
 *    column in this schema): `initializing|open|superseded|closed|failed`.
 *  - The existing globally-unique `uuid` IS the attempt identity -- no second "attempt id"
 *    column is introduced.
 *  - The existing `UNIQUE(tenant_uuid, idempotency_key)` is retained, but its re-keying scheme
 *    changes: `initializing`/`open` rows hold the payable's ACTIVE port key
 *    (`{payable_type}:{payable_id}`, unchanged from 007), so at most one attempt can ever be
 *    in flight (or open) per payable. On supersession or any terminal transition
 *    (`superseded`/`closed`/`failed`) the row is re-keyed to `{payable_type}:{payable_id}:{uuid}`
 *    -- using the attempt's OWN uuid rather than 007's `{...}:{reference}` scheme, because a
 *    `failed` attempt may never have obtained a reference at all. Re-keying by uuid frees the
 *    active port for a successor attempt the instant the row leaves `initializing`/`open`.
 *  - A NEW portable `UNIQUE(tenant_uuid, gateway, reference)` lets a reference be looked up
 *    directly once minted. Both SQLite and PostgreSQL treat every NULL as distinct under a
 *    unique index (the same fact 008/010 already rely on), so any number of `initializing` rows
 *    (`reference IS NULL`) coexist freely; only a genuine duplicate non-NULL
 *    `(tenant, gateway, reference)` is rejected. THIS is the migration's real, load-bearing
 *    change -- 007 had no such constraint at all.
 *
 * SQLite cannot express "add a composite UNIQUE constraint to an existing table" through its
 * ALTER TABLE dialect (confirmed empirically against `SQLiteSqlGenerator::modifyColumn()` /
 * `alterTable()`, which only ever wire up add/drop columns and plain `CREATE INDEX`/`DROP INDEX`
 * -- never a genuine table-level `UNIQUE` constraint), so the SQLite path recreates the table:
 * build the new shape, copy every existing row across unchanged, drop the old table, and rename
 * the new one into place. PostgreSQL supports a real additive `CREATE UNIQUE INDEX` (plus a
 * defensive, idempotent `ALTER COLUMN ... DROP NOT NULL`, harmless even though there was never a
 * NOT NULL constraint to drop), so it takes the lighter-weight in-place path. Both paths are
 * idempotent -- guarded by whether the new composite unique index already exists, checked by
 * reading the live catalog -- and purely data-preserving: no existing row's values change.
 */
class AddPaymentIntentAttemptLifecycle implements MigrationInterface
{
    private const TABLE = 'payment_intents';
    private const COMPOSITE_UNIQUE_INDEX = 'payment_intents_tenant_uuid_gateway_reference_unique';

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE) || $this->hasCompositeUniqueIndex($schema)) {
            return;
        }

        if ($schema->getConnection()->getDriverName() === 'sqlite') {
            $this->upSqlite($schema);
            return;
        }

        $this->upPortable($schema);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE) || !$this->hasCompositeUniqueIndex($schema)) {
            return;
        }

        if ($schema->getConnection()->getDriverName() === 'sqlite') {
            $this->downSqlite($schema);
            return;
        }

        $this->downPortable($schema);
    }

    public function getDescription(): string
    {
        return 'Adds a portable UNIQUE(tenant_uuid, gateway, reference) (multiple NULLs '
            . 'permitted) to payment_intents for reference-addressable session attempts.';
    }

    /**
     * PostgreSQL: an additive unique index, plus a defensive (and here, no-op-safe) `ALTER
     * COLUMN ... DROP NOT NULL` -- see the class docblock for why `reference` was already
     * nullable. `modifyColumn()` is deliberately NOT used: the fluent `alterTable()` builder
     * never actually wires column modifications into its generated statements (only add/drop
     * columns and indexes), so it would silently no-op; raw SQL is the only path that works.
     */
    private function upPortable(SchemaBuilderInterface $schema): void
    {
        $schema->addPendingOperation(
            'ALTER TABLE "' . self::TABLE . '" ALTER COLUMN "reference" DROP NOT NULL;'
        );
        $schema->execute();

        $schema->alterTable(self::TABLE, static function (TableBuilderInterface $table): void {
            $table->unique(['tenant_uuid', 'gateway', 'reference']);
        });
    }

    private function downPortable(SchemaBuilderInterface $schema): void
    {
        $schema->alterTable(self::TABLE, static function (TableBuilderInterface $table): void {
            $table->dropUnique(self::COMPOSITE_UNIQUE_INDEX);
        });
    }

    /**
     * SQLite: recreate the table under the new shape, copy every row across verbatim, then swap
     * it into place.
     */
    private function upSqlite(SchemaBuilderInterface $schema): void
    {
        $tmp = self::TABLE . '_attempt_lifecycle_tmp';

        $schema->createTable($tmp, static function (TableBuilderInterface $table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12)->default('');

            $table->string('payable_type', 100);
            $table->string('payable_id', 255);

            $table->string('idempotency_key', 512);

            $table->string('gateway', 50);
            $table->string('reference', 100)->nullable();
            $table->string('status', 16)->default('open');

            $table->bigInteger('amount');
            $table->string('currency', 10);
            $table->json('payload')->nullable();

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique(['tenant_uuid', 'idempotency_key']);
            $table->unique(['tenant_uuid', 'gateway', 'reference']);
            $table->index('tenant_uuid');
            $table->index('reference');
            $table->index(['payable_type', 'payable_id', 'status']);
            $table->index('gateway');
        });

        $this->copyAndSwap($schema, self::TABLE, $tmp);
    }

    private function downSqlite(SchemaBuilderInterface $schema): void
    {
        $tmp = self::TABLE . '_attempt_lifecycle_revert_tmp';

        $schema->createTable($tmp, static function (TableBuilderInterface $table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12)->default('');

            $table->string('payable_type', 100);
            $table->string('payable_id', 255);

            $table->string('idempotency_key', 512);

            $table->string('gateway', 50);
            $table->string('reference', 100);
            $table->string('status', 16)->default('open');

            $table->bigInteger('amount');
            $table->string('currency', 10);
            $table->json('payload')->nullable();

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique(['tenant_uuid', 'idempotency_key']);
            $table->index('tenant_uuid');
            $table->index('reference');
            $table->index(['payable_type', 'payable_id', 'status']);
            $table->index('gateway');
        });

        $this->copyAndSwap($schema, self::TABLE, $tmp);
    }

    private function copyAndSwap(SchemaBuilderInterface $schema, string $table, string $tmp): void
    {
        $columns = 'id, uuid, tenant_uuid, payable_type, payable_id, idempotency_key, gateway, '
            . 'reference, status, amount, currency, payload, created_at, updated_at';

        $schema->addPendingOperation(
            'INSERT INTO "' . $tmp . '" (' . $columns . ') SELECT ' . $columns . ' FROM "' . $table . '";'
        );
        $schema->addPendingOperation('DROP TABLE "' . $table . '";');
        $schema->addPendingOperation('ALTER TABLE "' . $tmp . '" RENAME TO "' . $table . '";');
        $schema->execute();
    }

    /**
     * Idempotency guard, portable across both supported drivers: are we already past this
     * migration? `reference`'s nullability can't be used as the signal -- see the class docblock,
     * it was already nullable before this migration ever ran -- so this checks for the one thing
     * that genuinely only exists post-migration: the new composite unique constraint itself, read
     * directly from the live catalog.
     *
     * PostgreSQL takes the named-index lookup (the `upPortable()` path issues a real, explicitly
     * named `CREATE UNIQUE INDEX`). SQLite CANNOT be checked the same way: `$table->unique(...)`
     * inside a `createTable()` callback compiles to an ANONYMOUS inline `UNIQUE (...)` table
     * constraint (confirmed empirically -- `SQLiteSqlGenerator::createTable()` never emits a name
     * for it), so SQLite assigns its own internal `sqlite_autoindex_<table>_<n>` name that bears
     * no relation to this framework's `generateIndexName()` convention. The only reliable check on
     * SQLite is therefore column-set based: does ANY unique index on this table cover exactly
     * `(tenant_uuid, gateway, reference)`, regardless of its name (mirrors
     * `MigrationsTest::indexColumns()`'s own `PRAGMA index_info` convention).
     */
    private function hasCompositeUniqueIndex(SchemaBuilderInterface $schema): bool
    {
        $pdo = $schema->getConnection()->getPDO();

        if ($schema->getConnection()->getDriverName() === 'sqlite') {
            return $this->sqliteHasCompositeUniqueIndex($pdo);
        }

        $stmt = $pdo->prepare('SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?');
        $stmt->execute([self::TABLE, self::COMPOSITE_UNIQUE_INDEX]);

        return (bool) $stmt->fetchColumn();
    }

    /** @var list<string> */
    private const COMPOSITE_UNIQUE_COLUMNS = ['tenant_uuid', 'gateway', 'reference'];

    private function sqliteHasCompositeUniqueIndex(\PDO $pdo): bool
    {
        $listStmt = $pdo->query('PRAGMA index_list("' . self::TABLE . '")');
        if ($listStmt === false) {
            return false;
        }

        foreach ($listStmt->fetchAll(\PDO::FETCH_ASSOC) as $index) {
            if (((int) $index['unique']) !== 1) {
                continue;
            }

            if ($this->indexColumns($pdo, (string) $index['name']) === self::COMPOSITE_UNIQUE_COLUMNS) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function indexColumns(\PDO $pdo, string $indexName): array
    {
        $infoStmt = $pdo->query('PRAGMA index_info("' . $indexName . '")');
        if ($infoStmt === false) {
            return [];
        }

        $columns = [];
        foreach ($infoStmt->fetchAll(\PDO::FETCH_ASSOC) as $column) {
            $columns[(int) $column['seqno']] = (string) $column['name'];
        }
        ksort($columns);

        return array_values($columns);
    }
}
