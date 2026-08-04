<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Checkout;

use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\ProviderEventPayloadUpdaterInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateCheckoutOriginations;
use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\GatewaySubscriptionService;
use Glueful\Extensions\Payvia\Services\UnresolvedSubscriptionOwnershipException;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Glueful\Http\Client as HttpClient;

/**
 * Task 7 (workspace self-serve checkout, design spec §3.4 + the §3.3 late-settlement-conflict
 * addendum): origination ownership correlation in `GatewaySubscriptionService::
 * applyProviderEvent()` and Stripe's `checkout.session.expired` ledger-lifecycle handling.
 *
 * SCOPE: the Paystack two-event pre-pass is fixture-gated (no sandbox fixtures exist yet -- see
 * progress.md's Task 2/3 SANDBOX GATE) and is deliberately NOT covered here; only the Stripe/core
 * correlation path is exercised, per the controller's scope-adjustment ruling for this task.
 */
final class OriginationCorrelationTest extends PayviaTestCase
{
    private ProviderCorrelationRepository $subscriptions;
    private CheckoutOriginationRepository $originations;
    private CheckoutSubjectGuardRepository $guards;
    private ProviderEventRepository $events;
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = $this->connection->getSchemaBuilder();
        (new CreateCheckoutOriginations())->up($schema);
        (new CreateGatewaySubscriptionsTable())->up($schema);
        (new CreateBillingPlansTable())->up($schema);
        (new CreateProviderEventsTable())->up($schema);

        // Deliberately NOT passing $this->context here: BaseRepository::getSharedDb()'s
        // context-aware fallback swaps in an unrelated Connection::fromContext() instance
        // whenever the passed connection reports hasContext() === false (true for the plain
        // `new Connection([...])` this harness builds) -- mirrors
        // CheckoutOriginationLedgerTest's identical connection-only construction.
        $this->subscriptions = new ProviderCorrelationRepository($this->connection);
        $this->originations = new CheckoutOriginationRepository($this->connection);
        $this->guards = new CheckoutSubjectGuardRepository($this->connection);
        $this->events = new ProviderEventRepository($this->connection);
        $this->bind(FakeWebhookGateway::class, new FakeWebhookGateway());
    }

    // ==================================================================
    // Adopt + enrich happy path (through the real WebhookService plumbing)
    // ==================================================================

    public function testAdoptAndEnrichHappyPathReturnsReplacementOnFirstDelivery(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantHAPPY1',
            'subject_key' => 'subject-happy',
            'status' => 'pending',
            'consumer_metadata' => $this->consumerMetadata('tenantHAPPY1'),
        ]);
        $this->seedLiveGuard('tenantHAPPY1', 'subject-happy', $uuid);

        $spy = $this->strictSpy();
        $service = $this->webhookService($spy);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_happy', 'delivery-happy', [
            'gateway_subscription_id' => 'sub_happy',
            'status' => 'active',
            'origination_uuid' => $uuid,
        ]);

        $result = $service->ingest('fake', $body);
        self::assertTrue($result->accepted);

        $projection = $this->subscriptions->findGatewaySubscriptionByGatewayId('fake', 'sub_happy');
        self::assertNotNull($projection);
        self::assertSame('tenantHAPPY1', $projection['tenant_uuid']);

        $origination = $this->originations->findByUuid($uuid);
        self::assertSame('provider_observed', $origination['status']);
        self::assertSame('sub_happy', $origination['provider_subscription_id']);

        self::assertCount(1, $spy->seen, 'the strict listener must see the enriched event on FIRST delivery');
        $metadata = $spy->seen[0]['metadata'];
        self::assertSame('tenantHAPPY1', $metadata['tenant_uuid']);
        self::assertSame('workspace', $metadata['subject_type']);
        self::assertSame('wsHAPPY0001', $metadata['subject_uuid']);
        self::assertSame('planHAPPY01', $metadata['plan_uuid']);
        self::assertSame('subscriptions', $metadata['glueful_consumer']);
        self::assertArrayNotHasKey('actor_user_uuid', $metadata, 'the actor uuid must NEVER leave the ledger');

        $stored = $this->events->findByDeliveryKey('fake', 'delivery-happy');
        $persisted = json_decode((string) $stored['normalized_payload'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('tenantHAPPY1', $persisted['metadata']['tenant_uuid'], 'enrichment must be durably persisted');
    }

    // ==================================================================
    // Ledger owner wins over a conflicting metadata hint (no existing projection)
    // ==================================================================

    public function testLedgerOwnerWinsOverConflictingMetadataHintWhenAdopting(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantLEDGER1',
            'subject_key' => 'subject-hint',
            'status' => 'pending',
            'consumer_metadata' => $this->consumerMetadata('tenantLEDGER1'),
        ]);
        $this->seedLiveGuard('tenantLEDGER1', 'subject-hint', $uuid);

        $event = ProviderEvent::create(
            'fake',
            EventType::SUBSCRIPTION_CREATED,
            null,
            'delivery-hint',
            'sub_hint',
            new \DateTimeImmutable(),
            [
                'gateway_subscription_id' => 'sub_hint',
                'status' => 'active',
                'origination_uuid' => $uuid,
                // A conflicting hint riding along in provider metadata must never win over the
                // ledger-resolved owner -- diagnosed and ignored, matching rule 1's policy.
                'metadata' => ['tenant_uuid' => 'tenantHINTBAD'],
            ],
            ['raw' => true],
        );

        $replacement = $this->service()->applyProviderEvent($event);

        self::assertNotNull($replacement);
        $projection = $this->subscriptions->findGatewaySubscriptionByGatewayId('fake', 'sub_hint');
        self::assertSame('tenantLEDGER1', $projection['tenant_uuid'], 'the ledger owner must win, never the hint');
    }

    // ==================================================================
    // Existing-projection event with a token still enriches (crash-window retry)
    // ==================================================================

    public function testExistingProjectionEventWithTokenStillEnrichesAndRetryReEnrichesAfterPersistFailure(): void
    {
        $this->subscriptions->upsertGatewaySubscription([
            'gateway' => 'fake',
            'gateway_subscription_id' => 'sub_crash',
            'tenant_uuid' => 'tenantCRASH1',
            'status' => 'active',
        ]);
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantCRASH1',
            'subject_key' => 'subject-crash',
            'status' => 'pending',
            'consumer_metadata' => $this->consumerMetadata('tenantCRASH1'),
        ]);
        $this->seedLiveGuard('tenantCRASH1', 'subject-crash', $uuid);

        $insertUuid = $this->events->insertReceived([
            'gateway' => 'fake',
            'source' => 'webhook',
            'provider_event_id' => null,
            'delivery_key' => 'delivery-crash',
            'logical_event_key' => EventType::SUBSCRIPTION_CREATED . ':sub_crash',
            'type' => EventType::SUBSCRIPTION_CREATED,
            'signature_valid' => true,
            'normalized_payload' => [
                'gateway_subscription_id' => 'sub_crash',
                'status' => 'active',
                'origination_uuid' => $uuid,
            ],
            'raw_payload' => ['raw' => true],
        ]);
        self::assertNotNull($insertUuid);

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);
        $subscriptions = new GatewaySubscriptionService(
            $this->context,
            $this->subscriptions,
            $manager,
            $this->originations,
            $this->guards,
        );
        $applier = static fn (
            PaymentProviderEventInterface $event
        ): ?PaymentProviderEventInterface => $subscriptions->applyProviderEvent($event);

        // Attempt 1: the applier runs (the origination transition + gateway_subscriptions row
        // ARE written), but persisting the enriched payload fails -- simulating the crash window
        // between the provider row being written and the enriched payload being durably stored.
        $failingUpdater = new class implements ProviderEventPayloadUpdaterInterface {
            public function replaceNormalizedPayload(string $uuid, array $normalized): void
            {
                throw new \RuntimeException('simulated persistence failure');
            }
        };
        $firstAttempt = new WebhookService(
            context: $this->context,
            gateways: $manager,
            events: $this->events,
            applier: $applier,
            payloadUpdater: $failingUpdater,
        );

        try {
            $firstAttempt->processStored($insertUuid);
            self::fail('expected the simulated persistence failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated persistence failure', $e->getMessage());
        }

        // The provider row was already written on attempt 1, despite payload persistence
        // failing -- proving the ledger transition/upsert are safely idempotent to repeat.
        self::assertSame('provider_observed', $this->originations->findByUuid($uuid)['status']);

        // Attempt 2 (retry): the event is still retryable (status stayed 'failed'). This time
        // the payload updater succeeds and the strict listener sees the re-enriched event.
        $spy = $this->strictSpy();
        $retry = new WebhookService(
            context: $this->context,
            gateways: $manager,
            events: $this->events,
            dispatcher: $this->dispatcherToSpy($spy),
            applier: $applier,
            payloadUpdater: $this->events,
        );
        $retry->processStored($insertUuid);

        self::assertCount(1, $spy->seen);
        self::assertSame('tenantCRASH1', $spy->seen[0]['metadata']['tenant_uuid']);

        $stored = $this->events->findByUuid($insertUuid);
        self::assertSame('processed', $stored['status']);
    }

    // ==================================================================
    // Owner mismatch with an existing projection is refused
    // ==================================================================

    public function testOwnerMismatchWithExistingProjectionIsRefused(): void
    {
        $this->subscriptions->upsertGatewaySubscription([
            'gateway' => 'fake',
            'gateway_subscription_id' => 'sub_mismatch',
            'tenant_uuid' => 'tenantEXIST01',
            'status' => 'active',
        ]);
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantLEDGER99',
            'subject_key' => 'subject-mismatch',
            'status' => 'pending',
            'consumer_metadata' => $this->consumerMetadata('tenantLEDGER99'),
        ]);
        $this->seedLiveGuard('tenantLEDGER99', 'subject-mismatch', $uuid);

        $event = ProviderEvent::create(
            'fake',
            EventType::SUBSCRIPTION_CREATED,
            null,
            'delivery-mismatch',
            'sub_mismatch',
            new \DateTimeImmutable(),
            [
                'gateway_subscription_id' => 'sub_mismatch',
                'status' => 'active',
                'origination_uuid' => $uuid,
            ],
            ['raw' => true],
        );

        try {
            $this->service()->applyProviderEvent($event);
            self::fail('Expected UnresolvedSubscriptionOwnershipException to propagate');
        } catch (UnresolvedSubscriptionOwnershipException $e) {
            self::assertStringContainsString(UnresolvedSubscriptionOwnershipException::MARKER, $e->getMessage());
        }

        $projection = $this->subscriptions->findGatewaySubscriptionByGatewayId('fake', 'sub_mismatch');
        self::assertSame('tenantEXIST01', $projection['tenant_uuid'], 'ownership must never move on a mismatch');

        self::assertSame(
            'pending',
            $this->originations->findByUuid($uuid)['status'],
            'the origination ledger row must be left untouched when refused'
        );
    }

    // ==================================================================
    // Late-settlement conflict
    // ==================================================================

    public function testLateSettlementConflictBlocksGuardBoundToTheConflictedOriginationAndStillDispatches(): void
    {
        $oldUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantOLD001',
            'subject_key' => 'subject-conflict',
            'status' => 'dispatched',
            'consumer_metadata' => $this->consumerMetadata('tenantOLD001'),
        ]);
        $newUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantOLD001',
            'subject_key' => 'subject-conflict',
            'status' => 'provider_observed',
        ]);
        // The guard is currently LIVE for the NEWER origination -- this is the "subject now has
        // a newer live origination" condition the late webhook collides with.
        $this->seedLiveGuard('tenantOLD001', 'subject-conflict', $newUuid);

        $spy = $this->strictSpy();
        $service = $this->webhookService($spy);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_late', 'delivery-late', [
            'gateway_subscription_id' => 'sub_late',
            'status' => 'active',
            'origination_uuid' => $oldUuid,
        ]);

        $result = $service->ingest('fake', $body);
        self::assertTrue($result->accepted, 'the conflicted event must still dispatch normally');

        $old = $this->originations->findByUuid($oldUuid);
        self::assertSame('late_settlement_conflict', $old['status']);

        $new = $this->originations->findByUuid($newUuid);
        self::assertSame('provider_observed', $new['status'], 'the newer origination row must be left untouched');

        $guard = $this->guardRow('tenantOLD001', 'subject-conflict');
        self::assertSame('blocked', $guard['state']);
        self::assertSame(
            $oldUuid,
            $guard['origination_uuid'],
            'the guard must bind to the CONFLICTED origination for the future operator-CAS, not the newer one'
        );
        // The reason must name BOTH originations -- the conflicted one and the newer one that
        // caused the conflict -- so an operator reading it doesn't have to go dig further.
        self::assertNotNull($guard['blocked_reason']);
        self::assertStringContainsString($oldUuid, $guard['blocked_reason']);
        self::assertStringContainsString($newUuid, $guard['blocked_reason']);

        // The gateway_subscriptions projection still records the honest provider state -- the
        // entitlement decision belongs to the downstream subscriptions consumer, not here.
        $projection = $this->subscriptions->findGatewaySubscriptionByGatewayId('fake', 'sub_late');
        self::assertNotNull($projection);
        self::assertSame('tenantOLD001', $projection['tenant_uuid']);

        self::assertCount(1, $spy->seen, 'the enriched event must still reach strict dispatch');
        self::assertSame('tenantOLD001', $spy->seen[0]['metadata']['tenant_uuid']);
    }

    // ==================================================================
    // Hardening: unchecked CAS returns (code review findings)
    // ==================================================================

    /**
     * IMPORTANT finding 1 (provider_observed branch): the origination's real status no longer
     * matches what ownership resolution assumed -- simulating a concurrent transition elsewhere
     * that landed between the read and this write. `transitionOrThrow()` must refuse to silently
     * proceed: the applier throws, nothing is dispatched, and the projection/ledger stay exactly
     * as they were before this attempt.
     */
    public function testProviderObservedCasRefusalThrowsAndDispatchesNothing(): void
    {
        // 'preparing' can only legally advance to 'initializing' -- 'preparing' -> 'provider_observed'
        // is illegal per CheckoutOriginationRepository::TRANSITIONS, exactly the observable
        // consequence of a real concurrent-write race (the row is not at the status the caller
        // assumed).
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantRACE01',
            'subject_key' => 'subject-race-po',
            'status' => 'preparing',
        ]);

        $spy = $this->strictSpy();
        $service = $this->webhookService($spy);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_race_po', 'delivery-race-po', [
            'gateway_subscription_id' => 'sub_race_po',
            'status' => 'active',
            'origination_uuid' => $uuid,
        ]);

        try {
            $service->ingest('fake', $body);
            self::fail('Expected the refused CAS to propagate as a hard error');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('failed to advance checkout origination', $e->getMessage());
        }

        self::assertSame([], $spy->seen, 'nothing may ever be dispatched when the ledger write was refused');
        self::assertSame('preparing', $this->originations->findByUuid($uuid)['status'], 'left untouched');
        $stored = $this->events->findByDeliveryKey('fake', 'delivery-race-po');
        self::assertSame('failed', $stored['status']);

        // Retry after the interfering state resolves: advance the origination the rest of the
        // way to a status where 'provider_observed' IS legal, then replay the same delivery.
        self::assertTrue($this->originations->transition($this->context, $uuid, 'preparing', 'initializing'));
        self::assertTrue($this->originations->transition($this->context, $uuid, 'initializing', 'pending'));

        $service->processStored((string) $stored['uuid']);

        self::assertCount(1, $spy->seen, 'the retry must succeed once the interfering state resolves');
        self::assertSame('provider_observed', $this->originations->findByUuid($uuid)['status']);
    }

    /**
     * IMPORTANT finding 1 (late_settlement_conflict branch): every terminal status legally
     * permits `-> late_settlement_conflict` per the transition map (including the vacuous
     * self-loop), so this specific CAS can ONLY ever be refused by a genuine concurrent write
     * changing the row between our read and this write -- never by an illegal-transition
     * shortcut. A SQLite trigger fires exactly when this method's OWN guard-block write commits
     * (the real write ordering `correlateOriginationAndEnrich()` uses), mutating the origination
     * row out from under the subsequent ledger transition -- a faithful, deterministic
     * reproduction of the TOCTOU window without needing real OS-level concurrency.
     */
    public function testLateSettlementConflictCasRefusalThrowsAndDispatchesNothing(): void
    {
        $oldUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantRACE02',
            'subject_key' => 'subject-race-lsc',
            'status' => 'dispatched',
        ]);
        $newUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantRACE02',
            'subject_key' => 'subject-race-lsc',
            'status' => 'provider_observed',
        ]);
        $this->seedLiveGuard('tenantRACE02', 'subject-race-lsc', $newUuid);

        // Fires the instant the guard block this code path performs actually commits, racing the
        // origination out from under the subsequent transition() call. Both writes now share one
        // transaction (the RELATED hardening fix), so this trigger's own effect is itself inside
        // that same transaction -- a rollback undoes the guard block AND the trigger's mutation
        // together.
        $triggerName = 'race_lsc_' . substr(md5($oldUuid), 0, 8);
        $this->connection->getPDO()->exec(sprintf(
            "CREATE TRIGGER %s AFTER UPDATE OF state ON subscription_checkout_subject_guards "
                . "WHEN NEW.subject_key = '%s' AND NEW.state = 'blocked' "
                . "BEGIN UPDATE subscription_checkout_originations SET status = 'provider_observed' "
                . "WHERE uuid = '%s' AND status = 'dispatched'; END;",
            $triggerName,
            'subject-race-lsc',
            $oldUuid
        ));

        $spy = $this->strictSpy();
        $service = $this->webhookService($spy);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_race_lsc', 'delivery-race-lsc', [
            'gateway_subscription_id' => 'sub_race_lsc',
            'status' => 'active',
            'origination_uuid' => $oldUuid,
        ]);

        try {
            $service->ingest('fake', $body);
            self::fail('Expected the refused late_settlement_conflict CAS to propagate as a hard error');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('failed to advance checkout origination', $e->getMessage());
        }

        self::assertSame([], $spy->seen, 'nothing may ever be dispatched when the ledger write was refused');

        // RELATED hardening fix, asserted directly: the guard block and the trigger's mutation
        // were both rolled back together with the refused ledger transition -- the origination
        // and the guard are mutually consistent (both exactly as they were before this attempt),
        // never a guard stranded `blocked` with a ledger that never actually reached conflict.
        self::assertSame(
            'dispatched',
            $this->originations->findByUuid($oldUuid)['status'],
            'the guard block and the ledger transition must roll back together'
        );
        $guardAfterRefusal = $this->guardRow('tenantRACE02', 'subject-race-lsc');
        self::assertSame('live', $guardAfterRefusal['state'], 'the guard must never be left stranded blocked');
        self::assertSame($newUuid, $guardAfterRefusal['origination_uuid']);

        $stored = $this->events->findByDeliveryKey('fake', 'delivery-race-lsc');
        self::assertSame('failed', $stored['status']);

        // Retry after the interfering state resolves (the trigger fires identically on every
        // attempt otherwise, since the transaction now cleanly undoes it each time -- drop it to
        // simulate the concurrent interference actually having stopped): both writes land
        // together and the conflict is recorded correctly this time.
        $this->connection->getPDO()->exec('DROP TRIGGER ' . $triggerName);
        $service->processStored((string) $stored['uuid']);

        self::assertCount(1, $spy->seen, 'the retry must succeed once the interfering state resolves');
        self::assertSame('late_settlement_conflict', $this->originations->findByUuid($oldUuid)['status']);
        $guardAfterRetry = $this->guardRow('tenantRACE02', 'subject-race-lsc');
        self::assertSame('blocked', $guardAfterRetry['state']);
        self::assertSame($oldUuid, $guardAfterRetry['origination_uuid']);
    }

    /**
     * IMPORTANT finding 2 -- outcome-level proof: the newer origination's guard was released
     * (its checkout completed cleanly) BEFORE this webhook is ever processed. The code must never
     * force-block a subject whose newer checkout already succeeded: it correctly finds no live
     * newer owner and falls through to the terminal -> provider_observed re-bind instead of
     * conflicting. (The narrower CAS primitive itself -- refusing when the guard has ALREADY
     * moved out from under a write -- is proven directly and deterministically in
     * {@see testBlockIfBoundToOnlySucceedsAgainstTheExactExpectedLiveOrigination()}; a genuine
     * read-then-write race on the SAME connection with no intervening statement to hook a SQLite
     * trigger onto cannot be reproduced without real OS-level concurrency, which the ledger's own
     * `CheckoutOriginationLedgerTest` reserves for a pgsql-gated subprocess test instead.)
     */
    public function testGuardReleasedBetweenReadAndBlockFallsThroughToReBindInsteadOfOverBlocking(): void
    {
        $oldUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantRACE03',
            'subject_key' => 'subject-race-release',
            'status' => 'dispatched',
            'consumer_metadata' => $this->consumerMetadata('tenantRACE03'),
        ]);
        $newUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantRACE03',
            'subject_key' => 'subject-race-release',
            'status' => 'provider_observed',
        ]);
        $this->seedLiveGuard('tenantRACE03', 'subject-race-release', $newUuid);

        // Fires on the FIRST read this code path performs (findBySubject(), driven by a SELECT --
        // simulated here via a trigger on the guard row's own read-adjacent write instead, since
        // SQLite has no SELECT trigger: release the guard the instant our code's first write
        // attempt would occur, by pre-releasing it out of band before ingest() ever runs. This
        // reproduces the OBSERVABLE contract (guard no longer live for the newer origination by
        // the time the CAS write runs) without needing genuine concurrency.
        self::assertTrue($this->guards->release($this->context, 'tenantRACE03', 'subject-race-release', $newUuid));

        $spy = $this->strictSpy();
        $service = $this->webhookService($spy);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_released', 'delivery-released', [
            'gateway_subscription_id' => 'sub_released',
            'status' => 'active',
            'origination_uuid' => $oldUuid,
        ]);

        $result = $service->ingest('fake', $body);
        self::assertTrue($result->accepted);

        // No conflict: the old origination re-binds straight to provider_observed instead.
        self::assertSame('provider_observed', $this->originations->findByUuid($oldUuid)['status']);
        $guard = $this->guardRow('tenantRACE03', 'subject-race-release');
        self::assertSame('open', $guard['state'], 'a subject whose newer checkout completed must never be blocked');

        self::assertCount(1, $spy->seen);
        self::assertSame('tenantRACE03', $spy->seen[0]['metadata']['tenant_uuid']);
    }

    /**
     * IMPORTANT finding 2 -- the CAS primitive itself, tested directly and deterministically
     * (no trigger tricks needed): `blockIfBoundTo()` succeeds ONLY when the guard is currently
     * `live` AND bound to the EXACT expected origination; a mismatched binding, a non-`live`
     * state (`blocked`/`open`), or no row at all each refuse without writing anything.
     */
    public function testBlockIfBoundToOnlySucceedsAgainstTheExactExpectedLiveOrigination(): void
    {
        // No row at all yet: refused.
        self::assertFalse($this->guards->blockIfBoundTo(
            $this->context,
            'tenantCAS001',
            'subject-cas-none',
            'origXXXXXXX1',
            'origYYYYYYY1',
            'reason'
        ));

        // A different origination currently holds it live: refused, and the row is untouched.
        $this->seedLiveGuard('tenantCAS001', 'subject-cas-mismatch', 'origLIVEOWN1');
        self::assertFalse($this->guards->blockIfBoundTo(
            $this->context,
            'tenantCAS001',
            'subject-cas-mismatch',
            'origWRONGGU1',
            'origCONFLICT1',
            'reason'
        ));
        $unchanged = $this->guardRow('tenantCAS001', 'subject-cas-mismatch');
        self::assertSame('live', $unchanged['state']);
        self::assertSame('origLIVEOWN1', $unchanged['origination_uuid']);

        // Already blocked (not live): refused, regardless of which origination it names.
        $this->seedGuard('tenantCAS001', 'subject-cas-blocked', 'blocked');
        self::assertFalse($this->guards->blockIfBoundTo(
            $this->context,
            'tenantCAS001',
            'subject-cas-blocked',
            'origANYTHING1',
            'origCONFLICT2',
            'reason'
        ));

        // Open (no binding at all): refused.
        $this->seedGuard('tenantCAS001', 'subject-cas-open', 'open');
        self::assertFalse($this->guards->blockIfBoundTo(
            $this->context,
            'tenantCAS001',
            'subject-cas-open',
            'origANYTHING2',
            'origCONFLICT3',
            'reason'
        ));

        // Exact match: succeeds, and binds to the NEW (conflicted) origination as instructed.
        $this->seedLiveGuard('tenantCAS001', 'subject-cas-match', 'origEXPECTED1');
        self::assertTrue($this->guards->blockIfBoundTo(
            $this->context,
            'tenantCAS001',
            'subject-cas-match',
            'origEXPECTED1',
            'origCONFLICTED1',
            'the reason'
        ));
        $blocked = $this->guardRow('tenantCAS001', 'subject-cas-match');
        self::assertSame('blocked', $blocked['state']);
        self::assertSame('origCONFLICTED1', $blocked['origination_uuid']);
        self::assertSame('the reason', $blocked['blocked_reason']);
    }

    // ==================================================================
    // CRITICAL fix: re-entry with an already-conflicted origination must
    // NEVER attempt a transition (TRANSITIONS['late_settlement_conflict'] = []
    // makes every attempt permanently illegal -- Stripe echoes subscription
    // metadata for the subscription's lifetime, so this row would otherwise
    // fail closed FOREVER on every future webhook for it).
    // ==================================================================

    public function testReEntryWithAlreadyConflictedOriginationDispatchesWithoutThrowing(): void
    {
        $conflictedUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantALREADY1',
            'subject_key' => 'subject-already-conflicted',
            'status' => 'late_settlement_conflict',
            'consumer_metadata' => $this->consumerMetadata('tenantALREADY1'),
        ]);
        // The guard stays blocked, bound to the conflicted origination -- exactly what a real
        // late-settlement-conflict leaves behind (Task 9's operator reconciliation is the only
        // path that ever moves it again).
        $this->seedBlockedGuard('tenantALREADY1', 'subject-already-conflicted', $conflictedUuid);

        $spy = $this->strictSpy();
        $service = $this->webhookService($spy);

        // Stripe echoes the same subscription metadata (incl. origination_uuid) on every
        // subsequent event for this subscription -- e.g. a later customer.subscription.updated.
        $body = $this->fakeBody(EventType::SUBSCRIPTION_UPDATED, 'sub_already', 'delivery-already-1', [
            'gateway_subscription_id' => 'sub_already',
            'status' => 'past_due',
            'origination_uuid' => $conflictedUuid,
        ]);

        $result = $service->ingest('fake', $body);
        self::assertTrue($result->accepted, 'a re-entry against an already-conflicted origination must not throw');

        self::assertCount(1, $spy->seen, 'the event must still dispatch so the consumer keeps rejecting it');
        self::assertSame('tenantALREADY1', $spy->seen[0]['metadata']['tenant_uuid']);

        // Nothing about the ledger or the guard moved.
        self::assertSame('late_settlement_conflict', $this->originations->findByUuid($conflictedUuid)['status']);
        $guard = $this->guardRow('tenantALREADY1', 'subject-already-conflicted');
        self::assertSame('blocked', $guard['state']);
        self::assertSame($conflictedUuid, $guard['origination_uuid']);

        // A second, independent delivery for the same already-conflicted origination (e.g. yet
        // another later webhook) is equally safe -- never a one-time fluke, never fails closed.
        $secondBody = $this->fakeBody(EventType::SUBSCRIPTION_UPDATED, 'sub_already', 'delivery-already-2', [
            'gateway_subscription_id' => 'sub_already',
            'status' => 'canceled',
            'origination_uuid' => $conflictedUuid,
        ]);
        $secondResult = $service->ingest('fake', $secondBody);
        self::assertTrue($secondResult->accepted);
        self::assertCount(2, $spy->seen);
        self::assertSame('late_settlement_conflict', $this->originations->findByUuid($conflictedUuid)['status']);
        self::assertSame('blocked', $this->guardRow('tenantALREADY1', 'subject-already-conflicted')['state']);
    }

    public function testReEntryWithAlreadyConflictedOriginationIsIdempotentAcrossRepeatDeliveries(): void
    {
        $conflictedUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantALREADY2',
            'subject_key' => 'subject-already-conflicted-2',
            'status' => 'late_settlement_conflict',
        ]);
        $this->seedBlockedGuard('tenantALREADY2', 'subject-already-conflicted-2', $conflictedUuid);

        // Calling applyProviderEvent() directly (byte-identical logical redelivery, e.g. a real
        // webhook retry) three times in a row must never throw and must never mutate anything.
        for ($i = 0; $i < 3; $i++) {
            $event = ProviderEvent::create(
                'fake',
                EventType::SUBSCRIPTION_UPDATED,
                null,
                'delivery-idempotent-' . $i,
                'sub_idempotent',
                new \DateTimeImmutable(),
                [
                    'gateway_subscription_id' => 'sub_idempotent',
                    'status' => 'past_due',
                    'origination_uuid' => $conflictedUuid,
                ],
                ['raw' => true],
            );

            $replacement = $this->service()->applyProviderEvent($event);
            self::assertNotNull($replacement, 'the conflicted event must still be dispatched, not swallowed');
        }

        self::assertSame('late_settlement_conflict', $this->originations->findByUuid($conflictedUuid)['status']);
        $guard = $this->guardRow('tenantALREADY2', 'subject-already-conflicted-2');
        self::assertSame('blocked', $guard['state']);
        self::assertSame($conflictedUuid, $guard['origination_uuid']);
    }

    // ==================================================================
    // Terminal -> provider_observed re-bind when no newer owner exists
    // ==================================================================

    public function testTerminalReBindsToProviderObservedWhenNoNewerOwnerExists(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantREBIND1',
            'subject_key' => 'subject-rebind',
            'status' => 'failed',
            'consumer_metadata' => $this->consumerMetadata('tenantREBIND1'),
        ]);
        // Guard already released back to 'open' -- exactly what a normal 'failed' terminal
        // transition leaves behind. No newer origination has claimed the subject.
        $this->seedGuard('tenantREBIND1', 'subject-rebind', 'open');

        $event = ProviderEvent::create(
            'fake',
            EventType::SUBSCRIPTION_CREATED,
            null,
            'delivery-rebind',
            'sub_rebind',
            new \DateTimeImmutable(),
            [
                'gateway_subscription_id' => 'sub_rebind',
                'status' => 'active',
                'origination_uuid' => $uuid,
            ],
            ['raw' => true],
        );

        $replacement = $this->service()->applyProviderEvent($event);

        self::assertNotNull($replacement);
        self::assertSame('tenantREBIND1', $replacement->normalized()['metadata']['tenant_uuid']);

        $origination = $this->originations->findByUuid($uuid);
        self::assertSame('provider_observed', $origination['status']);
        self::assertSame('sub_rebind', $origination['provider_subscription_id']);

        // No guard action is sanctioned for this branch -- it stays exactly as it was.
        $guard = $this->guardRow('tenantREBIND1', 'subject-rebind');
        self::assertSame('open', $guard['state']);
    }

    // ==================================================================
    // Stripe checkout.session.expired: ledger lifecycle, not a subscription event
    // ==================================================================

    public function testStripeCheckoutExpiredTransitionsPendingToExpiredAndReleasesGuardPreDispatch(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantEXP001',
            'subject_key' => 'subject-expire',
            'status' => 'pending',
            'gateway' => 'stripe',
            'checkout_reference' => 'cs_test_expire1',
        ]);
        $this->seedLiveGuard('tenantEXP001', 'subject-expire', $uuid);

        $event = $this->stripeGateway()->parseWebhookEvent(json_encode([
            'id' => 'evt_expire_1',
            'type' => 'checkout.session.expired',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'cs_test_expire1',
                    'object' => 'checkout.session',
                    'status' => 'expired',
                    'metadata' => ['origination_uuid' => $uuid],
                ],
            ],
        ], JSON_THROW_ON_ERROR), []);

        self::assertSame(EventType::CHECKOUT_EXPIRED, $event->type());

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $service = new GatewaySubscriptionService(
            $this->context,
            $this->subscriptions,
            $manager,
            $this->originations,
            $this->guards,
        );

        $replacement = $service->applyProviderEvent($event);
        self::assertNull($replacement, 'a pure ledger-lifecycle event carries no enrichment to persist');

        self::assertSame('expired', $this->originations->findByUuid($uuid)['status']);
        self::assertSame('open', $this->guardRow('tenantEXP001', 'subject-expire')['state']);

        // No gateway_subscriptions row was ever touched -- this is never a subscription event.
        self::assertSame(0, $this->connection->table('gateway_subscriptions')->count());

        // Idempotent redelivery: replaying the identical event a second time changes nothing and
        // never throws.
        $again = $service->applyProviderEvent($event);
        self::assertNull($again);
        self::assertSame('expired', $this->originations->findByUuid($uuid)['status']);
        self::assertSame('open', $this->guardRow('tenantEXP001', 'subject-expire')['state']);
    }

    public function testStripeCheckoutExpiredForUnknownReferenceIsSilentlyIgnored(): void
    {
        $event = $this->stripeGateway()->parseWebhookEvent(json_encode([
            'id' => 'evt_expire_2',
            'type' => 'checkout.session.expired',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'cs_test_unknown',
                    'object' => 'checkout.session',
                    'status' => 'expired',
                ],
            ],
        ], JSON_THROW_ON_ERROR), []);

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $service = new GatewaySubscriptionService(
            $this->context,
            $this->subscriptions,
            $manager,
            $this->originations,
            $this->guards,
        );

        self::assertNull($service->applyProviderEvent($event));
    }

    // ==================================================================
    // Regression: no origination token present falls through unaffected (Rule 3/4 untouched)
    // ==================================================================

    public function testNoOriginationTokenFallsThroughToBillingPlanDerivationUnaffected(): void
    {
        $this->connection->table('billing_plans')->insert([
            'uuid' => 'planREGRESS1',
            'tenant_uuid' => 'tenantPLAN001',
            'name' => 'Regression Plan',
            'amount' => 1000,
            'currency' => 'GHS',
        ]);

        $event = ProviderEvent::create(
            'fake',
            EventType::SUBSCRIPTION_CREATED,
            null,
            'delivery-regress',
            'sub_regress',
            new \DateTimeImmutable(),
            [
                'gateway_subscription_id' => 'sub_regress',
                'billing_plan_uuid' => 'planREGRESS1',
                'status' => 'active',
            ],
            ['raw' => true],
        );

        $replacement = $this->service()->applyProviderEvent($event);

        self::assertNull($replacement, 'the billing_plan_uuid path never returns a replacement');
        $projection = $this->subscriptions->findGatewaySubscriptionByGatewayId('fake', 'sub_regress');
        self::assertSame('tenantPLAN001', $projection['tenant_uuid']);
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /** @return array<string,mixed> */
    private function consumerMetadata(string $tenantUuid): array
    {
        self::$seq++;

        return [
            'tenant_uuid' => $tenantUuid,
            'subject_type' => 'workspace',
            'subject_uuid' => 'wsHAPPY' . str_pad((string) self::$seq, 4, '0', STR_PAD_LEFT),
            'plan_uuid' => 'planHAPPY' . str_pad((string) self::$seq, 2, '0', STR_PAD_LEFT),
            'glueful_consumer' => 'subscriptions',
            'actor_user_uuid' => 'actorUUID' . str_pad((string) self::$seq, 3, '0', STR_PAD_LEFT),
        ];
    }

    /** Inserts a checkout origination row directly at an arbitrary status. */
    private function seedOrigination(array $overrides = []): string
    {
        self::$seq++;
        $uuid = 'orig' . str_pad((string) self::$seq, 8, '0', STR_PAD_LEFT);
        $consumerMetadata = $overrides['consumer_metadata'] ?? null;
        unset($overrides['consumer_metadata']);

        $this->connection->table('subscription_checkout_originations')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'subject_key' => 'subject-' . self::$seq,
            'gateway' => 'fake',
            'provider_plan_identifier' => 'plan_' . self::$seq,
            'idempotency_key' => 'idem-' . self::$seq . '-' . bin2hex(random_bytes(4)),
            'request_fingerprint' => str_repeat('a', 64),
            'return_url' => 'https://shop.example.test/return',
            'cancel_url' => 'https://shop.example.test/cancel',
            'status' => 'pending',
            'live' => !in_array($overrides['status'] ?? 'pending', [
                'dispatched', 'failed', 'expired', 'abandoned', 'projection_rejected', 'late_settlement_conflict',
            ], true),
            'consumer_metadata' => $consumerMetadata !== null
                ? json_encode($consumerMetadata, JSON_THROW_ON_ERROR)
                : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], $overrides));

        return $uuid;
    }

    private function seedGuard(string $tenantUuid, string $subjectKey, string $state): void
    {
        self::$seq++;
        $this->connection->table('subscription_checkout_subject_guards')->insert([
            'uuid' => 'grd' . str_pad((string) self::$seq, 9, '0', STR_PAD_LEFT),
            'tenant_uuid' => $tenantUuid,
            'subject_key' => $subjectKey,
            'state' => $state,
            'revision' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function seedLiveGuard(string $tenantUuid, string $subjectKey, string $originationUuid): void
    {
        self::$seq++;
        $this->connection->table('subscription_checkout_subject_guards')->insert([
            'uuid' => 'grd' . str_pad((string) self::$seq, 9, '0', STR_PAD_LEFT),
            'tenant_uuid' => $tenantUuid,
            'subject_key' => $subjectKey,
            'state' => 'live',
            'origination_uuid' => $originationUuid,
            'revision' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function seedBlockedGuard(string $tenantUuid, string $subjectKey, string $originationUuid): void
    {
        self::$seq++;
        $this->connection->table('subscription_checkout_subject_guards')->insert([
            'uuid' => 'grd' . str_pad((string) self::$seq, 9, '0', STR_PAD_LEFT),
            'tenant_uuid' => $tenantUuid,
            'subject_key' => $subjectKey,
            'state' => 'blocked',
            'origination_uuid' => $originationUuid,
            'blocked_reason' => 'already conflicted',
            'revision' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed> */
    private function guardRow(string $tenantUuid, string $subjectKey): array
    {
        $row = $this->connection->table('subscription_checkout_subject_guards')
            ->where(['tenant_uuid' => $tenantUuid, 'subject_key' => $subjectKey])
            ->limit(1)
            ->first();
        self::assertNotNull($row, "expected a guard row for {$tenantUuid}/{$subjectKey}");

        return $row;
    }

    private function service(): GatewaySubscriptionService
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);

        return new GatewaySubscriptionService(
            $this->context,
            $this->subscriptions,
            $manager,
            $this->originations,
            $this->guards,
        );
    }

    private function webhookService(object $spy): WebhookService
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);
        $subscriptions = new GatewaySubscriptionService(
            $this->context,
            $this->subscriptions,
            $manager,
            $this->originations,
            $this->guards,
        );
        $applier = static fn (
            PaymentProviderEventInterface $event
        ): ?PaymentProviderEventInterface => $subscriptions->applyProviderEvent($event);

        return new WebhookService(
            context: $this->context,
            gateways: $manager,
            events: $this->events,
            dispatcher: $this->dispatcherToSpy($spy),
            applier: $applier,
            payloadUpdater: $this->events,
        );
    }

    /** @param array<string,mixed> $normalized */
    private function fakeBody(string $type, string $entityId, string $deliveryKey, array $normalized): string
    {
        return json_encode([
            'gateway' => 'fake',
            'type' => $type,
            'entity_id' => $entityId,
            'delivery_key' => $deliveryKey,
            'normalized' => $normalized,
        ], JSON_THROW_ON_ERROR);
    }

    private function strictSpy(): object
    {
        return new class implements StrictPaymentEventListener {
            /** @var list<array<string,mixed>> */
            public array $seen = [];

            public function supports(PaymentProviderEventInterface $event): bool
            {
                return true;
            }

            public function handle(PaymentProviderEventInterface $event): void
            {
                $this->seen[] = $event->normalized();
            }
        };
    }

    private function dispatcherToSpy(object $spy): callable
    {
        return static function (PaymentProviderEvent $event) use ($spy): void {
            if ($spy->supports($event->event)) {
                $spy->handle($event->event);
            }
        };
    }

    /**
     * `parseWebhookEvent()` never reads gateway config (normalizeType/normalizePayload/entityId/
     * occurredAt are all pure functions of the payload) -- no ConfigurationLoader setup needed,
     * unlike tests that drive signature verification or provider I/O.
     */
    private function stripeGateway(): StripeGateway
    {
        return new StripeGateway($this->createMock(HttpClient::class), $this->context);
    }
}
