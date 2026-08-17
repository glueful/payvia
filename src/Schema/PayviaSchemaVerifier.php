<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/payvia (schema policy spec B7): each migration's receipt may
 * be adopted only when THAT migration's observable effect exists — created tables with their
 * load-bearing columns for the create migrations, the named dispatch index for 006, the added
 * columns for 009/011, and the composite unique (tenant_uuid, gateway, reference) for 012.
 * Parent-table existence alone never certifies the ALTER/index work. Unknown basenames are
 * never adoptable.
 */
final class PayviaSchemaVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'glueful/payvia';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        return [
            '001_CreatePaymentsTable.php',
            '002_CreateBillingPlansTable.php',
            '003_CreateInvoicesTable.php',
            '004_CreateProviderEventsTable.php',
            '005_CreateGatewaySubscriptionsTable.php',
            '006_AddProviderEventsDispatchIndex.php',
            '007_CreatePaymentIntentsTable.php',
            '008_CreatePayviaTransfersTable.php',
            '009_AddProviderEventDispatchClaimToken.php',
            '010_CreateCheckoutOriginations.php',
            '011_AddCheckoutOriginationReconciliationColumns.php',
            '012_AddPaymentIntentAttemptLifecycle.php',
        ];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return match ($migrationBasename) {
            '001_CreatePaymentsTable.php' => $this->tablesWithColumns($db, [
                'payments' => ['gateway', 'gateway_transaction_id', 'status', 'reference', 'payable_type', 'payable_id'],
            ]),
            '002_CreateBillingPlansTable.php' => $this->tablesWithColumns($db, [
                'billing_plans' => ['uuid', 'gateway', 'gateway_price_id', 'interval', 'status'],
            ]),
            '003_CreateInvoicesTable.php' => $this->tablesWithColumns($db, [
                'invoices' => ['number', 'status', 'billing_plan_uuid', 'payable_type', 'payable_id'],
            ]),
            '004_CreateProviderEventsTable.php' => $this->tablesWithColumns($db, [
                'provider_events' => ['gateway', 'provider_event_id', 'logical_event_key', 'dispatch_status', 'delivery_key'],
            ]),
            '005_CreateGatewaySubscriptionsTable.php' => $this->tablesWithColumns($db, [
                'gateway_subscriptions' => ['uuid', 'gateway', 'gateway_subscription_id', 'billing_plan_uuid', 'cancel_at_period_end'],
            ]),
            '006_AddProviderEventsDispatchIndex.php' => $this->indexCovers(
                $db,
                'provider_events',
                'idx_provider_events_dispatch',
                ['status', 'dispatch_status', 'dispatch_claimed_at'],
                unique: false
            ),
            '007_CreatePaymentIntentsTable.php' => $this->tablesWithColumns($db, [
                'payment_intents' => ['uuid', 'reference', 'idempotency_key', 'status', 'payable_type'],
            ]),
            '008_CreatePayviaTransfersTable.php' => $this->tablesWithColumns($db, [
                'payvia_transfers' => ['idempotency_key', 'gateway', 'destination_ref', 'provider_ref'],
            ]),
            '009_AddProviderEventDispatchClaimToken.php' => $this->tablesWithColumns($db, [
                'provider_events' => ['dispatch_claim_token'],
            ]),
            '010_CreateCheckoutOriginations.php' => $this->tablesWithColumns($db, [
                'subscription_checkout_originations' => ['uuid', 'subject_key', 'gateway', 'idempotency_key', 'request_fingerprint'],
                'subscription_checkout_subject_guards' => ['uuid', 'subject_key', 'state', 'origination_uuid', 'revision'],
            ]),
            '011_AddCheckoutOriginationReconciliationColumns.php' => $this->tablesWithColumns($db, [
                'subscription_checkout_originations' => ['reconciliation_resolution', 'reconciliation_note', 'reconciled_at'],
            ]),
            '012_AddPaymentIntentAttemptLifecycle.php' => $this->indexCovers(
                $db,
                'payment_intents',
                'payment_intents_tenant_uuid_gateway_reference_unique',
                ['tenant_uuid', 'gateway', 'reference'],
                unique: true
            ),
            default => false,
        };
    }

    /** @param array<string, list<string>> $expectations */
    private function tablesWithColumns(Connection $db, array $expectations): bool
    {
        $schema = $db->getSchemaBuilder();
        foreach ($expectations as $table => $columns) {
            if (!$schema->hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Driver-aware index proof: an index over EXACTLY the given columns (order-insensitive set
     * match), by name where the platform keeps the name, by composition where it may not
     * (SQLite's table-recreate path can express the unique as an inline constraint).
     *
     * @param list<string> $columns
     */
    private function indexCovers(Connection $db, string $table, string $name, array $columns, bool $unique): bool
    {
        if (!$db->getSchemaBuilder()->hasTable($table)) {
            return false;
        }
        $pdo = $db->getPDO();
        if ($db->getDriverName() === 'sqlite') {
            $list = $pdo->query('PRAGMA index_list("' . $table . '")');
            if ($list === false) {
                return false;
            }
            foreach ($list->fetchAll(\PDO::FETCH_ASSOC) as $index) {
                if ($unique && (int) $index['unique'] !== 1) {
                    continue;
                }
                $info = $pdo->query('PRAGMA index_info("' . $index['name'] . '")');
                if ($info === false) {
                    continue;
                }
                $covered = array_column($info->fetchAll(\PDO::FETCH_ASSOC), 'name');
                sort($covered);
                $expected = $columns;
                sort($expected);
                if ($covered === $expected) {
                    return true;
                }
            }
            return false;
        }
        if ($db->getDriverName() === 'pgsql') {
            $stmt = $pdo->prepare('SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?');
            $stmt->execute([$table, $name]);
            $def = $stmt->fetchColumn();
            if ($def === false) {
                return false;
            }
            foreach ($columns as $column) {
                if (!str_contains((string) $def, $column)) {
                    return false;
                }
            }
            return !$unique || str_contains((string) $def, 'UNIQUE');
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT column_name) FROM information_schema.statistics '
            . 'WHERE table_name = ? AND index_name = ?'
        );
        $stmt->execute([$table, $name]);
        return (int) $stmt->fetchColumn() === count($columns);
    }
}
