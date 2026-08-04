<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderInterface;

/**
 * Task 9 (design spec §3.8): operator reconciliation writes its audit note into a NEW, dedicated
 * column pair rather than reusing `projection_reason` -- that column (together with
 * `projection_event_key`/`projection_outcome`) is the durable projection CONSUMER'S committed
 * receipt (design spec §3.6), and the reconciliation service's own NEVER-rule ("never rewrites a
 * committed rejected ack receipt") means it must not overwrite it. `projection_rejected` and
 * `late_settlement_conflict` rows -- the two terminal statuses reconciliation resolves without
 * changing `status` -- both already carry a committed `projection_reason` from the consumer's own
 * acknowledgement, so clobbering it here would destroy that history.
 *
 * `reconciliation_resolution` records which of the two explicit resolutions was applied
 * (`provider_confirmed_dead` | `provider_canceled_or_refunded`); `reconciliation_note` is the
 * operator-supplied, PII-free audit note (bounded exactly like `projection_reason`);
 * `reconciled_at` is the timestamp. All three stay nullable -- unresolved originations (the
 * overwhelming majority) never touch them -- and together they are the durable, retrievable
 * record an operator console reads back per design spec §3.8.
 *
 * Purely additive: nullable columns, existing rows unaffected, no existing reader touches them.
 */
class AddCheckoutOriginationReconciliationColumns implements MigrationInterface
{
    private const TABLE = 'subscription_checkout_originations';

    /** @var list<string> */
    private const COLUMNS = ['reconciliation_resolution', 'reconciliation_note', 'reconciled_at'];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE) || $schema->hasColumn(self::TABLE, 'reconciliation_resolution')) {
            return;
        }

        $schema->alterTable(self::TABLE, static function (TableBuilderInterface $table): void {
            $table->string('reconciliation_resolution', 40)->nullable();
            // Bounded exactly like `projection_reason` (design spec §3.3) -- the same
            // durable-note width, enforced by the service the same way.
            $table->string('reconciliation_note', 255)->nullable();
            $table->timestamp('reconciled_at')->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable(self::TABLE) || !$schema->hasColumn(self::TABLE, 'reconciliation_resolution')) {
            return;
        }

        // SQLite's schema builder cannot express DROP COLUMN through the fluent alter API (see
        // 009's identical rationale); SQLite itself has supported single-statement
        // `ALTER TABLE ... DROP COLUMN` since 3.35.0.
        if ($schema->getConnection()->getDriverName() === 'sqlite') {
            foreach (self::COLUMNS as $column) {
                $schema->addPendingOperation(
                    'ALTER TABLE "' . self::TABLE . '" DROP COLUMN "' . $column . '"'
                );
            }
            $schema->execute();
            return;
        }

        $schema->alterTable(self::TABLE, static function (TableBuilderInterface $table): void {
            foreach (self::COLUMNS as $column) {
                $table->dropColumn($column);
            }
        });
    }

    public function getDescription(): string
    {
        return 'Adds reconciliation_resolution/reconciliation_note/reconciled_at to '
            . 'subscription_checkout_originations for operator reconciliation (design spec §3.8).';
    }
}
