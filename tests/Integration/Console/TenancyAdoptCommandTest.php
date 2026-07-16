<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Console;

use Glueful\Extensions\Payvia\Console\TenancyAdoptCommand;
use Glueful\Extensions\Payvia\Database\Migrations\CreateInvoicesTable;
use Glueful\Extensions\Payvia\Repositories\InvoiceRepository;
use Glueful\Extensions\Payvia\Tenancy\TenantAdopter;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TenancyAdoptCommandTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bind(TenantAdopter::class, new TenantAdopter());
    }

    public function testAdoptCommandRequiresTheTenantOption(): void
    {
        $command = new TenancyAdoptCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('--tenant option is required', $tester->getDisplay());
    }

    public function testAdoptCommandRekeysSentinelRows(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateInvoicesTable())->up($schema);
        (new InvoiceRepository($this->connection))->createInvoice($this->context, [
            'number' => 'INV-1',
            'amount' => 1000,
        ]);

        $command = new TenancyAdoptCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantAAAA01']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('tenantAAAA01', $tester->getDisplay());
        self::assertSame(
            1,
            $this->connection->table('invoices')->where('tenant_uuid', '=', 'tenantAAAA01')->count()
        );
    }

    public function testAdoptCommandReportsFailureWhenAdoptionIsRefused(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateInvoicesTable())->up($schema);
        $repo = new InvoiceRepository($this->connection);
        $repo->createInvoice($this->context, ['number' => 'INV-1', 'amount' => 1000]);
        (new TenantAdopter())->adopt($this->context, 'tenantAAAA01');

        $command = new TenancyAdoptCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantBBBB02']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('refused', $tester->getDisplay());
    }
}
