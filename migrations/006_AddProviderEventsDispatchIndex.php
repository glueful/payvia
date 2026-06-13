<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderInterface;

/**
 * Adds a composite dispatch index to provider_events.
 *
 * The relay scheduler's hot path (ProviderEventRepository::findDispatchable())
 * filters with `status = 'processed' AND (dispatch_status = ?
 * OR (dispatch_status = ? AND dispatch_claimed_at < ?))`. The base table only
 * has single-column indexes on `status` and `dispatch_status`, which cannot
 * serve that combined predicate efficiently. This composite index follows the
 * equality-then-range column order so the planner can seek on
 * (status, dispatch_status) and range-scan dispatch_claimed_at.
 *
 * The index is managed through the schema builder so the migration stays on
 * the same database-agnostic path as the rest of the extension schema.
 */
class AddProviderEventsDispatchIndex implements MigrationInterface
{
    private const TABLE = 'provider_events';
    private const INDEX = 'idx_provider_events_dispatch';

    /** @var array<int, string> */
    private const COLUMNS = ['status', 'dispatch_status', 'dispatch_claimed_at'];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        // Idempotency guard: there is no portable hasIndex() in the schema
        // builder, so drop any pre-existing index of this name first. Safe to
        // run on a fresh table (no-op) or a re-run (replaces the index).
        $schema->dropIndex(self::TABLE, self::INDEX);

        $schema->alterTable(self::TABLE, static function (TableBuilderInterface $table): void {
            $table->index(self::COLUMNS, self::INDEX);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $schema->dropIndex(self::TABLE, self::INDEX);
    }

    public function getDescription(): string
    {
        return 'Adds composite (status, dispatch_status, dispatch_claimed_at) index '
            . 'to provider_events for the relay dispatch hot path.';
    }
}
