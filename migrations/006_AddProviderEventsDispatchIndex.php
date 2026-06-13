<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\SqlGeneratorInterface;

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
 * Implementation note: the index is created/dropped through the driver's SQL
 * generator (createIndex/dropIndex) rather than the alterTable()->index()
 * builder. The builder validates indexed columns against its (empty) column
 * set when altering an existing table and would reject an index over
 * pre-existing columns, and its dropIndex() path does not currently emit SQL.
 * The generator path emits correct, driver-specific statements for SQLite,
 * MySQL and PostgreSQL while staying within the schema-builder abstraction.
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

        $generator = $this->resolveGenerator($schema);
        if ($generator === null) {
            return;
        }

        // Idempotency guard: there is no portable hasIndex() in the schema
        // builder, so drop any pre-existing index of this name first. Safe to
        // run on a fresh table (no-op) or a re-run (replaces the index).
        $this->dropIndexIfExists($schema, $generator);

        $sql = $generator->createIndex(
            self::TABLE,
            new IndexDefinition(
                columns: self::COLUMNS,
                name: self::INDEX,
                type: 'index',
            )
        );

        $schema->addPendingOperation($sql);
        $schema->execute();
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $generator = $this->resolveGenerator($schema);
        if ($generator === null) {
            return;
        }

        $this->dropIndexIfExists($schema, $generator);
    }

    /**
     * Resolve the driver SQL generator.
     *
     * getSqlGenerator() lives on the concrete SchemaBuilder rather than the
     * interface the migration is typed against, so narrow before using it.
     */
    private function resolveGenerator(SchemaBuilderInterface $schema): ?SqlGeneratorInterface
    {
        if (!$schema instanceof SchemaBuilder) {
            return null;
        }

        return $schema->getSqlGenerator();
    }

    /**
     * Drop the dispatch index, tolerating its absence.
     *
     * The driver dropIndex() statements do not emit IF EXISTS, so a missing
     * index would raise. We swallow that to keep up()/down() idempotent,
     * mirroring the framework's own guarded SchemaBuilder::dropIndex().
     */
    private function dropIndexIfExists(SchemaBuilderInterface $schema, SqlGeneratorInterface $generator): void
    {
        $sql = $generator->dropIndex(self::TABLE, self::INDEX);

        try {
            $schema->addPendingOperation($sql);
            $schema->execute();
        } catch (\Throwable) {
            // Index did not exist; clear the failed pending op (execute() does
            // not reset on failure) so a subsequent createIndex runs cleanly.
            $schema->reset();
        }
    }

    public function getDescription(): string
    {
        return 'Adds composite (status, dispatch_status, dispatch_claimed_at) index '
            . 'to provider_events for the relay dispatch hot path.';
    }
}
