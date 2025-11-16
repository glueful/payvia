<?php

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Create invoices table
 *
 * Stores invoice records that can optionally be linked to users,
 * plans, and generic domain entities via polymorphic fields. Payment
 * reconciliation can be performed by relating invoices to payments
 * using shared references or metadata.
 */
class CreateInvoicesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('invoices', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);

            $table->string('user_uuid', 12)->nullable();

            // Optional link to a billing plan
            $table->string('billing_plan_uuid', 12)->nullable();

            // Polymorphic link to domain entity (org, location, project, etc.)
            $table->string('payable_type', 100)->nullable();
            $table->string('payable_id', 255)->nullable();

            // Human-readable invoice number (unique)
            $table->string('number', 50);

            // Financials
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('GHS');
            $table->string('status', 20)->default('draft'); // draft, pending, paid, canceled, failed

            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Free-form metadata (tax info, external IDs, notes, etc.)
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique('number');
            $table->index('user_uuid');
            $table->index(['payable_type', 'payable_id']);
            $table->index('billing_plan_uuid');
            $table->index('status');
            $table->index('due_at');

            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('invoices');
    }

    public function getDescription(): string
    {
        return 'Creates invoices table for generic billing invoices with polymorphic links.';
    }
}

