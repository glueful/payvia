<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderInterface;

/**
 * Adds a nullable per-acquisition claim token to provider_events.
 *
 * Backs `LogicalDispatchLeaseRepositoryInterface` (owner-fenced logical dispatch leases):
 * `claimLogicalForDispatch()`/`reclaimStaleDispatching()` scope a claim by
 * (gateway, logical_event_key, dispatch_status) alone, so a stale former owner and a fresh
 * reclaimer can both believe they hold the same `dispatching` row. Stamping each acquisition
 * with an opaque token lets completion/release fence on the exact winning acquisition instead
 * of the shared status column.
 *
 * Purely additive: the column is nullable, existing rows are unaffected, and the legacy
 * claimLogicalForDispatch()/reclaimStaleDispatching()/markLogicalDispatched() callers never
 * read or write it.
 *
 * down() drops the column. SQLite's schema builder cannot express DROP COLUMN through the
 * fluent alter API (`SQLiteSqlGenerator::dropColumn()` only emits a comment -- confirmed by
 * reading the generator; modifying/dropping a column there requires recreating the table), but
 * SQLite itself has supported a real, single-statement `ALTER TABLE ... DROP COLUMN` since
 * 3.35.0, so that dialect issues it directly as a pending operation. MySQL/PostgreSQL keep the
 * fluent path.
 */
class AddProviderEventDispatchClaimToken implements MigrationInterface
{
    private const TABLE = 'provider_events';
    private const COLUMN = 'dispatch_claim_token';

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE) || $schema->hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        $schema->alterTable(self::TABLE, static function (TableBuilderInterface $table): void {
            $table->string(self::COLUMN, 64)->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE) || !$schema->hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if ($schema->getConnection()->getDriverName() === 'sqlite') {
            $schema->addPendingOperation(
                'ALTER TABLE "' . self::TABLE . '" DROP COLUMN "' . self::COLUMN . '"'
            );
            $schema->execute();
            return;
        }

        $schema->alterTable(self::TABLE, static function (TableBuilderInterface $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }

    public function getDescription(): string
    {
        return 'Adds nullable dispatch_claim_token to provider_events for owner-fenced dispatch leases.';
    }
}
