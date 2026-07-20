<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Create payvia_transfers: the durable, pre-provider-I/O record of a payout
 * transfer attempt. Written before any provider call so a lost/ambiguous
 * response can be reconciled instead of blindly re-attempted. This table is
 * provider-transport only -- it names no caller-domain class or table.
 */
class CreatePayviaTransfersTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('payvia_transfers')) {
            return;
        }

        $schema->createTable('payvia_transfers', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12)->default('');

            $table->string('gateway', 50);

            // The caller's canonical per-attempt idempotency key (e.g.
            // "{payoutUuid}:attempt:{n}"). Never sent to the provider as-is.
            $table->string('idempotency_key', 191);

            // Provider-safe reference derived from idempotency_key (e.g.
            // Paystack's lowercase [a-z0-9_-]{16,50} constraint). Persisted
            // so a lost provider response can be recovered by reference.
            $table->string('provider_reference', 191);
            // The provider's own transfer id/code -- known only once a
            // provider response is received (or reconciled).
            $table->string('provider_ref', 191)->nullable();

            // Opaque destination account reference (PayoutDestination::accountRef).
            $table->string('destination_ref', 191);

            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('message', 255)->nullable();

            // The normalized request persisted before provider I/O.
            $table->json('request_payload');
            // The raw provider response, filled in once I/O completes.
            $table->json('raw_payload')->nullable();

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            // Per-attempt idempotency is tenant-scoped: the same canonical
            // attempt key never repeats within a (tenant, gateway).
            $table->unique(['tenant_uuid', 'gateway', 'idempotency_key']);
            // Correlation identity: the derived provider-safe reference has
            // no tenant context on the wire, so it stays globally unique per
            // gateway (mirrors payments.reference / gateway_subscriptions'
            // (gateway, gateway_subscription_id)).
            $table->unique(['gateway', 'provider_reference']);
            // provider_ref is nullable until a provider response is known.
            // NULLs never collide in a unique index on any of the three
            // supported drivers (SQLite/PostgreSQL/MySQL), so any number of
            // pre-response rows may share a NULL provider_ref while a
            // returned value stays globally unique per gateway.
            $table->unique(['gateway', 'provider_ref']);
        });

        // Declared as a follow-up ALTER (rather than inline on createTable):
        // no unique above is solely tenant-prefixed coverage by itself, so
        // nothing else in this table guarantees tenant_uuid coverage, and
        // the SQLite/PostgreSQL generators silently drop plain (non-unique)
        // index() calls made inside a createTable() callback.
        $schema->alterTable('payvia_transfers', function ($table): void {
            $table->index('tenant_uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('payvia_transfers');
    }

    public function getDescription(): string
    {
        return 'Creates payvia_transfers: durable pre-I/O provider payout transfer attempts.';
    }
}
