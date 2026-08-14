<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Console;

use Glueful\Extensions\Payvia\Console\SweepStaleIntentsCommand;
use Glueful\Extensions\Payvia\Database\Migrations\AddPaymentIntentAttemptLifecycle;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Services\StaleIntentSweeper;
use Glueful\Extensions\Payvia\Tests\Support\FixedTenantResolver;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SweepStaleIntentsCommandTest extends PayviaTestCase
{
    private PaymentIntentRepository $intents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePaymentIntentsTable());
        $this->runMigration(new AddPaymentIntentAttemptLifecycle());
        $this->context->mergeConfigDefaults('payvia', require __DIR__ . '/../../../config/payvia.php');

        $this->intents = new PaymentIntentRepository($this->connection);
        $this->bind(StaleIntentSweeper::class, new StaleIntentSweeper($this->intents));
    }

    public function testTheCommandRetiresStaleIntentsAndReportsTheCount(): void
    {
        $stale = $this->seed('ord-cli-stale', 40);
        $fresh = $this->seed('ord-cli-fresh', 2);

        $tester = new CommandTester($this->command());
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('1', $tester->getDisplay());
        self::assertStringContainsString('30', $tester->getDisplay(), 'the effective window is reported');
        self::assertSame('failed', $this->statusOf($stale));
        self::assertSame('open', $this->statusOf($fresh));
    }

    public function testTheLimitOptionCapsOneRun(): void
    {
        $uuids = [];
        foreach (range(1, 3) as $n) {
            $uuids[] = $this->seed('ord-cli-limit-' . $n, 40);
        }

        $tester = new CommandTester($this->command());
        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => 1]));

        $statuses = array_map(fn(string $uuid): string => $this->statusOf($uuid), $uuids);
        self::assertSame(['failed', 'open', 'open'], $statuses);
    }

    public function testTheStaleAfterDaysOptionOverridesConfigAndIsClamped(): void
    {
        $uuid = $this->seed('ord-cli-window', 3);

        $tester = new CommandTester($this->command());
        self::assertSame(Command::SUCCESS, $tester->execute(['--stale-after-days' => 0]));

        self::assertSame('failed', $this->statusOf($uuid), '0 clamps to 1 day, so a 3-day-old row is stale');
        self::assertStringContainsString('1', $tester->getDisplay());
    }

    // ==================================================================
    // Option hygiene: a typo in a crontab must be loud
    // ==================================================================

    public function testANonNumericWindowIsAnErrorNotASilentOneDaySweep(): void
    {
        // `(int) 'daily'` is 0, and 0 clamps to a ONE-day window -- thirty times more aggressive
        // than the default, retiring checkouts that started yesterday. Refuse instead.
        $uuid = $this->seed('ord-cli-bad-window', 3);

        $tester = new CommandTester($this->command());
        self::assertSame(Command::FAILURE, $tester->execute(['--stale-after-days' => 'daily']));
        self::assertStringContainsString('must be an integer', $tester->getDisplay());
        self::assertSame('open', $this->statusOf($uuid), 'nothing was swept');
    }

    public function testAnEmptyWindowValueIsAnErrorToo(): void
    {
        $uuid = $this->seed('ord-cli-empty-window', 3);

        $tester = new CommandTester($this->command());
        self::assertSame(Command::FAILURE, $tester->execute(['--stale-after-days' => '']));
        self::assertSame('open', $this->statusOf($uuid));
    }

    public function testANonNumericLimitIsAnErrorNotASilentNoOp(): void
    {
        // `(int) 'all'` is 0, and a batch cap of 0 is a sweep that reports success and does
        // nothing, forever.
        $uuid = $this->seed('ord-cli-bad-limit', 40);

        $tester = new CommandTester($this->command());
        self::assertSame(Command::FAILURE, $tester->execute(['--limit' => 'all']));
        self::assertStringContainsString('must be an integer', $tester->getDisplay());
        self::assertSame('open', $this->statusOf($uuid));
    }

    public function testANonPositiveLimitIsRefused(): void
    {
        $uuid = $this->seed('ord-cli-zero-limit', 40);

        $tester = new CommandTester($this->command());
        self::assertSame(Command::FAILURE, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('at least 1', $tester->getDisplay());
        self::assertSame('open', $this->statusOf($uuid));
    }

    // ==================================================================
    // Tenancy: the command must be operable on a tenancy-enabled host
    // ==================================================================

    public function testTheTenantOptionReachesTheResolverAndScopesTheSweep(): void
    {
        // A command has no request, so a tenancy-enabled host's FailClosedTenantResolver refuses
        // every unqualified CLI run. `--tenant` is how a scheduled sweep names its partition; it
        // must bypass the container-bound sweeper entirely and scope every read and CAS to the
        // named tenant.
        $tenanted = new PaymentIntentRepository($this->connection, resolver: new FixedTenantResolver('tenantAAAA01'));
        $theirs = $this->seedWith($tenanted, 'ord-cli-tenant', 40);
        $sentinel = $this->seed('ord-cli-sentinel', 40);

        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--tenant' => 'tenantAAAA01']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('tenantAAAA01', $tester->getDisplay());
        self::assertSame('failed', $this->statusOf($theirs));
        self::assertSame(
            'open',
            $this->statusOf($sentinel),
            'the sentinel partition is another tenant and is never swept by a --tenant run'
        );
    }

    public function testWithoutTheTenantOptionTheContainerBoundSweeperIsUsed(): void
    {
        $tenanted = new PaymentIntentRepository($this->connection, resolver: new FixedTenantResolver('tenantAAAA01'));
        $theirs = $this->seedWith($tenanted, 'ord-cli-other-tenant', 40);
        $sentinel = $this->seed('ord-cli-default', 40);

        $tester = new CommandTester($this->command());
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertSame('failed', $this->statusOf($sentinel));
        self::assertSame('open', $this->statusOf($theirs));
    }

    private function command(): SweepStaleIntentsCommand
    {
        return new SweepStaleIntentsCommand($this->context->getContainer(), $this->context);
    }

    private function seedWith(PaymentIntentRepository $intents, string $payableId, int $daysAgo): string
    {
        $claim = $intents->claimAttempt($this->context, [
            'payable_type' => 'commerce_order',
            'payable_id' => $payableId,
            'gateway' => 'fake',
            'amount' => 4999,
            'currency' => 'GHS',
        ]);
        $uuid = (string) $claim['uuid'];
        self::assertTrue($intents->markOpen($this->context, $uuid, 'sess_' . $uuid));
        $this->backdate($uuid, $daysAgo);

        return $uuid;
    }

    private function backdate(string $uuid, int $daysAgo): void
    {
        $stamp = (new \DateTimeImmutable('-' . $daysAgo . ' days'))->format('Y-m-d H:i:s');
        $this->connection->table('payment_intents')->where(['uuid' => $uuid])->update([
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }

    private function seed(string $payableId, int $daysAgo): string
    {
        $claim = $this->intents->claimAttempt($this->context, [
            'payable_type' => 'commerce_order',
            'payable_id' => $payableId,
            'gateway' => 'fake',
            'amount' => 4999,
            'currency' => 'GHS',
        ]);
        $uuid = (string) $claim['uuid'];
        self::assertTrue($this->intents->markOpen($this->context, $uuid, 'sess_' . $uuid));
        $this->backdate($uuid, $daysAgo);

        return $uuid;
    }

    private function statusOf(string $uuid): string
    {
        $row = $this->connection->table('payment_intents')
            ->select(['status'])
            ->where(['uuid' => $uuid])
            ->first();
        self::assertIsArray($row);

        return (string) $row['status'];
    }
}
