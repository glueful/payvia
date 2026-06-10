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
}
