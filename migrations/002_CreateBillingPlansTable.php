<?php

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Create billing_plans table
 *
 * Generic catalog of billing/subscription plans. Designed to be
 * tenant-agnostic; applications can link plans to any domain entity
 * (organizations, locations, etc.) via their own tables or via the
 * payments/invoices polymorphic links.
 */
class CreateBillingPlansTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('billing_plans', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);

            $table->string('name', 100);
            $table->text('description')->nullable();

            // Pricing
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('GHS');
            // Interval: monthly, yearly, one_time, etc.
            $table->string('interval', 20)->default('monthly');
            $table->integer('trial_days')->nullable();

            // JSON feature flags / usage limits / metadata
            $table->json('features')->nullable();
            $table->json('metadata')->nullable();

            $table->string('status', 20)->default('active');

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique('name');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('billing_plans');
    }

    public function getDescription(): string
    {
        return 'Creates billing_plans table for generic billing/subscription plans.';
    }
}

