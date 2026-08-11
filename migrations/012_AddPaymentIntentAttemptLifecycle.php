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
 * PRE-FLIGHT DUPLICATE RESOLUTION (required -- 007 had zero uniqueness on `reference`, so a real
 * database MAY already contain a duplicate `(tenant_uuid, gateway, reference)` group when this
 * migration runs). This is a REACHABLE production state, not a hypothetical one: a gateway with a
 * fixed, time-boxed idempotency key -- Stripe replays the identical checkout session id/reference
 * for ~24h for a retried request -- can hand back the SAME reference for a retried attempt on a
 * payable whose earlier attempt already retired (closed/superseded/failed) under that exact
 * reference, and 007 never rejected the second row. Before creating the new constraint, both
 * driver paths deterministically resolve every such group: the NEWEST row (by `created_at`, then
 * `id`, both descending -- the row most likely to be the CURRENT, live attempt) keeps its
 * `reference`; every OLDER row sharing that `(tenant_uuid, gateway, reference)` triple has its
 * `reference` set to NULL (freely admitted by the new unique index; they are historical rows and
 * nothing about the resolution is sensitive or recorded anywhere). No row is ever deleted or has
 * any other column touched. Both paths run this pass, and everything after it, inside a single
 * transaction each -- see {@see upSqlite()}/{@see upPostgres()} -- so a mid-failure can never
 * leave the schema half-migrated (a bare, un-transacted `DROP NOT NULL` that commits before a
 * later `CREATE UNIQUE INDEX` fails on leftover duplicates was exactly this bug pre-fix).
 *
 * SQLite cannot express "add a composite UNIQUE constraint to an existing table" through its
 * ALTER TABLE dialect (confirmed empirically against `SQLiteSqlGenerator::modifyColumn()` /
 * `alterTable()`, which only ever wire up add/drop columns and plain `CREATE INDEX`/`DROP INDEX`
 * -- never a genuine table-level `UNIQUE` constraint), so the SQLite path recreates the table:
 * build the new shape, copy every existing (now duplicate-free) row across unchanged, drop the
 * old table, and rename the new one into place -- all inside one transaction, so a mid-failure
 * rolls back to the untouched original table rather than stranding a `_tmp` table. PostgreSQL
 * supports a real additive `CREATE UNIQUE INDEX` (plus a defensive, idempotent
 * `ALTER COLUMN ... DROP NOT NULL`, harmless even though there was never a NOT NULL constraint to
 * drop), so it takes the lighter-weight in-place path -- also wrapped in one transaction. Both
 * paths are idempotent -- guarded by whether the new composite unique index already exists,
 * checked by reading the live catalog.
 */
class AddPaymentIntentAttemptLifecycle implements MigrationInterface
{
    private const TABLE = 'payment_intents';
    private const COMPOSITE_UNIQUE_INDEX = 'payment_intents_tenant_uuid_gateway_reference_unique';

    /** @var list<string> */
    private const COMPOSITE_UNIQUE_COLUMNS = ['tenant_uuid', 'gateway', 'reference'];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE) || $this->hasCompositeUniqueIndex($schema)) {
            return;
        }

        if ($schema->getConnection()->getDriverName() === 'sqlite') {
            $this->upSqlite($schema);
            return;
        }

        // Every non-SQLite driver this extension is actually built and tested against is
        // PostgreSQL (see the class docblock and `hasCompositeUniqueIndex()` below) -- there is
        // no MySQL/other-driver path here despite the historically "portable" naming elsewhere in
        // this file; a MySQL target would need its own path revisited before relying on this.
        $this->upPostgres($schema);
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

        $this->downPostgres($schema);
    }

    public function getDescription(): string
    {
        return 'Resolves any pre-existing duplicate (tenant_uuid, gateway, reference) rows, then '
            . 'adds that portable UNIQUE (multiple NULLs permitted) to payment_intents for '
            . 'reference-addressable session attempts.';
    }

    /**
     * PostgreSQL-only path: null out superseded-duplicate references, then a defensive
     * (no-op-safe -- see class docblock) `ALTER COLUMN ... DROP NOT NULL`, then the additive
     * unique index -- all in ONE transaction, so a failure partway (e.g. the index creation
     * failing on a duplicate this pass missed) leaves the table exactly as it was, never
     * half-migrated. Issued as raw SQL directly against the PDO connection rather than through
     * `SchemaBuilder`/`alterTable()`: that fluent path auto-executes each statement immediately
     * (see `SchemaBuilder::alterTable()`), which is exactly the non-atomic behavior being fixed
     * here, and its `modifyColumn()` never actually wires column modifications into its generated
     * statements at all (only add/drop columns and indexes) -- it would silently no-op.
     */
    private function upPostgres(SchemaBuilderInterface $schema): void
    {
        $pdo = $schema->getConnection()->getPDO();

        $pdo->beginTransaction();
        try {
            $this->nullOutSupersededDuplicateReferences($pdo);

            $pdo->exec('ALTER TABLE "' . self::TABLE . '" ALTER COLUMN "reference" DROP NOT NULL;');
            $pdo->exec(
                'CREATE UNIQUE INDEX "' . self::COMPOSITE_UNIQUE_INDEX . '" ON "' . self::TABLE . '" '
                    . '("tenant_uuid", "gateway", "reference");'
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private function downPostgres(SchemaBuilderInterface $schema): void
    {
        $schema->getConnection()->getPDO()->exec(
            'DROP INDEX IF EXISTS "' . self::COMPOSITE_UNIQUE_INDEX . '";'
        );
    }

    /**
     * SQLite: null out superseded-duplicate references, recreate the table under the new shape,
     * copy every (now duplicate-free) row across verbatim, swap it into place, then rename its
     * plain indexes to their final, `payment_intents_..._index` names -- all in ONE transaction
     * (SQLite's DDL is fully transactional), so any failure partway rolls back to the original,
     * untouched table rather than stranding a `_tmp` table.
     *
     * The temp table's plain indexes are deliberately created with the BUILDER'S OWN
     * tmp-table-based auto-generated names (`payment_intents_attempt_lifecycle_tmp_..._index`),
     * NOT the final `payment_intents_..._index` names, even though the ORIGINAL table (about to
     * be dropped) still exists at that point: SQLite index names are unique DATABASE-WIDE, not
     * per-table, so creating an index under the final name while the original table's
     * identically-named index still lives would collide. Only AFTER {@see copyAndSwap()} has
     * dropped the original table (freeing those names) does {@see renamePlainIndexesToFinalNames()}
     * drop-and-recreate each index under its intended final name -- fixing the "_tmp_"-prefixed
     * name drift a naive rebuild would otherwise leave behind permanently (an explicitly
     * `CREATE INDEX`-ed name, unlike SQLite's own anonymous `sqlite_autoindex_*` constraints, does
     * NOT get rewritten by a later `RENAME TO`, confirmed empirically).
     */
    private function upSqlite(SchemaBuilderInterface $schema): void
    {
        $pdo = $schema->getConnection()->getPDO();
        $tmp = self::TABLE . '_attempt_lifecycle_tmp';

        $pdo->beginTransaction();
        try {
            $this->nullOutSupersededDuplicateReferences($pdo);

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
                $table->unique(['tenant_uuid', 'gateway', 'reference'], self::COMPOSITE_UNIQUE_INDEX);
                $table->index('tenant_uuid');
                $table->index('reference');
                $table->index(['payable_type', 'payable_id', 'status']);
                $table->index('gateway');
            });

            $this->copyAndSwap($schema, self::TABLE, $tmp);
            $this->renamePlainIndexesToFinalNames($schema, $tmp);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private function downSqlite(SchemaBuilderInterface $schema): void
    {
        $pdo = $schema->getConnection()->getPDO();
        $tmp = self::TABLE . '_attempt_lifecycle_revert_tmp';

        $pdo->beginTransaction();
        try {
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
            $this->renamePlainIndexesToFinalNames($schema, $tmp);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Drop-and-recreate each of the table's four plain indexes under its final,
     * `payment_intents_..._index` name (matching 007's own convention), replacing whatever
     * tmp-table-based name the builder auto-generated for it at creation time. Must run AFTER
     * {@see copyAndSwap()} has dropped the original table -- see {@see upSqlite()}'s docblock for
     * why these names cannot be used any earlier.
     */
    private function renamePlainIndexesToFinalNames(SchemaBuilderInterface $schema, string $tmpTable): void
    {
        $indexes = [
            'tenant_uuid_index' => '("tenant_uuid")',
            'reference_index' => '("reference")',
            'payable_type_payable_id_status_index' => '("payable_type", "payable_id", "status")',
            'gateway_index' => '("gateway")',
        ];

        foreach ($indexes as $suffix => $columnsSql) {
            $schema->addPendingOperation('DROP INDEX "' . $tmpTable . '_' . $suffix . '";');
            $schema->addPendingOperation(
                'CREATE INDEX "' . self::TABLE . '_' . $suffix . '" ON "' . self::TABLE . '" ' . $columnsSql . ';'
            );
        }
        $schema->execute();
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
     * The pre-flight dedup pass (see class docblock): for every group of rows sharing a non-NULL
     * `(tenant_uuid, gateway, reference)`, keep the newest row's `reference` and null out every
     * older row's. `ROW_NUMBER() OVER (PARTITION BY ...)` is standard SQL supported by both
     * SQLite (since 3.25.0 -- well below anything this codebase runs on) and PostgreSQL.
     */
    private function nullOutSupersededDuplicateReferences(\PDO $pdo): void
    {
        $pdo->exec(
            'UPDATE "' . self::TABLE . '" SET "reference" = NULL WHERE "id" IN ('
                . 'SELECT "id" FROM ('
                . 'SELECT "id", ROW_NUMBER() OVER ('
                . 'PARTITION BY "tenant_uuid", "gateway", "reference" '
                . 'ORDER BY "created_at" DESC, "id" DESC'
                . ') AS "rn" '
                . 'FROM "' . self::TABLE . '" WHERE "reference" IS NOT NULL'
                . ') AS "payment_intents_dedup_ranked" WHERE "rn" > 1'
                . ');'
        );
    }

    /**
     * Idempotency guard, portable across both supported drivers: are we already past this
     * migration? `reference`'s nullability can't be used as the signal -- see the class docblock,
     * it was already nullable before this migration ever ran -- so this checks for the one thing
     * that genuinely only exists post-migration: the new composite unique constraint itself, read
     * directly from the live catalog.
     *
     * PostgreSQL takes the named-index lookup (`upPostgres()` issues a real, explicitly named
     * `CREATE UNIQUE INDEX`). SQLite CANNOT be checked the same way: `$table->unique(...)` inside
     * a `createTable()` callback compiles to an ANONYMOUS inline `UNIQUE (...)` table constraint
     * regardless of any name passed to it (confirmed empirically -- `SQLiteSqlGenerator
     * ::createTable()` never emits an index's `name` for a unique clause), so SQLite assigns its
     * own internal `sqlite_autoindex_<table>_<n>` name instead. The only reliable check on SQLite
     * is therefore column-set based: does ANY unique index on this table cover exactly
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
