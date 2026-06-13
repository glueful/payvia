<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\SqlGeneratorInterface;

/**
 * Scope billing_plans.name uniqueness per gateway.
 *
 * The original 002 migration declared a global UNIQUE on `name`, which the
 * framework emits as an INLINE constraint inside CREATE TABLE. That form is
 * not portably droppable:
 *   - SQLite backs it with an anonymous `sqlite_autoindex_*` that "cannot be
 *     dropped" (index associated with a UNIQUE/PRIMARY KEY constraint).
 *   - PostgreSQL emits a named CONSTRAINT requiring ALTER TABLE ... DROP
 *     CONSTRAINT, but the framework's dropUnique()/dropIndex() only emit a
 *     plain DROP INDEX (wrong statement).
 *   - Only MySQL's named UNIQUE KEY is directly droppable.
 *
 * The only portable path is a full TABLE REBUILD: create a replacement table
 * with the intended index shape, copy every row, drop the original, and rename
 * the replacement into place. The new shape is index-equivalent to 002 EXCEPT
 * the global unique on `name` is replaced by a composite unique on
 * `(gateway, name)`.
 *
 * NULL semantics: `gateway` is nullable. On all three drivers NULLs do not
 * collide in a unique index, so multiple plans with a NULL gateway may share
 * the same `name` after this change. Two plans with the SAME non-NULL gateway
 * may not share a name; the same name across DIFFERENT gateways is allowed.
 *
 * down() restores the original 002 shape (global unique on `name`). It will
 * fail if the data has come to contain duplicate `name` values across
 * gateways — that is expected and standard for tightening-constraint
 * rollbacks.
 */
class ScopeBillingPlanNameUniquePerGateway implements MigrationInterface
{
    private const TABLE = 'billing_plans';
    private const TABLE_NEW = 'billing_plans_new';

    /**
     * Column list copied between old and new tables. Must match the column set
     * 002 produced (and the inverse rebuild in down()).
     *
     * @var array<int, string>
     */
    private const COLUMNS = [
        'id',
        'uuid',
        'name',
        'description',
        'amount',
        'currency',
        'interval',
        'trial_days',
        'gateway',
        'gateway_product_id',
        'gateway_price_id',
        'metadata',
        'status',
        'created_at',
        'updated_at',
    ];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        // Idempotency guard: if `gateway` already participates in a unique
        // index, the composite (gateway, name) unique is already in place and
        // up() has nothing to do.
        if ($this->columnHasUniqueIndex($schema, self::TABLE, 'gateway')) {
            return;
        }

        $this->rebuild(
            $schema,
            function ($table): void {
                $this->defineColumns($table);
                $table->unique('uuid');
                // Scoped uniqueness: a name is unique per (non-NULL) gateway.
                $table->unique(['gateway', 'name']);
            }
        );
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        // Idempotency guard: if `gateway` no longer participates in a unique
        // index, the original global-unique shape is already restored.
        if (!$this->columnHasUniqueIndex($schema, self::TABLE, 'gateway')) {
            return;
        }

        // NOTE: this rebuild reinstates the global UNIQUE on `name`. It will
        // fail if `billing_plans` now contains the same `name` under two
        // different gateways. That is acceptable and standard for rolling back
        // a uniqueness change.
        $this->rebuild(
            $schema,
            function ($table): void {
                $this->defineColumns($table);
                $table->unique('uuid');
                $table->unique('name');
            }
        );
    }

    /**
     * Rebuild billing_plans with a new index shape, preserving all rows.
     *
     * Steps: create billing_plans_new with the given definition, copy every
     * row across the shared column list, drop the original, rename the
     * replacement into place.
     *
     * @param callable(\Glueful\Database\Schema\Interfaces\TableBuilderInterface): void $define
     */
    private function rebuild(SchemaBuilderInterface $schema, callable $define): void
    {
        $generator = $this->resolveGenerator($schema);
        if ($generator === null) {
            // Without the concrete generator we cannot emit the copy/rename SQL
            // safely. Abort rather than leave a half-built table behind.
            return;
        }

        // Defensive: a stale billing_plans_new from a previously interrupted
        // run would break createTable(). Drop it first.
        $schema->dropTableIfExists(self::TABLE_NEW);

        // createTable() auto-executes the CREATE statement.
        $schema->createTable(self::TABLE_NEW, $define);

        // Copy rows. Explicit column list guarantees positional integrity and
        // avoids depending on column ordering.
        $columns = implode(', ', array_map([$generator, 'quoteIdentifier'], self::COLUMNS));
        $copySql = sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s;',
            $generator->quoteIdentifier(self::TABLE_NEW),
            $columns,
            $columns,
            $generator->quoteIdentifier(self::TABLE)
        );
        $schema->addPendingOperation($copySql);
        $schema->execute();

        // Drop the original and rename the replacement into place.
        $schema->dropTable(self::TABLE);
        $schema->execute();

        $schema->addPendingOperation($generator->renameTable(self::TABLE_NEW, self::TABLE));
        $schema->execute();
    }

    /**
     * Declare the billing_plans columns exactly as 002 did. Shared between the
     * forward and inverse rebuilds so the table shape stays identical apart
     * from the intended index change.
     *
     * @param \Glueful\Database\Schema\Interfaces\TableBuilderInterface $table
     */
    private function defineColumns($table): void
    {
        $table->bigInteger('id')->primary()->autoIncrement();
        $table->string('uuid', 12);

        $table->string('name', 100);
        $table->text('description')->nullable();

        // Pricing
        $table->decimal('amount', 12, 2);
        $table->string('currency', 10)->default('GHS');
        // Interval: monthly, yearly, one_time, etc.
        $table->string('interval', 20)->default('monthly');
        $table->integer('trial_days')->nullable();

        // Provider linkage for priced plans. Nullable means app-managed / not linked yet.
        $table->string('gateway', 50)->nullable();
        $table->string('gateway_product_id', 100)->nullable();
        $table->string('gateway_price_id', 100)->nullable();

        // App-owned metadata. Entitlements belong in glueful/subscriptions.
        $table->json('metadata')->nullable();

        $table->string('status', 20)->default('active');

        $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
        $table->timestamp('updated_at')->nullable();
    }

    /**
     * Cross-driver check: does $column participate in any UNIQUE index?
     *
     * The schema builder's getTableColumns() is unusable here because on SQLite
     * it deliberately skips `sqlite_autoindex_*` entries, and an INLINE UNIQUE
     * constraint (which is exactly what 002 and this rebuild emit) is backed by
     * one of those autoindexes. So we introspect the driver's index catalog
     * directly, keying off the connection driver name.
     *
     * Used as the idempotency pivot for both directions:
     *   - up():  `gateway` is NOT in any unique index in the 002 shape, and IS
     *            after this migration -> a true result means up() is done.
     *   - down(): inverse.
     */
    private function columnHasUniqueIndex(SchemaBuilderInterface $schema, string $table, string $column): bool
    {
        if (!$schema instanceof SchemaBuilder) {
            return false;
        }

        $pdo = $schema->getConnection()->getPDO();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'sqlite' => $this->sqliteColumnHasUniqueIndex($pdo, $table, $column),
            'mysql' => $this->mysqlColumnHasUniqueIndex($pdo, $table, $column),
            'pgsql' => $this->pgsqlColumnHasUniqueIndex($pdo, $table, $column),
            // Unknown driver: assume not present so up() proceeds (the rebuild
            // is itself safe to run) and down() no-ops rather than risk a wrong
            // rebuild.
            default => false,
        };
    }

    private function sqliteColumnHasUniqueIndex(\PDO $pdo, string $table, string $column): bool
    {
        $list = $pdo->query('PRAGMA index_list(' . $this->quoteSqliteIdentifier($table) . ');');
        if ($list === false) {
            return false;
        }

        foreach ($list->fetchAll(\PDO::FETCH_ASSOC) as $index) {
            if ((int) ($index['unique'] ?? 0) !== 1) {
                continue;
            }

            $info = $pdo->query('PRAGMA index_info(' . $this->quoteSqliteIdentifier((string) $index['name']) . ');');
            if ($info === false) {
                continue;
            }

            foreach ($info->fetchAll(\PDO::FETCH_ASSOC) as $col) {
                if (($col['name'] ?? null) === $column) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mysqlColumnHasUniqueIndex(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t '
            . 'AND COLUMN_NAME = :c AND NON_UNIQUE = 0 LIMIT 1'
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        return (bool) $stmt->fetchColumn();
    }

    private function pgsqlColumnHasUniqueIndex(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM pg_index ix '
            . 'JOIN pg_class t ON t.oid = ix.indrelid '
            . 'JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey) '
            . 'JOIN pg_namespace n ON n.oid = t.relnamespace '
            . 'WHERE t.relname = :t AND a.attname = :c '
            . 'AND ix.indisunique AND n.nspname = current_schema() LIMIT 1'
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        return (bool) $stmt->fetchColumn();
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Resolve the driver SQL generator.
     *
     * getSqlGenerator()/renameTable()/quoteIdentifier() live on the concrete
     * SchemaBuilder + generator rather than the interface the migration is
     * typed against, so narrow before using them.
     */
    private function resolveGenerator(SchemaBuilderInterface $schema): ?SqlGeneratorInterface
    {
        if (!$schema instanceof SchemaBuilder) {
            return null;
        }

        return $schema->getSqlGenerator();
    }

    public function getDescription(): string
    {
        return 'Scopes billing_plans.name uniqueness per gateway by rebuilding the table '
            . 'with a composite UNIQUE (gateway, name) in place of the global UNIQUE (name).';
    }
}
