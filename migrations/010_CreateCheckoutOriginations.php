<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Workspace self-serve checkout (design spec §3.3): the origination ledger + subject guard.
 *
 * `subscription_checkout_originations` is the permanent correlation identity for a hosted
 * subscription checkout attempt -- its opaque `uuid` is stamped into provider metadata so a
 * webhook arriving after any terminal state (even much later) still correlates. `status` walks
 * a monotonic/idempotent state machine (see {@see \Glueful\Extensions\Payvia\Repositories
 * \CheckoutOriginationRepository::TRANSITIONS}); the one sanctioned exception is a terminal
 * status regressing to `provider_observed` when late money movement is observed, or advancing
 * to `late_settlement_conflict` when a newer reservation already owns the subject.
 *
 * `subscription_checkout_subject_guards` is a SEPARATE, narrower authority: "may this subject
 * originate another checkout right now?" It is durable coordination state only -- no provider
 * payload or PII -- and is never inferred from the origination rows or any local TTL (a hosted
 * checkout may complete well after any client-side expectation of expiry).
 *
 * Both `unique(gateway, checkout_reference)` and `unique(gateway, provider_subscription_id)`
 * stay nullable until the provider confirms a value: NULLs never collide in a unique index on
 * any of the three supported drivers (SQLite/PostgreSQL/MySQL) -- the same fact already relied
 * on by 008's `(gateway, provider_ref)` and pinned again here for this table by
 * CheckoutOriginationLedgerTest on both SQLite and PostgreSQL. No re-key workaround (007's
 * pattern) was needed.
 *
 * Plain (non-unique) `index()` calls are declared inline inside `createTable()`: unlike 005/008
 * (written against an older vendored framework where the SQLite/PostgreSQL generators silently
 * dropped inline plain indexes), the currently vendored `glueful/framework` emits every plain
 * index as its own follow-up `CREATE INDEX` statement regardless of where in the callback it was
 * declared -- confirmed empirically against both engines -- so no follow-up `alterTable()` is
 * required here.
 */
class CreateCheckoutOriginations implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('subscription_checkout_subject_guards')) {
            $schema->createTable('subscription_checkout_subject_guards', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('subject_key', 191);

                // open: free to originate. live: an origination currently owns this subject
                // (exclusive). blocked: explicit hold (e.g. a late_settlement_conflict) that
                // requires operator remediation, never an automatic reopen.
                $table->string('state', 16)->default('open');
                $table->string('origination_uuid', 12)->nullable();
                $table->string('blocked_reason', 255)->nullable();
                // Optimistic bookkeeping counter, bumped on every claim/release/block.
                $table->bigInteger('revision')->default(1);

                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'subject_key']);
                $table->index('state');
            });
        }

        if ($schema->hasTable('subscription_checkout_originations')) {
            return;
        }

        $schema->createTable('subscription_checkout_originations', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            // The opaque origination_uuid stamped into provider metadata.
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12)->default('');

            // Opaque consumer subject; scopes the live-guard above.
            $table->string('subject_key', 191);

            $table->string('gateway', 50);
            $table->string('provider_plan_identifier', 191);

            // Caller idempotency, tenant-scoped.
            $table->string('idempotency_key', 191);
            // SHA-256 hex over the canonical request shape.
            $table->string('request_fingerprint', 64);

            // Exclusive, RECOVERABLE provider-I/O lease -- NOT ownership. While `status` stays
            // `initializing`, multiple retries of the actual provider call must still execute
            // at most once at a time; this token/timestamp pair (mirrors provider_events'
            // dispatch_claim_token) lets a stale holder's late release/complete be fenced out
            // once another caller has taken over, without ever changing `status` itself.
            $table->string('initialization_claim_token', 12)->nullable();
            $table->timestamp('initialization_claimed_at')->nullable();

            // Initialization recovery only. Cleared the moment the origination reaches a
            // definitive (terminal) outcome -- never carried past that point.
            $table->string('customer_email', 254)->nullable();

            $table->string('return_url', 2048);
            $table->string('cancel_url', 2048);

            // Stripe session id / Paystack reference. Nullable until minted.
            $table->string('checkout_reference', 191)->nullable();
            // Stored verbatim -- not reconstructable from other columns.
            $table->string('checkout_url', 2048)->nullable();

            // Recorded at correlation (a signed webhook observed it). Nullable until then.
            $table->string('provider_subscription_id', 191)->nullable();
            // Diagnostics only -- never an ownership join.
            $table->string('provider_customer_code', 191)->nullable();
            $table->string('provider_plan_code', 191)->nullable();

            $table->string('status', 24);
            // Derived/read-optimized flag mirroring `status`'s terminality. Never the guard
            // authority -- `subscription_checkout_subject_guards.state` alone decides whether a
            // subject may originate another checkout.
            $table->boolean('live')->default(true);

            // The consumer (e.g. the subscriptions projector) whose durable acceptance
            // completes this origination, and that consumer's own acknowledgement.
            $table->string('required_projection_consumer', 50)->nullable();
            $table->string('projection_event_key', 191)->nullable();
            $table->string('projection_outcome', 24)->nullable();
            $table->string('projection_reason', 255)->nullable();

            // Subject/plan/actor context. LOCAL ONLY -- never sent to the provider.
            $table->json('consumer_metadata')->nullable();

            $table->timestamp('provider_expires_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique(['tenant_uuid', 'idempotency_key']);
            // Provider webhooks correlate by this pair alone, with no tenant context, so it
            // stays globally unique per gateway (mirrors gateway_subscriptions/payvia_transfers).
            $table->unique(['gateway', 'checkout_reference']);
            $table->unique(['gateway', 'provider_subscription_id']);

            $table->index(['subject_key', 'live']);
            $table->index('status');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Child before parent: originations reference guards only by opaque uuid (no DB-level
        // foreign key), but this keeps the drop order consistent with the tenant-lifecycle
        // purge order described in the design spec.
        $schema->dropTableIfExists('subscription_checkout_originations');
        $schema->dropTableIfExists('subscription_checkout_subject_guards');
    }

    public function getDescription(): string
    {
        return 'Creates the checkout origination ledger and subject guard tables.';
    }
}
