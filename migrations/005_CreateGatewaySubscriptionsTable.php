<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

class CreateGatewaySubscriptionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('gateway_subscriptions')) {
            return;
        }

        $schema->createTable('gateway_subscriptions', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12)->default('');
            $table->string('gateway', 50);
            $table->string('gateway_subscription_id', 191);
            $table->string('gateway_customer_id', 191)->nullable();
            $table->string('gateway_price_id', 191)->nullable();
            $table->string('billing_plan_uuid', 12)->nullable();
            $table->string('status', 30);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            // Correlation identity: provider webhooks arrive keyed by this
            // pair alone, with no tenant context, so it stays globally unique.
            $table->unique(['gateway', 'gateway_subscription_id']);
            $table->index('billing_plan_uuid');
            $table->index(['gateway', 'status']);
        });

        // Declared as a follow-up ALTER (rather than inline on createTable): the
        // (gateway, gateway_subscription_id) unique isn't tenant-prefixed, so
        // nothing else in this table covers tenant_uuid, and the SQLite/
        // PostgreSQL generators silently drop plain (non-unique) index() calls
        // made inside a createTable() callback.
        $schema->alterTable('gateway_subscriptions', function ($table) {
            $table->index('tenant_uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('gateway_subscriptions');
    }

    public function getDescription(): string
    {
        return 'Creates persisted gateway subscription projection table.';
    }
}
