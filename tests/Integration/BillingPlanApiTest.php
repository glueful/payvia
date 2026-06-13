<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Controllers\BillingPlanController;
use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Repositories\BillingPlanRepository;
use Glueful\Extensions\Payvia\Services\BillingPlanService;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Glueful\Auth\AuthenticationManager;
use Symfony\Component\HttpFoundation\Request;

final class BillingPlanApiTest extends PayviaTestCase
{
    private BillingPlanController $controller;
    private BillingPlanRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);

        $this->repo = new BillingPlanRepository($this->connection);
        $this->bind(AuthenticationManager::class, $this->createMock(AuthenticationManager::class));
        $this->bind(Request::class, new Request());
        $this->controller = new BillingPlanController(
            $this->context,
            new BillingPlanService($this->repo)
        );
    }

    /** @param array<string,mixed> $body */
    private function jsonRequest(array $body): Request
    {
        return new Request([], [], [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testCreatePersistsGatewayLinkageFields(): void
    {
        $request = $this->jsonRequest([
            'name' => 'Pro',
            'amount' => 50.0,
            'currency' => 'GHS',
            'interval' => 'monthly',
            'gateway' => 'paystack',
            'gateway_product_id' => 'PROD_1',
            'gateway_price_id' => 'PLN_x',
        ]);

        $this->controller->create($request);

        $rows = $this->repo->list([]);
        self::assertSame('paystack', $rows[0]['gateway']);
        self::assertSame('PROD_1', $rows[0]['gateway_product_id']);
        self::assertSame('PLN_x', $rows[0]['gateway_price_id']);
    }

    public function testUpdateChangesGatewayLinkageFields(): void
    {
        $uuid = $this->repo->create([
            'name' => 'Basic',
            'amount' => 10.0,
            'currency' => 'GHS',
            'interval' => 'monthly',
            'status' => 'active',
        ]);

        $request = $this->jsonRequest([
            'plan_uuid' => $uuid,
            'gateway' => 'paystack',
            'gateway_price_id' => 'PLN_y',
        ]);

        $this->controller->update($request);

        $rows = $this->repo->list([]);
        self::assertSame('paystack', $rows[0]['gateway']);
        self::assertSame('PLN_y', $rows[0]['gateway_price_id']);
    }

    public function testCreateRejectsInvalidStatus(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'name' => 'Pro',
            'amount' => 50.0,
            'status' => 'bogus',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->repo->list([]));
    }

    public function testCreateRejectsInvalidInterval(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'name' => 'Pro',
            'amount' => 50.0,
            'interval' => 'weekly',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->repo->list([]));
    }

    public function testCreateRejectsInvalidCurrency(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'name' => 'Pro',
            'amount' => 50.0,
            'currency' => 'CEDIS',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->repo->list([]));
    }

    public function testCreateRejectsZeroAmount(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'name' => 'Pro',
            'amount' => 0,
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->repo->list([]));
    }

    public function testCreateRejectsNegativeAmount(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'name' => 'Pro',
            'amount' => -5,
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->repo->list([]));
    }

    public function testCreateUppercasesLowercaseCurrency(): void
    {
        $this->controller->create($this->jsonRequest([
            'name' => 'Pro',
            'amount' => 50.0,
            'currency' => 'usd',
        ]));

        $rows = $this->repo->list([]);
        self::assertSame('USD', $rows[0]['currency']);
    }

    public function testUpdateRejectsInvalidStatus(): void
    {
        $uuid = $this->repo->create([
            'name' => 'Basic',
            'amount' => 10.0,
            'currency' => 'GHS',
            'interval' => 'monthly',
            'status' => 'active',
        ]);

        $response = $this->controller->update($this->jsonRequest([
            'plan_uuid' => $uuid,
            'status' => 'bogus',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('active', $this->repo->list([])[0]['status']);
    }

    public function testUpdateRejectsZeroAmount(): void
    {
        $uuid = $this->repo->create([
            'name' => 'Basic',
            'amount' => 10.0,
            'currency' => 'GHS',
            'interval' => 'monthly',
            'status' => 'active',
        ]);

        $response = $this->controller->update($this->jsonRequest([
            'plan_uuid' => $uuid,
            'amount' => 0,
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(10.0, (float) $this->repo->list([])[0]['amount']);
    }

    public function testCreateAcceptsValidValues(): void
    {
        $response = $this->controller->create($this->jsonRequest([
            'name' => 'Pro',
            'amount' => 50.0,
            'currency' => 'GHS',
            'interval' => 'one_time',
            'status' => 'inactive',
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $this->repo->list([]));
    }
}
