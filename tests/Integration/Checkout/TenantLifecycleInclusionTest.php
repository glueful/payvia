<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Checkout;

use Glueful\Extensions\Payvia\Console\TenancyAdoptCommand;
use Glueful\Extensions\Payvia\Database\Migrations\CreateCheckoutOriginations;
use Glueful\Extensions\Payvia\Support\DiagnosticsReport;
use Glueful\Extensions\Payvia\Tenancy\TenantAdopter;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Task 9 (workspace self-serve checkout, design spec §3.8): tenant lifecycle inclusion. Both
 * checkout tables (`subscription_checkout_originations`, `subscription_checkout_subject_guards`)
 * must be first-class members of every tenant inventory/adoption surface Payvia exposes --
 * {@see DiagnosticsReport::tenantTables()}, {@see TenantAdopter}, and the
 * `payvia:tenancy:adopt` console command -- exactly like the original five domain tables, and in
 * FK-safe order (child `subscription_checkout_originations` before parent
 * `subscription_checkout_subject_guards`, mirroring 010's own migration `down()` drop order --
 * see the ledger note on {@see \Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository::block()}).
 */
final class TenantLifecycleInclusionTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateCheckoutOriginations());
    }

    // ==================================================================
    // DiagnosticsReport::tenantTables() -- inventory + FK-safe order
    // ==================================================================

    public function testTenantTablesIncludesBothCheckoutTablesWithOriginationsBeforeGuards(): void
    {
        $tables = DiagnosticsReport::tenantTables();

        self::assertContains('subscription_checkout_originations', $tables);
        self::assertContains('subscription_checkout_subject_guards', $tables);

        $originationsIndex = array_search('subscription_checkout_originations', $tables, true);
        $guardsIndex = array_search('subscription_checkout_subject_guards', $tables, true);
        self::assertLessThan(
            $guardsIndex,
            $originationsIndex,
            'the child originations table must be listed before the parent guard table (FK-safe order)'
        );

        // The original five tables are still present and untouched.
        self::assertSame(
            ['payments', 'billing_plans', 'invoices', 'gateway_subscriptions', 'payment_intents'],
            array_slice($tables, 0, 5)
        );
    }

    public function testDiagnosticsReportsBothCheckoutTablesAsPresentOnceMigrated(): void
    {
        $report = DiagnosticsReport::build($this->context);

        self::assertTrue($report['database']['payvia_tables_present']['subscription_checkout_originations']);
        self::assertTrue($report['database']['payvia_tables_present']['subscription_checkout_subject_guards']);
    }

    // ==================================================================
    // TenantAdopter -- rekeys sentinel rows in BOTH checkout tables
    // ==================================================================

    public function testAdoptRekeysSentinelRowsInBothCheckoutTables(): void
    {
        $this->seedOrigination('origAAAAAAAA', '');
        $this->seedOrigination('origBBBBBBBB', '');
        $this->seedGuard('subject-a', '');

        $result = (new TenantAdopter())->adopt($this->context, 'tenantAAAA01');

        self::assertSame(2, $result['tables']['subscription_checkout_originations']);
        self::assertSame(1, $result['tables']['subscription_checkout_subject_guards']);

        self::assertSame(
            0,
            $this->connection->table('subscription_checkout_originations')
                ->where('tenant_uuid', '=', '')
                ->count()
        );
        self::assertSame(
            2,
            $this->connection->table('subscription_checkout_originations')
                ->where('tenant_uuid', '=', 'tenantAAAA01')
                ->count()
        );
        self::assertSame(
            1,
            $this->connection->table('subscription_checkout_subject_guards')
                ->where('tenant_uuid', '=', 'tenantAAAA01')
                ->count()
        );
    }

    /**
     * `TenantAdopter::adopt()` processes tables in {@see DiagnosticsReport::tenantTables()}'s
     * own order -- proving the result array's own key order puts
     * `subscription_checkout_originations` before `subscription_checkout_subject_guards`, not
     * just that both happen to be present.
     */
    public function testAdoptProcessesOriginationsBeforeGuardsInFkSafeOrder(): void
    {
        $this->seedOrigination('origAAAAAAAA', '');
        $this->seedGuard('subject-a', '');

        $result = (new TenantAdopter())->adopt($this->context, 'tenantAAAA01');

        $keys = array_keys($result['tables']);
        $originationsIndex = array_search('subscription_checkout_originations', $keys, true);
        $guardsIndex = array_search('subscription_checkout_subject_guards', $keys, true);

        self::assertIsInt($originationsIndex);
        self::assertIsInt($guardsIndex);
        self::assertLessThan($guardsIndex, $originationsIndex);
    }

    public function testAdoptRefusesWhenACheckoutTableAlreadyContainsMixedTenantData(): void
    {
        $this->seedOrigination('origAAAAAAAA', 'tenantOTHER1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/subscription_checkout_originations/');
        (new TenantAdopter())->adopt($this->context, 'tenantAAAA01');
    }

    public function testAdoptIsANoOpForCheckoutTablesWhenNoSentinelRowsExist(): void
    {
        $this->seedOrigination('origAAAAAAAA', 'tenantAAAA01');

        $result = (new TenantAdopter())->adopt($this->context, 'tenantAAAA01');

        self::assertSame(0, $result['tables']['subscription_checkout_originations']);
        self::assertSame(
            1,
            $this->connection->table('subscription_checkout_originations')
                ->where('tenant_uuid', '=', 'tenantAAAA01')
                ->count()
        );
    }

    // ==================================================================
    // payvia:tenancy:adopt console command -- end to end through both checkout tables
    // ==================================================================

    public function testTenancyAdoptCommandReportsCheckoutTableRowCounts(): void
    {
        $this->bind(TenantAdopter::class, new TenantAdopter());
        $this->seedOrigination('origAAAAAAAA', '');
        $this->seedGuard('subject-a', '');

        $command = new TenancyAdoptCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantAAAA01']);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('subscription_checkout_originations', $display);
        self::assertStringContainsString('subscription_checkout_subject_guards', $display);

        self::assertSame(
            1,
            $this->connection->table('subscription_checkout_originations')
                ->where('tenant_uuid', '=', 'tenantAAAA01')
                ->count()
        );
        self::assertSame(
            1,
            $this->connection->table('subscription_checkout_subject_guards')
                ->where('tenant_uuid', '=', 'tenantAAAA01')
                ->count()
        );
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private static int $seq = 0;

    private function seedOrigination(string $uuid, string $tenantUuid): void
    {
        self::$seq++;
        $this->connection->table('subscription_checkout_originations')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenantUuid,
            'subject_key' => 'subject-' . self::$seq,
            'gateway' => 'stripe',
            'provider_plan_identifier' => 'plan_' . self::$seq,
            'idempotency_key' => 'idem-' . self::$seq . '-' . bin2hex(random_bytes(4)),
            'request_fingerprint' => str_repeat('a', 64),
            'return_url' => 'https://shop.example.test/return',
            'cancel_url' => 'https://shop.example.test/cancel',
            'status' => 'preparing',
            'live' => true,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function seedGuard(string $subjectKey, string $tenantUuid): void
    {
        self::$seq++;
        $this->connection->table('subscription_checkout_subject_guards')->insert([
            'uuid' => 'grd' . str_pad((string) self::$seq, 9, '0', STR_PAD_LEFT),
            'tenant_uuid' => $tenantUuid,
            'subject_key' => $subjectKey,
            'state' => 'open',
            'revision' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
