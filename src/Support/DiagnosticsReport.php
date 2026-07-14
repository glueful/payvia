<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Glueful\Extensions\Payvia\Services\UnresolvedSubscriptionOwnershipException;
use Glueful\Extensions\Payvia\Tenancy\FailClosedTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;

final class DiagnosticsReport
{
    /** @return array<string,mixed> */
    public static function build(ApplicationContext $context): array
    {
        $container = container($context);

        return [
            'contracts' => [
                'payvia_tenant_resolver' => self::contract(
                    $context,
                    PayviaTenantResolver::class,
                    SentinelTenantResolver::class
                ),
                'current_tenant_resolver' => self::contract($context, CurrentTenantResolver::class, null),
                'tenant_table_registry' => self::contract($context, TenantTableRegistry::class, null),
                'tenant_context_runner' => self::contract($context, TenantContextRunner::class, null),
            ],
            'tenancy' => [
                'resolver_mode' => self::resolverMode($context),
                'registered_tables' => self::tenantTables(),
                'sentinel_rows' => self::sentinelRows($context),
            ],
            'database' => [
                'payvia_tables_present' => self::tablesPresent($context),
            ],
            'container' => [
                'has_current_tenant_resolver' => $container->has(CurrentTenantResolver::class),
                'has_tenant_table_registry' => $container->has(TenantTableRegistry::class),
                'has_tenant_context_runner' => $container->has(TenantContextRunner::class),
            ],
            'webhooks' => [
                'unresolved_subscription_ownership_failures' => self::unresolvedSubscriptionOwnershipFailures(
                    $context
                ),
            ],
        ];
    }

    /**
     * `sentinel` when Payvia falls back to the single-store resolver (no host tenancy package,
     * or the provider hasn't bound `PayviaTenantResolver` at all yet -- e.g. a bare test
     * harness); `fail_closed` once the provider has wrapped a shared `CurrentTenantResolver`.
     */
    private static function resolverMode(ApplicationContext $context): string
    {
        $container = container($context);
        if (!$container->has(PayviaTenantResolver::class)) {
            return 'sentinel';
        }

        $resolver = $container->get(PayviaTenantResolver::class);

        return $resolver instanceof FailClosedTenantResolver ? 'fail_closed' : 'sentinel';
    }

    /**
     * @param class-string $contract
     * @param class-string|null $fallback
     * @return array{source: string, class: string|null}
     */
    private static function contract(ApplicationContext $context, string $contract, ?string $fallback): array
    {
        $container = container($context);
        if ($container->has($contract)) {
            $service = $container->get($contract);

            return ['source' => 'bound', 'class' => is_object($service) ? $service::class : get_debug_type($service)];
        }

        return ['source' => 'fallback', 'class' => $fallback];
    }

    /** @return array<string,int> */
    private static function sentinelRows(ApplicationContext $context): array
    {
        $rows = [];
        foreach (self::tenantTables() as $table) {
            if (!db($context)->getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $rows[$table] = (int) db($context)->table($table)->where('tenant_uuid', '=', '')->count();
        }

        return $rows;
    }

    /** @return array<string,bool> */
    private static function tablesPresent(ApplicationContext $context): array
    {
        $present = [];
        foreach (self::tenantTables() as $table) {
            $present[$table] = db($context)->getSchemaBuilder()->hasTable($table);
        }

        return $present;
    }

    /**
     * The real classification: `GatewaySubscriptionService::applyProviderEvent()` throws
     * `UnresolvedSubscriptionOwnershipException` when it cannot resolve a subscription's tenant
     * owner (no existing projection and no valid billing-plan correlation, or a metadata tenant
     * hint that disagrees with the resolved owner). `WebhookService` records that failure on the
     * provider event via its generic `\Throwable` handling, so the exception's own stable
     * `MARKER` prefix -- persisted verbatim into `provider_events.error` -- is what retries and
     * diagnostics both key off of, rather than guessing from event type + status alone.
     */
    private static function unresolvedSubscriptionOwnershipFailures(ApplicationContext $context): int
    {
        if (!db($context)->getSchemaBuilder()->hasTable('provider_events')) {
            return 0;
        }

        return (int) db($context)->table('provider_events')
            ->where('status', '=', 'failed')
            ->whereLike('error', UnresolvedSubscriptionOwnershipException::MARKER . '%')
            ->count();
    }

    /**
     * The five tenant-scoped domain tables. `provider_events` is deliberately excluded --
     * it is the global transport/inbox table and carries no `tenant_uuid` column.
     *
     * @return list<string>
     */
    public static function tenantTables(): array
    {
        return ['payments', 'billing_plans', 'invoices', 'gateway_subscriptions', 'payment_intents'];
    }
}
