<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Tenancy;

use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateInvoicesTable;
use Glueful\Extensions\Payvia\Repositories\InvoiceRepository;
use Glueful\Extensions\Payvia\Tenancy\TenantAdopter;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class TenantAdopterTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->connection->getSchemaBuilder();
        (new CreateInvoicesTable())->up($schema);
        (new CreateBillingPlansTable())->up($schema);
    }

    public function testAdoptRekeysSentinelRowsAndRefusesMixedData(): void
    {
        $repo = new InvoiceRepository($this->connection);
        $repo->createInvoice($this->context, ['number' => 'INV-1', 'amount' => 1000]);
        $repo->createInvoice($this->context, ['number' => 'INV-2', 'amount' => 2000]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantAAAA01');

        self::assertSame(2, $result['tables']['invoices']);
        self::assertSame(0, $this->connection->table('invoices')->where('tenant_uuid', '=', '')->count());
        self::assertSame(
            2,
            $this->connection->table('invoices')->where('tenant_uuid', '=', 'tenantAAAA01')->count()
        );

        // A second tenant now finding non-sentinel, non-matching rows must refuse.
        $this->expectException(\RuntimeException::class);
        (new TenantAdopter())->adopt($this->context, 'tenantBBBB02');
    }

    public function testAdoptRejectsAnEmptyTenantUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TenantAdopter())->adopt($this->context, '   ');
    }

    public function testAdoptIsANoOpWhenNoSentinelRowsExist(): void
    {
        $result = (new TenantAdopter())->adopt($this->context, 'tenantAAAA01');

        self::assertSame('tenantAAAA01', $result['tenant_uuid']);
        self::assertSame(0, $result['tables']['invoices']);
    }
}
