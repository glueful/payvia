<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Repositories\BillingPlanRepository;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class MigrationsTest extends PayviaTestCase
{
    public function testBillingPlansGainsNullableGatewayLinkageColumns(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);

        self::assertTrue($schema->hasColumn('billing_plans', 'gateway'));
        self::assertTrue($schema->hasColumn('billing_plans', 'gateway_product_id'));
        self::assertTrue($schema->hasColumn('billing_plans', 'gateway_price_id'));
        self::assertFalse($schema->hasColumn('billing_plans', 'features'));
    }

    public function testListReturnsGatewayLinkage(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateBillingPlansTable())->up($schema);

        $repo = new BillingPlanRepository($this->connection);
        $repo->create([
            'name' => 'Pro',
            'amount' => 50.0,
            'currency' => 'GHS',
            'interval' => 'monthly',
            'gateway' => 'paystack',
            'gateway_price_id' => 'PLN_x',
            'status' => 'active',
        ]);

        $rows = $repo->list([]);

        self::assertSame('paystack', $rows[0]['gateway']);
        self::assertSame('PLN_x', $rows[0]['gateway_price_id']);
        self::assertArrayNotHasKey('features', $rows[0]);
    }

    public function testProviderEventsTableHasTwoKeysAndOutboxColumns(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateProviderEventsTable())->up($schema);

        self::assertTrue($schema->hasTable('provider_events'));
        foreach ([
            'delivery_key',
            'logical_event_key',
            'status',
            'dispatch_status',
            'dispatched_at',
            'dispatch_claimed_at',
            'attempts',
            'signature_valid',
            'normalized_payload',
        ] as $column) {
            self::assertTrue($schema->hasColumn('provider_events', $column), "missing {$column}");
        }
    }

    public function testGatewaySubscriptionsTableShape(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new CreateGatewaySubscriptionsTable())->up($schema);

        self::assertTrue($schema->hasTable('gateway_subscriptions'));
        foreach ([
            'gateway',
            'gateway_subscription_id',
            'gateway_customer_id',
            'gateway_price_id',
            'status',
            'current_period_end',
            'cancel_at_period_end',
            'raw_payload',
        ] as $column) {
            self::assertTrue($schema->hasColumn('gateway_subscriptions', $column), "missing {$column}");
        }
    }
}
