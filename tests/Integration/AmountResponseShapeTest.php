<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Auth\AuthenticationManager;
use Glueful\Extensions\Payvia\Controllers\BillingPlanController;
use Glueful\Extensions\Payvia\Controllers\InvoiceController;
use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateInvoicesTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentsTable;
use Glueful\Extensions\Payvia\Repositories\BillingPlanRepository;
use Glueful\Extensions\Payvia\Repositories\InvoiceRepository;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Repositories\PaymentRepository;
use Glueful\Extensions\Payvia\Services\BillingPlanService;
use Glueful\Extensions\Payvia\Services\InvoiceService;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * `amount` is stored as a bigint/integer minor-unit column on every domain
 * table. PDO's return type for integer columns is driver-dependent (some
 * MySQL/Postgres configurations round-trip integer columns as numeric
 * strings), so every read surface that returns `amount` must normalize it
 * back to a PHP int rather than trust the driver. Covers the two GET/list
 * HTTP endpoints (billing plans, invoices) plus the payments repository
 * lookup and the payment_intents open-intent read, none of which may leak a
 * driver-dependent string `amount`.
 */
final class AmountResponseShapeTest extends PayviaTestCase
{
    public function testInvoicesListResponseAmountIsInt(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateInvoicesTable())->up($schema);

        $repo = new InvoiceRepository($this->connection);
        $repo->createInvoice($this->context, [
            'amount' => 5000,
            'currency' => 'GHS',
            'status' => 'pending',
        ]);

        $this->bind(AuthenticationManager::class, $this->createMock(AuthenticationManager::class));
        $this->bind(Request::class, new Request());
        $controller = new InvoiceController($this->context, new InvoiceService($repo));

        $response = $controller->index(new Request());
        $body = json_decode((string) $response->getContent(), true);

        self::assertIsArray($body);
        self::assertNotEmpty($body['data']);
        self::assertIsInt($body['data'][0]['amount']);
        self::assertSame(5000, $body['data'][0]['amount']);
    }

    public function testBillingPlansListResponseAmountIsInt(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);

        $repo = new BillingPlanRepository($this->connection);
        $repo->createPlan($this->context, [
            'name' => 'Pro',
            'amount' => 7500,
            'currency' => 'GHS',
            'interval' => 'monthly',
            'status' => 'active',
        ]);

        $this->bind(AuthenticationManager::class, $this->createMock(AuthenticationManager::class));
        $this->bind(Request::class, new Request());
        $controller = new BillingPlanController($this->context, new BillingPlanService($repo));

        $response = $controller->index(new Request());
        $body = json_decode((string) $response->getContent(), true);

        self::assertIsArray($body);
        self::assertNotEmpty($body['data']['plans']);
        self::assertIsInt($body['data']['plans'][0]['amount']);
        self::assertSame(7500, $body['data']['plans'][0]['amount']);
    }

    public function testPaymentFindByReferenceAmountIsInt(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreatePaymentsTable())->up($schema);

        $repo = new PaymentRepository($this->connection);
        $repo->createPayment($this->context, [
            'gateway' => 'paystack',
            'reference' => 'ref-shape-1',
            'amount' => 12000,
            'currency' => 'GHS',
            'status' => 'success',
        ]);

        $row = $repo->findByReference($this->context, 'ref-shape-1');

        self::assertIsArray($row);
        self::assertIsInt($row['amount']);
        self::assertSame(12000, $row['amount']);
    }

    public function testPaymentIntentOpenReadAmountIsInt(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreatePaymentIntentsTable())->up($schema);

        $repo = new PaymentIntentRepository($this->connection);
        $repo->createOpen($this->context, [
            'payable_type' => 'invoice',
            'payable_id' => '1',
            'gateway' => 'stripe',
            'reference' => 'ref-intent-1',
            'amount' => 3300,
            'currency' => 'GHS',
        ]);

        $row = $repo->findOpen($this->context, 'invoice', '1');

        self::assertIsArray($row);
        self::assertIsInt($row['amount']);
        self::assertSame(3300, $row['amount']);
    }
}
