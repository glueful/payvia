<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

class CreateProviderEventsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('provider_events')) {
            return;
        }

        $schema->createTable('provider_events', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('gateway', 50);
            $table->string('source', 20);
            $table->string('provider_event_id', 191)->nullable();
            $table->string('delivery_key', 191);
            $table->string('logical_event_key', 191);
            $table->string('type', 100);
            $table->string('status', 20)->default('received');
            $table->string('dispatch_status', 20)->default('pending');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('dispatch_claimed_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->boolean('signature_valid')->default(false);
            $table->json('normalized_payload')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('received_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('processed_at')->nullable();

            $table->unique('uuid');
            $table->unique(['gateway', 'delivery_key']);
            $table->index(['gateway', 'logical_event_key']);
            $table->index('status');
            $table->index('dispatch_status');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('provider_events');
    }

    public function getDescription(): string
    {
        return 'Creates provider_events for two-key idempotency and outbox dispatch.';
    }
}
