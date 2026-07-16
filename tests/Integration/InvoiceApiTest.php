<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Controllers\InvoiceController;
use Glueful\Extensions\Payvia\Database\Migrations\CreateInvoicesTable;
use Glueful\Extensions\Payvia\Repositories\InvoiceRepository;
use Glueful\Extensions\Payvia\Services\InvoiceService;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Glueful\Auth\AuthenticationManager;
use Symfony\Component\HttpFoundation\Request;

final class InvoiceApiTest extends PayviaTestCase
{
    private InvoiceController $controller;
    private InvoiceRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->connection->getSchemaBuilder();
        (new CreateInvoicesTable())->up($schema);

        $this->repo = new InvoiceRepository($this->connection);
        $this->bind(AuthenticationManager::class, $this->createMock(AuthenticationManager::class));
        $this->bind(Request::class, new Request());
        $this->controller = new InvoiceController(
            $this->context,
            new InvoiceService($this->repo)
        );
    }

    /** @param array<string,mixed> $body */
    private function jsonRequest(array $body): Request
    {
        return new Request([], [], [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testCreateRejectsInvalidStatus(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'amount' => 5000,
            'status' => 'bogus',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->repo->list($this->context, []));
    }

    public function testCreateRejectsInvalidCurrency(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'amount' => 5000,
            'currency' => 'CEDIS',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->repo->list($this->context, []));
    }

    public function testCreateRejectsZeroAmount(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'amount' => 0,
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->repo->list($this->context, []));
    }

    public function testCreateRejectsNegativeAmount(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'amount' => -10,
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->repo->list($this->context, []));
    }

    public function testCreateUppercasesLowercaseCurrency(): void
    {
        $this->controller->create($this->jsonRequest([
            'amount' => 5000,
            'currency' => 'usd',
        ]));

        $rows = $this->repo->list($this->context, []);
        self::assertSame('USD', $rows[0]['currency']);
    }

    public function testCreateAcceptsValidValues(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'amount' => 5000,
            'currency' => 'GHS',
            'status' => 'draft',
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $this->repo->list($this->context, []));
    }

    public function testMarkPaidRejectsUnparseablePaidAt(): void
    {
        $uuid = $this->repo->createInvoice($this->context, [
            'amount' => 5000,
            'currency' => 'GHS',
            'status' => 'pending',
        ]);

        $response = $this->controller->markPaid($this->jsonRequest([
            'invoice_uuid' => $uuid,
            'paid_at' => 'not-a-date',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('pending', $this->repo->list($this->context, [])[0]['status']);
    }

    public function testMarkPaidAcceptsValidPaidAt(): void
    {
        $uuid = $this->repo->createInvoice($this->context, [
            'amount' => 5000,
            'currency' => 'GHS',
            'status' => 'pending',
        ]);

        $response = $this->controller->markPaid($this->jsonRequest([
            'invoice_uuid' => $uuid,
            'paid_at' => '2026-01-15 10:30:00',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid', $this->repo->list($this->context, [])[0]['status']);
    }

    public function testIndexCapsPerPageAt100(): void
    {
        $request = new Request(['per_page' => '100000']);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(100, $body['per_page']);
    }
}
