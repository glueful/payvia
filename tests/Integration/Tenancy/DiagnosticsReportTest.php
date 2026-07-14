<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Payvia\Database\Migrations\CreateInvoicesTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Repositories\InvoiceRepository;
use Glueful\Extensions\Payvia\Services\UnresolvedSubscriptionOwnershipException;
use Glueful\Extensions\Payvia\Support\DiagnosticsReport;
use Glueful\Extensions\Payvia\Tenancy\FailClosedTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class DiagnosticsReportTest extends PayviaTestCase
{
    public function testReportsSentinelModeAndTablesWhenNoResolverIsBound(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateInvoicesTable())->up($schema);
        (new InvoiceRepository($this->connection))->createInvoice($this->context, [
            'number' => 'INV-1',
            'amount' => 1000,
        ]);

        $report = DiagnosticsReport::build($this->context);

        self::assertSame('sentinel', $report['tenancy']['resolver_mode']);
        self::assertSame(
            ['payments', 'billing_plans', 'invoices', 'gateway_subscriptions', 'payment_intents'],
            $report['tenancy']['registered_tables']
        );
        self::assertSame(1, $report['tenancy']['sentinel_rows']['invoices']);
        self::assertFalse($report['container']['has_current_tenant_resolver']);
    }

    public function testReportsFailClosedModeWhenAPayviaTenantResolverIsBound(): void
    {
        $shared = new class implements CurrentTenantResolver {
            public function tenantUuid(ApplicationContext $context): string
            {
                return 'tenantAAAA01';
            }
        };
        $this->bind(CurrentTenantResolver::class, $shared);
        $this->bind(PayviaTenantResolver::class, new FailClosedTenantResolver($shared));

        $report = DiagnosticsReport::build($this->context);

        self::assertSame('fail_closed', $report['tenancy']['resolver_mode']);
        self::assertTrue($report['container']['has_current_tenant_resolver']);
    }

    /**
     * The real classification keys off the typed exception's stable message marker in
     * `provider_events.error`, not event type + status alone -- a failed subscription-type
     * event whose failure came from something else entirely (row B) must NOT be counted, while
     * a non-subscription-type row carrying the marker (row C) still would be, proving this is
     * no longer the Task 4 event-type heuristic.
     */
    public function testUnresolvedSubscriptionOwnershipFailuresCountsTheTypedExceptionMarkerOnly(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateProviderEventsTable())->up($schema);

        $markerMessage = UnresolvedSubscriptionOwnershipException::noPlanCorrelation(
            'stripe',
            'sub_1',
            null
        )->getMessage();

        // Row A: subscription-type event that failed with the typed ownership exception -- counts.
        $this->connection->table('provider_events')->insert([
            'uuid' => 'evtAAAAAAAAA',
            'gateway' => 'stripe',
            'source' => 'webhook',
            'delivery_key' => 'd1',
            'logical_event_key' => 'l1',
            'type' => EventType::SUBSCRIPTION_UPDATED,
            'status' => 'failed',
            'error' => $markerMessage,
        ]);
        // Row B: same subscription-type event, but failed for an unrelated reason -- must NOT
        // count under the real classification (it would have under the old type+status heuristic).
        $this->connection->table('provider_events')->insert([
            'uuid' => 'evtBBBBBBBBB',
            'gateway' => 'stripe',
            'source' => 'webhook',
            'delivery_key' => 'd2',
            'logical_event_key' => 'l2',
            'type' => EventType::SUBSCRIPTION_UPDATED,
            'status' => 'failed',
            'error' => 'gateway request timed out',
        ]);
        // Row C: a non-subscription event type without an error at all -- must NOT count.
        $this->connection->table('provider_events')->insert([
            'uuid' => 'evtCCCCCCCCC',
            'gateway' => 'stripe',
            'source' => 'webhook',
            'delivery_key' => 'd3',
            'logical_event_key' => 'l3',
            'type' => EventType::PAYMENT_SUCCEEDED,
            'status' => 'failed',
        ]);

        $report = DiagnosticsReport::build($this->context);

        self::assertSame(1, $report['webhooks']['unresolved_subscription_ownership_failures']);
    }
}
