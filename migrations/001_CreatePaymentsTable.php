<?php

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Create payments table for external payment providers.
 */
class CreatePaymentsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('payments', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);

            $table->string('user_uuid', 12)->nullable();

            // Polymorphic domain link:
            // - payable_type: logical type of the thing being paid for
            //                 (e.g., 'subscription', 'order', 'invoice').
            // - payable_id: identifier of that thing in its own domain
            //               (UUID, numeric ID, external reference, etc.).
            $table->string('payable_type', 100)->nullable();
            $table->string('payable_id', 255)->nullable();

            $table->string('gateway', 50);
            $table->string('gateway_transaction_id', 100)->nullable();
            $table->string('reference', 100);

            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('GHS');
            $table->string('status', 20)->default('pending');
            $table->string('message', 255)->nullable();

            // Free-form, queryable context stored by the application
            // (e.g. plan UUID, billing cycle, campaign tags). Intended
            // for lightweight app-level metadata; heavier raw provider
            // payloads go into raw_payload.
            $table->json('metadata')->nullable();
            $table->json('raw_payload')->nullable();

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique('reference');
            $table->index('user_uuid');
            $table->index(['payable_type', 'payable_id']);
            $table->index('gateway');
            $table->index('gateway_transaction_id');

            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('payments');
    }

    public function getDescription(): string
    {
        return 'Creates payments table for external payment providers with reconciliation metadata.';
    }
}
