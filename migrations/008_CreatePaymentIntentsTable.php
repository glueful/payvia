<?php

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Create open-payment-intent records used to make hosted checkout initiation
 * idempotent per payable.
 */
class CreatePaymentIntentsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('payment_intents', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);

            $table->string('payable_type', 100);
            $table->string('payable_id', 255);

            // Open rows use "{type}:{id}". Closing re-keys to
            // "{type}:{id}:{reference}", freeing the open key portably on
            // engines without partial unique indexes.
            $table->string('idempotency_key', 512);

            $table->string('gateway', 50);
            $table->string('reference', 100);
            $table->string('status', 16)->default('open');

            $table->bigInteger('amount');
            $table->string('currency', 10);
            $table->json('payload')->nullable();

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique('idempotency_key');
            $table->index('reference');
            $table->index(['payable_type', 'payable_id', 'status']);
            $table->index('gateway');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('payment_intents');
    }

    public function getDescription(): string
    {
        return 'Creates payment intent records for idempotent hosted payment initiation.';
    }
}
