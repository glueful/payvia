<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Checkout;

use Glueful\Extensions\Payvia\Checkout\ProjectionAcknowledgementRefused;
use Glueful\Extensions\Payvia\Checkout\RequiredProjectionAcknowledgementMissing;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Payvia\Database\Migrations\AddProviderEventDispatchClaimToken;
use Glueful\Extensions\Payvia\Database\Migrations\CreateBillingPlansTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateCheckoutOriginations;
use Glueful\Extensions\Payvia\Database\Migrations\CreateGatewaySubscriptionsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\GatewaySubscriptionService;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Glueful\Extensions\Payvia\Tests\Support\RecordingAckListener;

/**
 * Task 8 (workspace self-serve checkout, design spec §3.6): the durable projection
 * acknowledgement CAS writer ({@see CheckoutOriginationRepository::acknowledge()}) and the
 * post-dispatch finalizer wired into `WebhookService::dispatch()`.
 *
 * Two layers of coverage:
 * - The ack CAS matrix, exercised directly against `CheckoutOriginationRepository::acknowledge()`
 *   (accept / repeat / conflict / wrong consumer / wrong state / late_settlement_conflict).
 * - The finalizer, exercised through the REAL `WebhookService` plumbing (`ingest()`/
 *   `processStored()`), with a {@see RecordingAckListener} standing in for subscriptions 2.2's
 *   strict bridge -- proving the finalizer only ever sees an acknowledgement that a strict
 *   listener actually wrote during THIS SAME composed dispatch.
 */
final class ProjectionAcknowledgementTest extends PayviaTestCase
{
    private const CONSUMER = 'subscriptions';

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
        (new AddProviderEventDispatchClaimToken())->up($schema);

        $this->subscriptions = new ProviderCorrelationRepository($this->connection);
        $this->originations = new CheckoutOriginationRepository($this->connection);
        $this->guards = new CheckoutSubjectGuardRepository($this->connection);
        $this->events = new ProviderEventRepository($this->connection);
        $this->bind(FakeWebhookGateway::class, new FakeWebhookGateway());
    }

    // ==================================================================
    // §3.6 ack CAS matrix -- CheckoutOriginationRepository::acknowledge()
    // ==================================================================

    public function testAcknowledgeAcceptedRecordsReceiptWithoutChangingStatus(): void
    {
        $uuid = $this->seedOrigination([
            'status' => 'provider_observed',
            'required_projection_consumer' => self::CONSUMER,
        ]);

        $this->originations->acknowledge($uuid, self::CONSUMER, 'subscription.created:sub_ack1', 'accepted');

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('provider_observed', $row['status'], 'acknowledge() never moves status by itself');
        self::assertSame('subscription.created:sub_ack1', $row['projection_event_key']);
        self::assertSame('accepted', $row['projection_outcome']);
        self::assertNull($row['projection_reason']);
    }

    public function testAcknowledgeRepeatSameOutcomeIsNoOp(): void
    {
        $uuid = $this->seedOrigination([
            'status' => 'provider_observed',
            'required_projection_consumer' => self::CONSUMER,
        ]);

        $this->originations->acknowledge(
            $uuid,
            self::CONSUMER,
            'subscription.created:sub_repeat',
            'rejected',
            'origination_mismatch'
        );
        // A repeat delivery re-reading its own receipt and re-calling with the SAME outcome must
        // never throw.
        $this->originations->acknowledge(
            $uuid,
            self::CONSUMER,
            'subscription.created:sub_repeat',
            'rejected',
            'origination_mismatch'
        );

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('rejected', $row['projection_outcome']);
        self::assertSame('origination_mismatch', $row['projection_reason']);
    }

    public function testAcknowledgeConflictingSecondOutcomeThrows(): void
    {
        $uuid = $this->seedOrigination([
            'status' => 'provider_observed',
            'required_projection_consumer' => self::CONSUMER,
        ]);

        $this->originations->acknowledge($uuid, self::CONSUMER, 'subscription.created:sub_conflict', 'accepted');

        try {
            $this->originations->acknowledge($uuid, self::CONSUMER, 'subscription.created:sub_conflict', 'rejected');
            self::fail('Expected a conflicting second outcome to throw');
        } catch (ProjectionAcknowledgementRefused $e) {
            self::assertStringContainsString(ProjectionAcknowledgementRefused::MARKER, $e->getMessage());
        }

        // The FIRST outcome must survive a refused conflicting write untouched.
        self::assertSame('accepted', $this->originations->findByUuid($uuid)['projection_outcome']);
    }

    public function testAcknowledgeWrongConsumerIsRefused(): void
    {
        $uuid = $this->seedOrigination([
            'status' => 'provider_observed',
            'required_projection_consumer' => self::CONSUMER,
        ]);

        try {
            $this->originations->acknowledge($uuid, 'some_other_consumer', 'subscription.created:sub_x', 'accepted');
            self::fail('Expected a wrong-consumer acknowledgement to be refused');
        } catch (ProjectionAcknowledgementRefused $e) {
            self::assertStringContainsString(ProjectionAcknowledgementRefused::MARKER, $e->getMessage());
        }

        $row = $this->originations->findByUuid($uuid);
        self::assertNull($row['projection_event_key'], 'a refused acknowledgement must write nothing');
    }

    public function testAcknowledgeWrongStateIsRefused(): void
    {
        $uuid = $this->seedOrigination([
            'status' => 'pending',
            'required_projection_consumer' => self::CONSUMER,
        ]);

        try {
            $this->originations->acknowledge($uuid, self::CONSUMER, 'subscription.created:sub_x', 'accepted');
            self::fail('Expected an acknowledgement against a non-provider_observed status to be refused');
        } catch (ProjectionAcknowledgementRefused $e) {
            self::assertStringContainsString(ProjectionAcknowledgementRefused::MARKER, $e->getMessage());
        }

        self::assertSame('pending', $this->originations->findByUuid($uuid)['status']);
    }

    public function testAcknowledgeUnknownOriginationIsRefused(): void
    {
        try {
            $this->originations->acknowledge('origMISSING01', self::CONSUMER, 'subscription.created:sub_x', 'accepted');
            self::fail('Expected acknowledging an unknown origination to be refused');
        } catch (ProjectionAcknowledgementRefused $e) {
            self::assertStringContainsString(ProjectionAcknowledgementRefused::MARKER, $e->getMessage());
        }
    }

    public function testAcknowledgeLateSettlementConflictAcceptsOnlyMatchingRejected(): void
    {
        $uuid = $this->seedOrigination([
            'status' => 'late_settlement_conflict',
            'required_projection_consumer' => self::CONSUMER,
        ]);

        $this->originations->acknowledge(
            $uuid,
            self::CONSUMER,
            'subscription.created:sub_lsc',
            'rejected',
            'origination_mismatch'
        );

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('late_settlement_conflict', $row['status'], 'status must never change');
        self::assertSame('subscription.created:sub_lsc', $row['projection_event_key']);
        self::assertSame('rejected', $row['projection_outcome']);
        self::assertSame('origination_mismatch', $row['projection_reason']);
    }

    public function testAcknowledgeLateSettlementConflictAcceptedIsRefused(): void
    {
        $uuid = $this->seedOrigination([
            'status' => 'late_settlement_conflict',
            'required_projection_consumer' => self::CONSUMER,
        ]);

        try {
            $this->originations->acknowledge($uuid, self::CONSUMER, 'subscription.created:sub_lsc2', 'accepted');
            self::fail('Expected an accepted acknowledgement against a conflict to be refused loudly');
        } catch (ProjectionAcknowledgementRefused $e) {
            self::assertStringContainsString(ProjectionAcknowledgementRefused::MARKER, $e->getMessage());
        }

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('late_settlement_conflict', $row['status']);
        self::assertNull($row['projection_event_key'], 'a refused acknowledgement must write nothing');
    }

    /**
     * Crash-after-projection-before-acknowledgement recovery (design spec §3.6): a duplicate
     * delivery re-reads its own already-persisted receipt and re-calls `acknowledge()` with the
     * SAME outcome it originally computed. This must stay a safe no-op even when, by the time the
     * duplicate call lands, the origination has ALREADY advanced past `provider_observed` (e.g.
     * the FIRST call's acknowledgement already let the finalizer complete) -- proving the repeat
     * check is evaluated before (and independently of) the current status/consumer gate.
     */
    public function testAcknowledgeRepeatAfterOriginationAlreadyAdvancedIsStillNoOp(): void
    {
        $uuid = $this->seedOrigination([
            'status' => 'provider_observed',
            'required_projection_consumer' => self::CONSUMER,
        ]);

        $this->originations->acknowledge($uuid, self::CONSUMER, 'subscription.created:sub_dup', 'accepted');
        self::assertTrue($this->originations->transition($this->context, $uuid, 'provider_observed', 'dispatched'));

        // Simulated consumer double: the exact same tuple, called again after the origination has
        // already moved on.
        $this->originations->acknowledge($uuid, self::CONSUMER, 'subscription.created:sub_dup', 'accepted');

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('dispatched', $row['status'], 'the repeat must never touch status');
        self::assertSame('accepted', $row['projection_outcome']);
    }

    /**
     * `projection_reason` is a `VARCHAR(255)` column and the spec calls it a "bounded reason"
     * twice -- an over-length value must be truncated (not left to hard-fail against a strict DB
     * engine, which would leave the event retrying forever with the identical over-length
     * reason). The truncation must also be stable across a repeat delivery with the SAME
     * over-length reason: the second call must still be recognized as an idempotent no-op rather
     * than throwing.
     */
    public function testAcknowledgeTruncatesOverLengthReasonAndStaysStableOnRetry(): void
    {
        $uuid = $this->seedOrigination([
            'status' => 'provider_observed',
            'required_projection_consumer' => self::CONSUMER,
        ]);
        $longReason = str_repeat('x', 500);
        $logicalEventKey = 'subscription.created:sub_bound';

        $this->originations->acknowledge($uuid, self::CONSUMER, $logicalEventKey, 'rejected', $longReason);

        $row = $this->originations->findByUuid($uuid);
        self::assertSame(255, strlen((string) $row['projection_reason']));
        self::assertSame(substr($longReason, 0, 255), $row['projection_reason']);

        // A retry delivering the identical (outcome, over-length reason) tuple must stay a no-op,
        // not throw.
        $this->originations->acknowledge($uuid, self::CONSUMER, $logicalEventKey, 'rejected', $longReason);
        self::assertSame(substr($longReason, 0, 255), $this->originations->findByUuid($uuid)['projection_reason']);
    }

    /** Truncation is mb-safe: it must never split a multi-byte character in half. */
    public function testAcknowledgeTruncatesOverLengthMultiByteReasonOnACharacterBoundary(): void
    {
        $uuid = $this->seedOrigination([
            'status' => 'provider_observed',
            'required_projection_consumer' => self::CONSUMER,
        ]);
        // 300 three-byte UTF-8 characters (900 bytes, 300 mb_strlen) -- well past the 255 bound.
        $multiByteReason = str_repeat('日', 300);

        $this->originations->acknowledge(
            $uuid,
            self::CONSUMER,
            'subscription.created:sub_mb',
            'rejected',
            $multiByteReason
        );

        $persisted = (string) $this->originations->findByUuid($uuid)['projection_reason'];
        self::assertSame(255, mb_strlen($persisted));
        self::assertTrue(mb_check_encoding($persisted, 'UTF-8'), 'truncation must never split a character');
        self::assertSame(mb_substr($multiByteReason, 0, 255), $persisted);
    }

    // ==================================================================
    // §3.6 finalizer -- through the real WebhookService plumbing
    // ==================================================================

    public function testFinalizerAcceptedTransitionsToDispatchedAndReleasesGuard(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0001',
            'subject_key' => 'subject-ack-accept',
            'status' => 'pending',
            'required_projection_consumer' => self::CONSUMER,
            'consumer_metadata' => $this->consumerMetadata('tenantACK0001'),
        ]);
        $this->seedLiveGuard('tenantACK0001', 'subject-ack-accept', $uuid);

        $listener = new RecordingAckListener($this->originations, self::CONSUMER, 'accepted');
        $service = $this->webhookService([$listener]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_accept', 'delivery-ack-accept', [
            'gateway_subscription_id' => 'sub_ack_accept',
            'status' => 'active',
            'origination_uuid' => $uuid,
        ]);

        $result = $service->ingest('fake', $body);
        self::assertTrue($result->accepted);
        self::assertCount(1, $listener->handled);

        $origination = $this->originations->findByUuid($uuid);
        self::assertSame('dispatched', $origination['status']);

        $guard = $this->guardRow('tenantACK0001', 'subject-ack-accept');
        self::assertSame('open', $guard['state'], 'the guard must be released on an accepted acknowledgement');

        self::assertSame('dispatched', $this->dispatchStatusFor('delivery-ack-accept'));
    }

    public function testFinalizerRejectedTransitionsToProjectionRejectedRetainsGuardAndEventStillDispatches(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0002',
            'subject_key' => 'subject-ack-reject',
            'status' => 'pending',
            'required_projection_consumer' => self::CONSUMER,
            'consumer_metadata' => $this->consumerMetadata('tenantACK0002'),
        ]);
        $this->seedLiveGuard('tenantACK0002', 'subject-ack-reject', $uuid);

        $listener = new RecordingAckListener(
            $this->originations,
            self::CONSUMER,
            'rejected',
            'plan_no_longer_available'
        );
        $service = $this->webhookService([$listener]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_reject', 'delivery-ack-reject', [
            'gateway_subscription_id' => 'sub_ack_reject',
            'status' => 'active',
            'origination_uuid' => $uuid,
        ]);

        $result = $service->ingest('fake', $body);
        self::assertTrue($result->accepted, 'the deterministic rejection still lets the provider event dispatch');

        $origination = $this->originations->findByUuid($uuid);
        self::assertSame('projection_rejected', $origination['status']);
        self::assertSame('plan_no_longer_available', $origination['projection_reason']);

        $guard = $this->guardRow('tenantACK0002', 'subject-ack-reject');
        self::assertSame('live', $guard['state'], 'a rejected acknowledgement must retain the guard');
        self::assertSame($uuid, $guard['origination_uuid']);

        self::assertSame('dispatched', $this->dispatchStatusFor('delivery-ack-reject'));
    }

    public function testFinalizerNoRequiredConsumerCompletesGenericDispatch(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0003',
            'subject_key' => 'subject-ack-generic',
            'status' => 'pending',
            'consumer_metadata' => $this->consumerMetadata('tenantACK0003'),
        ]);
        $this->seedLiveGuard('tenantACK0003', 'subject-ack-generic', $uuid);

        $service = $this->webhookService([]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_generic', 'delivery-ack-generic', [
            'gateway_subscription_id' => 'sub_ack_generic',
            'status' => 'active',
            'origination_uuid' => $uuid,
        ]);

        $result = $service->ingest('fake', $body);
        self::assertTrue($result->accepted);

        self::assertSame('dispatched', $this->originations->findByUuid($uuid)['status']);
        self::assertSame('open', $this->guardRow('tenantACK0003', 'subject-ack-generic')['state']);
    }

    /**
     * CRITICAL (code review finding): `completeOriginationDispatch()`'s `provider_observed ->
     * dispatched` transition and its guard release must be ATOMIC. A `CREATE TRIGGER` fires the
     * instant the origination's own status UPDATE commits -- a faithful, deterministic
     * reproduction of a concurrent operator `block()` landing between the two writes, without
     * needing real OS-level concurrency (mirrors the identical technique already used for
     * `GatewaySubscriptionService`'s late-settlement-conflict TOCTOU tests). Without atomicity,
     * the origination would be left `dispatched` with its guard permanently stranded `blocked`
     * (unreachable by any future retry, since `finalizeOrigination()`'s "already past
     * provider_observed" branch is a silent no-op). With atomicity, the refused release rolls
     * BOTH writes back, so the origination stays `provider_observed` and a later retry -- once
     * the interference has cleared -- lands both writes together.
     */
    public function testFinalizerAcceptedAtomicGuardReleaseFailureRollsBackTransitionAndRetrySucceeds(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0009',
            'subject_key' => 'subject-ack-atomic-acc',
            'status' => 'pending',
            'required_projection_consumer' => self::CONSUMER,
            'consumer_metadata' => $this->consumerMetadata('tenantACK0009'),
        ]);
        $this->seedLiveGuard('tenantACK0009', 'subject-ack-atomic-acc', $uuid);

        $triggerName = $this->armConcurrentGuardBlockTrigger($uuid, 'tenantACK0009', 'subject-ack-atomic-acc');

        $listener = new RecordingAckListener($this->originations, self::CONSUMER, 'accepted');
        $service = $this->webhookService([$listener]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_atomic_acc', 'delivery-ack-atomic-acc', [
            'gateway_subscription_id' => 'sub_ack_atomic_acc',
            'status' => 'active',
            'origination_uuid' => $uuid,
        ]);

        try {
            $service->ingest('fake', $body);
            self::fail('Expected the refused guard release to propagate and roll back the transition');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('subject guard could not be released', $e->getMessage());
        }

        self::assertSame(
            'provider_observed',
            $this->originations->findByUuid($uuid)['status'],
            'the dispatched transition must roll back together with the refused guard release'
        );
        $guardAfterRefusal = $this->guardRow('tenantACK0009', 'subject-ack-atomic-acc');
        self::assertSame('live', $guardAfterRefusal['state'], 'the guard must never be left stranded blocked');
        self::assertSame($uuid, $guardAfterRefusal['origination_uuid']);
        self::assertSame('pending', $this->dispatchStatusFor('delivery-ack-atomic-acc'), 'the lease must be released');

        // The interference has stopped -- retry lands both writes together.
        $this->connection->getPDO()->exec('DROP TRIGGER ' . $triggerName);
        $stored = $this->events->findByDeliveryKey('fake', 'delivery-ack-atomic-acc');
        $service->processStored((string) $stored['uuid']);

        self::assertSame('dispatched', $this->originations->findByUuid($uuid)['status']);
        self::assertSame('open', $this->guardRow('tenantACK0009', 'subject-ack-atomic-acc')['state']);
        self::assertSame('dispatched', $this->dispatchStatusFor('delivery-ack-atomic-acc'));
    }

    /** Same atomicity guarantee, exercised through the "no required consumer" generic-completion path. */
    public function testFinalizerNoRequiredConsumerAtomicGuardReleaseFailureRollsBackTransitionAndRetrySucceeds(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0010',
            'subject_key' => 'subject-ack-atomic-generic',
            'status' => 'pending',
            'consumer_metadata' => $this->consumerMetadata('tenantACK0010'),
        ]);
        $this->seedLiveGuard('tenantACK0010', 'subject-ack-atomic-generic', $uuid);

        $triggerName = $this->armConcurrentGuardBlockTrigger($uuid, 'tenantACK0010', 'subject-ack-atomic-generic');

        $service = $this->webhookService([]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_atomic_gen', 'delivery-ack-atomic-gen', [
            'gateway_subscription_id' => 'sub_ack_atomic_gen',
            'status' => 'active',
            'origination_uuid' => $uuid,
        ]);

        try {
            $service->ingest('fake', $body);
            self::fail('Expected the refused guard release to propagate and roll back the transition');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('subject guard could not be released', $e->getMessage());
        }

        self::assertSame('provider_observed', $this->originations->findByUuid($uuid)['status']);
        $guardAfterRefusal = $this->guardRow('tenantACK0010', 'subject-ack-atomic-generic');
        self::assertSame('live', $guardAfterRefusal['state']);
        self::assertSame('pending', $this->dispatchStatusFor('delivery-ack-atomic-gen'));

        $this->connection->getPDO()->exec('DROP TRIGGER ' . $triggerName);
        $stored = $this->events->findByDeliveryKey('fake', 'delivery-ack-atomic-gen');
        $service->processStored((string) $stored['uuid']);

        self::assertSame('dispatched', $this->originations->findByUuid($uuid)['status']);
        self::assertSame('open', $this->guardRow('tenantACK0010', 'subject-ack-atomic-generic')['state']);
        self::assertSame('dispatched', $this->dispatchStatusFor('delivery-ack-atomic-gen'));
    }

    /**
     * Missing acknowledgement throws `RequiredProjectionAcknowledgementMissing`, releases the
     * logical dispatch lease (leaving `dispatch_status = pending`, immediately retryable, not
     * stuck `dispatching`), and a LATER retry -- once the required consumer's acknowledgement has
     * finally landed -- succeeds cleanly.
     */
    public function testFinalizerMissingAckThrowsLeasesReleasedAndRetrySucceedsAfterLateAck(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0004',
            'subject_key' => 'subject-ack-missing',
            'status' => 'pending',
            'required_projection_consumer' => self::CONSUMER,
            'consumer_metadata' => $this->consumerMetadata('tenantACK0004'),
        ]);
        $this->seedLiveGuard('tenantACK0004', 'subject-ack-missing', $uuid);

        // The listener runs (it "projects") but does not acknowledge -- an unmapped/transient
        // outcome the projector itself has nothing definitive to say about yet.
        $listener = new RecordingAckListener($this->originations, self::CONSUMER, null);
        $service = $this->webhookService([$listener]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_missing', 'delivery-ack-missing', [
            'gateway_subscription_id' => 'sub_ack_missing',
            'status' => 'active',
            'origination_uuid' => $uuid,
        ]);

        try {
            $service->ingest('fake', $body);
            self::fail('Expected RequiredProjectionAcknowledgementMissing to propagate');
        } catch (RequiredProjectionAcknowledgementMissing $e) {
            self::assertStringContainsString(RequiredProjectionAcknowledgementMissing::MARKER, $e->getMessage());
        }

        self::assertCount(1, $listener->handled);
        self::assertSame('provider_observed', $this->originations->findByUuid($uuid)['status']);
        self::assertSame('pending', $this->dispatchStatusFor('delivery-ack-missing'), 'the lease must be released');

        // The required consumer's acknowledgement lands out of band (e.g. a slower, independent
        // projector run) before the retry.
        $this->originations->acknowledge(
            $uuid,
            self::CONSUMER,
            EventType::SUBSCRIPTION_CREATED . ':sub_ack_missing',
            'accepted'
        );

        $stored = $this->events->findByDeliveryKey('fake', 'delivery-ack-missing');
        $service->processStored((string) $stored['uuid']);

        self::assertSame('dispatched', $this->originations->findByUuid($uuid)['status']);
        self::assertSame('open', $this->guardRow('tenantACK0004', 'subject-ack-missing')['state']);
        self::assertSame('dispatched', $this->dispatchStatusFor('delivery-ack-missing'));
    }

    // ==================================================================
    // §3.6 finalizer -- late_settlement_conflict
    // ==================================================================

    /**
     * The real end-to-end late-settlement `WebhookService` delivery: a historical origination
     * observes money movement after a NEWER reservation already owns the subject. The required
     * consumer deterministically rejects the mismatched reservation (`origination_mismatch`); the
     * finalizer completes as a no-op, the signed provider event finishes dispatching EXACTLY
     * ONCE, the newer origination is left completely untouched, and a provider redelivery of the
     * identical logical event is idempotent (the strict listener is never invoked a second time).
     */
    public function testLateSettlementConflictEndToEndRejectedReceiptDispatchedOnceAndRedeliveryIsIdempotent(): void
    {
        $oldUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0005',
            'subject_key' => 'subject-ack-lsc',
            'status' => 'dispatched',
            'required_projection_consumer' => self::CONSUMER,
            'consumer_metadata' => $this->consumerMetadata('tenantACK0005'),
        ]);
        $newUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0005',
            'subject_key' => 'subject-ack-lsc',
            'status' => 'provider_observed',
        ]);
        $this->seedLiveGuard('tenantACK0005', 'subject-ack-lsc', $newUuid);

        $listener = new RecordingAckListener($this->originations, self::CONSUMER, 'rejected', 'origination_mismatch');
        $service = $this->webhookService([$listener]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_lsc', 'delivery-ack-lsc-1', [
            'gateway_subscription_id' => 'sub_ack_lsc',
            'status' => 'active',
            'origination_uuid' => $oldUuid,
        ]);

        $result = $service->ingest('fake', $body);
        self::assertTrue($result->accepted, 'the conflicted event must still dispatch normally');
        self::assertCount(1, $listener->handled, 'exactly one rejected receipt was produced');

        $old = $this->originations->findByUuid($oldUuid);
        self::assertSame('late_settlement_conflict', $old['status']);
        self::assertSame(EventType::SUBSCRIPTION_CREATED . ':sub_ack_lsc', $old['projection_event_key']);
        self::assertSame('rejected', $old['projection_outcome']);
        self::assertSame('origination_mismatch', $old['projection_reason']);

        // The newer reservation is left completely untouched.
        $new = $this->originations->findByUuid($newUuid);
        self::assertSame('provider_observed', $new['status']);

        $guard = $this->guardRow('tenantACK0005', 'subject-ack-lsc');
        self::assertSame('blocked', $guard['state']);
        self::assertSame($oldUuid, $guard['origination_uuid']);

        // The logical dispatch is marked complete -- the signed event finished exactly once.
        self::assertSame('dispatched', $this->dispatchStatusFor('delivery-ack-lsc-1'));

        // Redelivery: the provider re-sends the SAME logical event under a NEW delivery key (a
        // realistic webhook redelivery). Already logically dispatched -> the strict listener must
        // never run again, and nothing about the ledger/guard moves.
        $redelivered = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_lsc', 'delivery-ack-lsc-2', [
            'gateway_subscription_id' => 'sub_ack_lsc',
            'status' => 'active',
            'origination_uuid' => $oldUuid,
        ]);
        $redeliveryResult = $service->ingest('fake', $redelivered);
        self::assertTrue($redeliveryResult->accepted);

        self::assertCount(1, $listener->handled, 'redelivery of an already-dispatched logical event is idempotent');
        self::assertSame('late_settlement_conflict', $this->originations->findByUuid($oldUuid)['status']);
        self::assertSame('provider_observed', $this->originations->findByUuid($newUuid)['status']);
        self::assertSame('blocked', $this->guardRow('tenantACK0005', 'subject-ack-lsc')['state']);
    }

    public function testFinalizerLateSettlementConflictAcceptedThrowsAndKeepsEventRetryable(): void
    {
        $oldUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0006',
            'subject_key' => 'subject-ack-lsc-acc',
            'status' => 'dispatched',
            'required_projection_consumer' => self::CONSUMER,
            'consumer_metadata' => $this->consumerMetadata('tenantACK0006'),
        ]);
        $newUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0006',
            'subject_key' => 'subject-ack-lsc-acc',
            'status' => 'provider_observed',
        ]);
        $this->seedLiveGuard('tenantACK0006', 'subject-ack-lsc-acc', $newUuid);

        // The projector wrongly (or a misbehaving consumer) accepts a conflicted reservation --
        // the ack CAS WRITER itself refuses this loudly (it never even reaches the finalizer),
        // exactly like acknowledge()'s own late_settlement_conflict matrix proves directly.
        $listener = new RecordingAckListener($this->originations, self::CONSUMER, 'accepted');
        $service = $this->webhookService([$listener]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_lsc_acc', 'delivery-ack-lsc-acc', [
            'gateway_subscription_id' => 'sub_ack_lsc_acc',
            'status' => 'active',
            'origination_uuid' => $oldUuid,
        ]);

        try {
            $service->ingest('fake', $body);
            self::fail('Expected an accepted acknowledgement against a conflict to throw and stay retryable');
        } catch (ProjectionAcknowledgementRefused $e) {
            self::assertStringContainsString(ProjectionAcknowledgementRefused::MARKER, $e->getMessage());
        }

        self::assertSame('late_settlement_conflict', $this->originations->findByUuid($oldUuid)['status']);
        self::assertSame('pending', $this->dispatchStatusFor('delivery-ack-lsc-acc'));
    }

    /**
     * Belt-and-suspenders coverage for the finalizer's OWN defensive check (as opposed to the ack
     * CAS writer's -- see the test above): even if a projection_outcome of `accepted` somehow
     * ended up recorded against a `late_settlement_conflict` row for the CURRENT logical event
     * key (bypassing the writer, e.g. a future/alternate `SubscriptionProjectionAcknowledger`
     * implementation that does not enforce the same rule), the finalizer itself must still refuse
     * to treat it as a completion rather than silently transitioning nothing and returning
     * success.
     */
    public function testFinalizerOwnDefenseRefusesAPreRecordedAcceptedOutcomeAgainstAConflict(): void
    {
        $oldUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0006B',
            'subject_key' => 'subject-ack-lsc-acc-b',
            'status' => 'late_settlement_conflict',
            'required_projection_consumer' => self::CONSUMER,
            'consumer_metadata' => $this->consumerMetadata('tenantACK0006B'),
        ]);
        // Bypasses the CAS writer entirely -- simulates a pre-existing (illegitimate) accepted
        // receipt for THIS event's exact logical key.
        $this->connection->table('subscription_checkout_originations')->where(['uuid' => $oldUuid])->update([
            'projection_event_key' => EventType::SUBSCRIPTION_CREATED . ':sub_ack_lsc_acc_b',
            'projection_outcome' => 'accepted',
        ]);

        $listener = new RecordingAckListener($this->originations, self::CONSUMER, null);
        $service = $this->webhookService([$listener]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_lsc_acc_b', 'delivery-ack-lsc-acc-b', [
            'gateway_subscription_id' => 'sub_ack_lsc_acc_b',
            'status' => 'active',
            'origination_uuid' => $oldUuid,
        ]);

        try {
            $service->ingest('fake', $body);
            self::fail('Expected the finalizer to refuse a pre-recorded accepted outcome against a conflict');
        } catch (RequiredProjectionAcknowledgementMissing $e) {
            self::assertStringContainsString(RequiredProjectionAcknowledgementMissing::MARKER, $e->getMessage());
        }

        self::assertSame('late_settlement_conflict', $this->originations->findByUuid($oldUuid)['status']);
        self::assertSame('pending', $this->dispatchStatusFor('delivery-ack-lsc-acc-b'));
    }

    public function testFinalizerLateSettlementConflictMissingAckThrowsAndKeepsEventRetryable(): void
    {
        $oldUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0007',
            'subject_key' => 'subject-ack-lsc-miss',
            'status' => 'dispatched',
            'required_projection_consumer' => self::CONSUMER,
            'consumer_metadata' => $this->consumerMetadata('tenantACK0007'),
        ]);
        $newUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0007',
            'subject_key' => 'subject-ack-lsc-miss',
            'status' => 'provider_observed',
        ]);
        $this->seedLiveGuard('tenantACK0007', 'subject-ack-lsc-miss', $newUuid);

        $listener = new RecordingAckListener($this->originations, self::CONSUMER, null);
        $service = $this->webhookService([$listener]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_lsc_miss', 'delivery-ack-lsc-miss', [
            'gateway_subscription_id' => 'sub_ack_lsc_miss',
            'status' => 'active',
            'origination_uuid' => $oldUuid,
        ]);

        try {
            $service->ingest('fake', $body);
            self::fail('Expected a missing acknowledgement against a conflict to throw and stay retryable');
        } catch (RequiredProjectionAcknowledgementMissing $e) {
            self::assertStringContainsString(RequiredProjectionAcknowledgementMissing::MARKER, $e->getMessage());
        }

        self::assertSame('late_settlement_conflict', $this->originations->findByUuid($oldUuid)['status']);
        self::assertSame('pending', $this->dispatchStatusFor('delivery-ack-lsc-miss'));

        // A later, correct rejected acknowledgement resolves the retry.
        $this->originations->acknowledge(
            $oldUuid,
            self::CONSUMER,
            EventType::SUBSCRIPTION_CREATED . ':sub_ack_lsc_miss',
            'rejected',
            'origination_mismatch'
        );
        $stored = $this->events->findByDeliveryKey('fake', 'delivery-ack-lsc-miss');
        $service->processStored((string) $stored['uuid']);

        self::assertSame('late_settlement_conflict', $this->originations->findByUuid($oldUuid)['status']);
        self::assertSame('dispatched', $this->dispatchStatusFor('delivery-ack-lsc-miss'));
    }

    /**
     * IMPORTANT (code review finding): a `late_settlement_conflict` origination that was NEVER
     * given a `required_projection_consumer` in the first place (e.g. a checkout that never
     * wired workspace subscriptions 2.2 at all) has no consumer that could ever acknowledge it,
     * and `late_settlement_conflict` has no further legal status transition either way
     * (`TRANSITIONS['late_settlement_conflict']` is permanently empty) -- so the finalizer must
     * complete SILENTLY, letting the signed provider event finish dispatching exactly once,
     * rather than throwing forever waiting on an acknowledgement nobody will ever produce. Status
     * and the blocked guard binding (both set by the APPLIER's own
     * `attemptLateSettlementConflict()`, unrelated to the finalizer) must stay completely
     * untouched.
     */
    public function testFinalizerLateSettlementConflictNoRequiredConsumerCompletesSilentlyUntouched(): void
    {
        $oldUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0011',
            'subject_key' => 'subject-ack-lsc-noconsumer',
            'status' => 'dispatched',
            // Deliberately no required_projection_consumer.
        ]);
        $newUuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0011',
            'subject_key' => 'subject-ack-lsc-noconsumer',
            'status' => 'provider_observed',
        ]);
        $this->seedLiveGuard('tenantACK0011', 'subject-ack-lsc-noconsumer', $newUuid);

        // No strict listener is needed: with no required consumer, nothing is ever expected to
        // acknowledge this origination.
        $service = $this->webhookService([]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_lsc_noc', 'delivery-ack-lsc-noc', [
            'gateway_subscription_id' => 'sub_ack_lsc_noc',
            'status' => 'active',
            'origination_uuid' => $oldUuid,
        ]);

        $result = $service->ingest('fake', $body);
        self::assertTrue($result->accepted, 'the conflicted event must complete silently, never throw');

        $old = $this->originations->findByUuid($oldUuid);
        self::assertSame('late_settlement_conflict', $old['status'], 'status must stay exactly as the applier left it');
        self::assertNull($old['projection_event_key'], 'no acknowledgement was ever expected or written');

        $guard = $this->guardRow('tenantACK0011', 'subject-ack-lsc-noconsumer');
        self::assertSame('blocked', $guard['state']);
        self::assertSame($oldUuid, $guard['origination_uuid']);

        self::assertSame('dispatched', $this->dispatchStatusFor('delivery-ack-lsc-noc'));
    }

    /**
     * A strict listener that crashes BEFORE ever acknowledging (design spec §3.6: "unmapped/
     * transient projection throws and writes no acknowledgement") leaves the origination at
     * `provider_observed`, releases the lease (event retryable), and a later retry -- once the
     * listener succeeds -- completes correctly with no second ownership/correlation row ever
     * created for the same origination uuid.
     */
    public function testStrictListenerCrashBeforeAckLeavesProviderObservedRetryableWithNoSecondOwnershipRow(): void
    {
        $uuid = $this->seedOrigination([
            'tenant_uuid' => 'tenantACK0008',
            'subject_key' => 'subject-ack-crash',
            'status' => 'pending',
            'required_projection_consumer' => self::CONSUMER,
            'consumer_metadata' => $this->consumerMetadata('tenantACK0008'),
        ]);
        $this->seedLiveGuard('tenantACK0008', 'subject-ack-crash', $uuid);

        $crashingListener = new RecordingAckListener(
            $this->originations,
            self::CONSUMER,
            'accepted',
            null,
            crashBeforeAck: true
        );
        $service = $this->webhookService([$crashingListener]);

        $body = $this->fakeBody(EventType::SUBSCRIPTION_CREATED, 'sub_ack_crash', 'delivery-ack-crash', [
            'gateway_subscription_id' => 'sub_ack_crash',
            'status' => 'active',
            'origination_uuid' => $uuid,
        ]);

        try {
            $service->ingest('fake', $body);
            self::fail('Expected the simulated crash to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated crash before acknowledgement', $e->getMessage());
        }

        self::assertSame('provider_observed', $this->originations->findByUuid($uuid)['status']);
        self::assertNull($this->originations->findByUuid($uuid)['projection_event_key']);
        self::assertSame('pending', $this->dispatchStatusFor('delivery-ack-crash'));
        self::assertSame(
            1,
            $this->connection->table('subscription_checkout_originations')->where(['uuid' => $uuid])->count(),
            'no duplicate ownership row may exist yet'
        );

        // Retry: this time the listener succeeds.
        $succeedingListener = new RecordingAckListener($this->originations, self::CONSUMER, 'accepted');
        $retryService = $this->webhookService([$succeedingListener]);
        $stored = $this->events->findByDeliveryKey('fake', 'delivery-ack-crash');
        $retryService->processStored((string) $stored['uuid']);

        self::assertSame('dispatched', $this->originations->findByUuid($uuid)['status']);
        self::assertSame(
            1,
            $this->connection->table('subscription_checkout_originations')->where(['uuid' => $uuid])->count(),
            'the retry must never create a second ownership row for the same origination'
        );
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
            'subject_uuid' => 'wsACK' . str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'plan_uuid' => 'planACK' . str_pad((string) self::$seq, 4, '0', STR_PAD_LEFT),
            'glueful_consumer' => self::CONSUMER,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function seedOrigination(array $overrides = []): string
    {
        self::$seq++;
        $uuid = 'ack' . str_pad((string) self::$seq, 9, '0', STR_PAD_LEFT);
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

    /**
     * Fires the instant `completeOriginationDispatch()`'s OWN `provider_observed -> dispatched`
     * status UPDATE for `$uuid` commits, mutating the matching guard to `blocked` in response -- a
     * faithful, deterministic reproduction of a concurrent operator `block()` landing between the
     * transition and the guard-release write, without needing real OS-level concurrency (mirrors
     * `OriginationCorrelationTest`'s identical TOCTOU-trigger technique). Both writes now share
     * ONE transaction (the atomicity fix under test here), so this trigger's own effect is itself
     * inside that same transaction -- a rollback undoes the guard block AND the trigger's mutation
     * together, exactly like the guard was never touched at all.
     *
     * Returns the trigger's name so the caller can `DROP TRIGGER` it once the test wants to
     * simulate the concurrent interference having stopped, before retrying.
     */
    private function armConcurrentGuardBlockTrigger(string $uuid, string $tenantUuid, string $subjectKey): string
    {
        $triggerName = 'race_dispatch_' . substr(md5($uuid), 0, 8);
        $this->connection->getPDO()->exec(sprintf(
            "CREATE TRIGGER %s AFTER UPDATE OF status ON subscription_checkout_originations "
                . "WHEN NEW.uuid = '%s' AND NEW.status = 'dispatched' "
                . "BEGIN UPDATE subscription_checkout_subject_guards SET state = 'blocked', "
                . "blocked_reason = 'concurrent operator hold' "
                . "WHERE tenant_uuid = '%s' AND subject_key = '%s' AND state = 'live'; END;",
            $triggerName,
            $uuid,
            $tenantUuid,
            $subjectKey
        ));

        return $triggerName;
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

    private function dispatchStatusFor(string $deliveryKey): ?string
    {
        $row = $this->events->findByDeliveryKey('fake', $deliveryKey);

        return is_array($row) ? (string) ($row['dispatch_status'] ?? null) : null;
    }

    /**
     * Wires the REAL `WebhookService` finalizer (`originations`/`guards` both present, mirroring
     * `PayviaServiceProvider::makeWebhookService()`), plus `logicalDispatchLeases` so a
     * finalizer/dispatch failure releases its lease immediately -- an immediate `processStored()`
     * retry can reclaim the row without waiting out a stale-claim timeout, exactly like
     * production's lease-backed composition.
     *
     * @param list<StrictPaymentEventListener> $listeners
     */
    private function webhookService(array $listeners): WebhookService
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

        $dispatcher = static function (PaymentProviderEvent $event) use ($listeners): void {
            foreach ($listeners as $listener) {
                if ($listener->supports($event->event)) {
                    $listener->handle($event->event);
                }
            }
        };

        return new WebhookService(
            context: $this->context,
            gateways: $manager,
            events: $this->events,
            dispatcher: $dispatcher,
            applier: $applier,
            logicalDispatchLeases: $this->events,
            payloadUpdater: $this->events,
            originations: $this->originations,
            guards: $this->guards,
        );
    }
}
