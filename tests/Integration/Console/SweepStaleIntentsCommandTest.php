<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Console;

use Glueful\Extensions\Payvia\Console\SweepStaleIntentsCommand;
use Glueful\Extensions\Payvia\Database\Migrations\AddPaymentIntentAttemptLifecycle;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Services\StaleIntentSweeper;
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

    private function command(): SweepStaleIntentsCommand
    {
        return new SweepStaleIntentsCommand($this->context->getContainer(), $this->context);
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

        $stamp = (new \DateTimeImmutable('-' . $daysAgo . ' days'))->format('Y-m-d H:i:s');
        $this->connection->table('payment_intents')->where(['uuid' => $uuid])->update([
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

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
