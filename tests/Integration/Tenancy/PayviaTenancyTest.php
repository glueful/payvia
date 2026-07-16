<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateInvoicesTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentsTable;
use Glueful\Extensions\Payvia\Repositories\BillingPlanRepository;
use Glueful\Extensions\Payvia\Repositories\InvoiceRepository;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Repositories\PaymentRepository;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Two real tenants (not the '' sentinel) proving invoices, payments, billing plans, and
 * payment intents never leak across a tenant boundary, and that business-key uniqueness
 * (invoice number, plan name, intent idempotency key) is per-tenant, not global. Follows
 * commerce's RefundTenancyTest "fixedTenant" convention: a `PayviaTenantResolver` fake that
 * always returns a fixed tenant, injected directly into each repository under test.
 */
final class PayviaTenancyTest extends PayviaTestCase
{
    private const TENANT_A = 'tenantAAAA01';
    private const TENANT_B = 'tenantBBBB02';

    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->connection->getSchemaBuilder();
        (new CreatePaymentsTable())->up($schema);
        (new CreateInvoicesTable())->up($schema);
        (new CreateBillingPlansTable())->up($schema);
        (new CreatePaymentIntentsTable())->up($schema);
    }

    public function testInvoicesAreFullyIsolatedPerTenantAcrossCrud(): void
    {
        $repoA = $this->invoiceRepo(self::TENANT_A);
        $repoB = $this->invoiceRepo(self::TENANT_B);

        // Same invoice number reused by both tenants: the composite
        // (tenant_uuid, number) unique permits this.
        $uuidA = $repoA->createInvoice($this->context, ['number' => 'INV-1', 'amount' => 1000, 'currency' => 'GHS']);
        $uuidB = $repoB->createInvoice($this->context, ['number' => 'INV-1', 'amount' => 2000, 'currency' => 'GHS']);

        self::assertNotSame($uuidA, $uuidB);

        $listA = $repoA->list($this->context);
        $listB = $repoB->list($this->context);
        self::assertCount(1, $listA);
        self::assertCount(1, $listB);
        self::assertSame(1000, (int) $listA[0]['amount']);
        self::assertSame(2000, (int) $listB[0]['amount']);

        // Cross-tenant: tenant B cannot mark tenant A's invoice paid/canceled -- the
        // non-revealing not-found result is indistinguishable from an unknown uuid.
        self::assertFalse($repoB->markPaid($this->context, $uuidA));
        self::assertFalse($repoB->markCanceled($this->context, $uuidA));
        self::assertSame('draft', $repoA->list($this->context)[0]['status'] ?? null);

        // Same tenant: works.
        self::assertTrue($repoA->markPaid($this->context, $uuidA));
        self::assertSame('paid', $repoA->list($this->context)[0]['status']);
    }

    public function testPaymentsLookupIsScopedByTenantEvenWithAGloballyUniqueReference(): void
    {
        $repoA = $this->paymentRepo(self::TENANT_A);
        $repoB = $this->paymentRepo(self::TENANT_B);

        $repoA->createPayment($this->context, [
            'gateway' => 'paystack',
            'reference' => 'REF-TENANT-A-1',
            'amount' => 1000,
            'currency' => 'GHS',
            'status' => 'success',
        ]);

        self::assertNotNull($repoA->findByReference($this->context, 'REF-TENANT-A-1'));
        // Tenant B cannot see tenant A's payment merely by knowing the (globally unique)
        // reference -- cross-tenant lookup is the same non-revealing not-found as unknown.
        self::assertNull($repoB->findByReference($this->context, 'REF-TENANT-A-1'));
        self::assertFalse($repoB->updateByReference($this->context, 'REF-TENANT-A-1', ['status' => 'failed']));

        // Tenant A's own row is untouched by tenant B's failed update attempt.
        self::assertSame('success', $repoA->findByReference($this->context, 'REF-TENANT-A-1')['status']);
    }

    public function testBillingPlanNamesAreUniquePerTenantNotGlobally(): void
    {
        $repoA = $this->planRepo(self::TENANT_A);
        $repoB = $this->planRepo(self::TENANT_B);

        $planData = ['name' => 'Pro', 'amount' => 5000, 'currency' => 'GHS', 'interval' => 'monthly'];
        $uuidA = $repoA->createPlan($this->context, $planData + ['gateway' => 'paystack']);
        // Two tenants may each have a "Pro" plan under the same gateway: the composite
        // (tenant_uuid, gateway, name) unique permits this.
        $uuidB = $repoB->createPlan($this->context, $planData + ['gateway' => 'paystack', 'amount' => 7000]);

        self::assertNotSame($uuidA, $uuidB);
        self::assertCount(1, $repoA->list($this->context));
        self::assertCount(1, $repoB->list($this->context));

        // Cross-tenant: tenant B cannot update/disable tenant A's plan.
        self::assertFalse($repoB->updatePlan($this->context, $uuidA, ['name' => 'Hijacked']));
        self::assertFalse($repoB->disable($this->context, $uuidA));
        self::assertSame('active', $repoA->list($this->context)[0]['status']);
    }

    public function testPaymentIntentOpenCreateCloseIsolatesByTenant(): void
    {
        $repoA = $this->intentRepo(self::TENANT_A);
        $repoB = $this->intentRepo(self::TENANT_B);

        // Two tenants may open the same logical {payable_type}:{payable_id} simultaneously.
        self::assertTrue($repoA->createOpen($this->context, $this->intentRow('invoice', 'shared-payable', 'ref-a')));
        self::assertTrue($repoB->createOpen($this->context, $this->intentRow('invoice', 'shared-payable', 'ref-b')));

        $openA = $repoA->findOpen($this->context, 'invoice', 'shared-payable');
        $openB = $repoB->findOpen($this->context, 'invoice', 'shared-payable');
        self::assertIsArray($openA);
        self::assertIsArray($openB);
        self::assertSame('ref-a', $openA['reference']);
        self::assertSame('ref-b', $openB['reference']);

        // Tenant B cannot close tenant A's open intent -- close() cannot reach a row it
        // does not own, so tenant A's intent remains open (silent no-op, non-revealing).
        $repoB->close($this->context, (string) $openA['uuid'], 'ref-a');
        self::assertNotNull($repoA->findOpen($this->context, 'invoice', 'shared-payable'));

        // Tenant A can close its own.
        $repoA->close($this->context, (string) $openA['uuid'], 'ref-a');
        self::assertNull($repoA->findOpen($this->context, 'invoice', 'shared-payable'));

        // Tenant B's own intent is unaffected throughout.
        self::assertNotNull($repoB->findOpen($this->context, 'invoice', 'shared-payable'));
    }

    public function testSingleStoreSentinelBehaviorIsByteIdenticalWithoutAResolver(): void
    {
        // No resolver supplied -- defaults to SentinelTenantResolver ('' everywhere), matching
        // pre-tenancy single-store behavior exactly.
        $repo = new InvoiceRepository($this->connection);

        $uuid = $repo->createInvoice($this->context, ['number' => 'INV-SENTINEL', 'amount' => 1000]);
        $row = $this->connection->table('invoices')->where(['uuid' => $uuid])->first();

        self::assertSame('', $row['tenant_uuid']);
        self::assertCount(1, $repo->list($this->context));
    }

    private function invoiceRepo(string $tenant): InvoiceRepository
    {
        return new InvoiceRepository($this->connection, resolver: $this->fixedTenant($tenant));
    }

    private function paymentRepo(string $tenant): PaymentRepository
    {
        return new PaymentRepository($this->connection, resolver: $this->fixedTenant($tenant));
    }

    private function planRepo(string $tenant): BillingPlanRepository
    {
        return new BillingPlanRepository($this->connection, resolver: $this->fixedTenant($tenant));
    }

    private function intentRepo(string $tenant): PaymentIntentRepository
    {
        return new PaymentIntentRepository($this->connection, resolver: $this->fixedTenant($tenant));
    }

    /** @return array<string,mixed> */
    private function intentRow(string $type, string $id, string $reference): array
    {
        return [
            'payable_type' => $type,
            'payable_id' => $id,
            'gateway' => 'paystack',
            'reference' => $reference,
            'amount' => 4999,
            'currency' => 'GHS',
        ];
    }

    private function fixedTenant(string $tenant): PayviaTenantResolver
    {
        return new class ($tenant) implements PayviaTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }
}
