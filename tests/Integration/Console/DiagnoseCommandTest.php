<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Console;

use Glueful\Extensions\Payvia\Console\DiagnoseCommand;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentsTable;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DiagnoseCommandTest extends PayviaTestCase
{
    public function testDiagnoseReportsSentinelModeAndSentinelRowCounts(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreatePaymentsTable())->up($schema);
        $this->connection->table('payments')->insert([
            'uuid' => 'payAAAAAAAAA',
            'gateway' => 'paystack',
            'reference' => 'ref-1',
            'amount' => 1000,
        ]);

        $command = new DiagnoseCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('sentinel', $display);
        self::assertStringContainsString('payments', $display);
        self::assertStringContainsString('Unresolved subscription-ownership failures: 0', $display);
    }
}
