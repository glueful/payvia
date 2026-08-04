<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Repositories;

use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Database\Migrations\CreateCheckoutOriginations;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Task 4 (workspace self-serve checkout, design spec §3.3): the origination ledger's status
 * state machine, the initialization provider-I/O lease, and the subject guard's exclusive
 * open/live/blocked authority.
 *
 * Most of this suite runs against the in-memory SQLite harness ({@see PayviaTestCase}). A
 * handful of tests are gated on a real, reachable PostgreSQL instance (via `DB_PGSQL_*` env
 * vars, defaulting to `127.0.0.1:5432/payvia_test` as user `postgres`) and skip cleanly when
 * none is available: the nullable-unique `(gateway, provider_subscription_id)` pin needs proof
 * on both supported engines, and the guard's concurrent-claim race needs a genuinely independent
 * second actor (a real subprocess, launched via `proc_open()` against
 * tests/Fixtures/checkout-origination/subject_guard_race_child.php -- PHP has no threads) to
 * exercise Postgres' real transaction-poisoning-after-a-failed-statement behavior, which SQLite
 * does not reproduce.
 */
final class CheckoutOriginationLedgerTest extends PayviaTestCase
{
    private const ALL_STATUSES = [
        'preparing',
        'initializing',
        'pending',
        'provider_observed',
        'dispatched',
        'projection_rejected',
        'late_settlement_conflict',
        'failed',
        'expired',
        'abandoned',
    ];

    private CheckoutOriginationRepository $originations;
    private CheckoutSubjectGuardRepository $guards;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateCheckoutOriginations());
        $this->originations = new CheckoutOriginationRepository($this->connection);
        $this->guards = new CheckoutSubjectGuardRepository($this->connection);
    }

    // ==================================================================
    // claimPreparing()
    // ==================================================================

    public function testClaimPreparingInsertsAPreparingLiveRowWithAGeneratedUuid(): void
    {
        $row = $this->claim();

        self::assertNotSame('', $row['uuid']);
        self::assertSame('preparing', $row['status']);
        self::assertTrue((bool) $row['live']);
        self::assertNull($row['checkout_reference']);
        self::assertNull($row['provider_subscription_id']);
    }

    public function testClaimPreparingWithTheSameIdempotencyKeyReturnsTheExistingRowInstead(): void
    {
        $key = $this->uniqueKey('idem');
        $first = $this->claim(['idempotency_key' => $key]);

        $second = $this->claim(['idempotency_key' => $key, 'subject_key' => 'a-different-subject']);

        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame(1, $this->connection->table('subscription_checkout_originations')->count());
    }

    public function testClaimPreparingWithoutAnIdempotencyKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->originations->claimPreparing($this->context, $this->row(['idempotency_key' => '']));
    }

    // ==================================================================
    // finders
    // ==================================================================

    public function testFindersLocateByEachOfTheirKeysAndReturnNullOtherwise(): void
    {
        $claimed = $this->claim();
        $this->originations->transition($this->context, $claimed['uuid'], 'preparing', 'initializing');
        self::assertTrue($this->originations->completeInitialization(
            $this->context,
            $claimed['uuid'],
            $this->claimLease($claimed['uuid']),
            'pending',
            ['checkout_reference' => 'cs_test_findme'],
        ));
        $this->originations->transition(
            $this->context,
            $claimed['uuid'],
            'pending',
            'provider_observed',
            ['provider_subscription_id' => 'sub_findme'],
        );

        self::assertSame($claimed['uuid'], $this->originations->findByUuid($claimed['uuid'])['uuid']);
        self::assertNull($this->originations->findByUuid('nope'));

        $byIdempotency = $this->originations->findByIdempotencyKey($this->context, $claimed['idempotency_key']);
        self::assertSame($claimed['uuid'], $byIdempotency['uuid']);
        self::assertNull($this->originations->findByIdempotencyKey($this->context, 'nope'));

        $byReference = $this->originations->findByCheckoutReference('stripe', 'cs_test_findme');
        self::assertSame($claimed['uuid'], $byReference['uuid']);
        self::assertNull($this->originations->findByCheckoutReference('stripe', 'nope'));

        $bySubscription = $this->originations->findByProviderSubscriptionId('stripe', 'sub_findme');
        self::assertSame($claimed['uuid'], $bySubscription['uuid']);
        self::assertNull($this->originations->findByProviderSubscriptionId('stripe', 'nope'));
    }

    // ==================================================================
    // transition(): the full legal/illegal/idempotent matrix
    // ==================================================================

    /** @dataProvider legalTransitions */
    public function testLegalTransitionSucceedsAndAdvancesStatus(string $from, string $to): void
    {
        $uuid = $this->seed($from);

        self::assertTrue($this->originations->transition($this->context, $uuid, $from, $to));
        self::assertSame($to, $this->originations->findByUuid($uuid)['status']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function legalTransitions(): iterable
    {
        foreach (CheckoutOriginationRepository::TRANSITIONS as $from => $tos) {
            foreach ($tos as $to) {
                yield "{$from}->{$to}" => [$from, $to];
            }
        }
    }

    /** @dataProvider illegalTransitions */
    public function testIllegalTransitionIsRefusedWithoutWritingAnything(string $from, string $to): void
    {
        $uuid = $this->seed($from);

        self::assertFalse($this->originations->transition($this->context, $uuid, $from, $to));
        self::assertSame($from, $this->originations->findByUuid($uuid)['status'], 'status must be untouched');
    }

    /**
     * Every (from, to) pair NOT present in {@see CheckoutOriginationRepository::TRANSITIONS} and
     * not a same-value pair (covered separately by the idempotent-repeat test below) -- the
     * complement is derived from the map itself so this can never silently drift out of sync
     * with it.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function illegalTransitions(): iterable
    {
        foreach (self::ALL_STATUSES as $from) {
            foreach (self::ALL_STATUSES as $to) {
                if ($from === $to) {
                    continue;
                }
                if (in_array($to, CheckoutOriginationRepository::TRANSITIONS[$from] ?? [], true)) {
                    continue;
                }

                yield "{$from}->{$to}" => [$from, $to];
            }
        }
    }

    public function testRepeatingAnAlreadyCompletedTransitionIsAnIdempotentNoOp(): void
    {
        $uuid = $this->seed('pending');

        self::assertTrue($this->originations->transition($this->context, $uuid, 'pending', 'provider_observed'));
        // Row is now `provider_observed`, not `pending` -- a redelivered caller repeating the
        // EXACT same (from, to) call must still succeed, without needing the row to still match
        // `$from`.
        self::assertTrue($this->originations->transition($this->context, $uuid, 'pending', 'provider_observed'));
        self::assertSame('provider_observed', $this->originations->findByUuid($uuid)['status']);
    }

    public function testTransitionAgainstAWrongCurrentStatusThatIsNeitherFromNorToFails(): void
    {
        $uuid = $this->seed('initializing');

        // Legal per the map (pending -> provider_observed), but the row is actually
        // `initializing` -- neither `$from` nor `$to` -- so this must fail without writing.
        self::assertFalse($this->originations->transition($this->context, $uuid, 'pending', 'provider_observed'));
        self::assertSame('initializing', $this->originations->findByUuid($uuid)['status']);
    }

    public function testTransitionOnAnUnknownUuidReturnsFalse(): void
    {
        self::assertFalse(
            $this->originations->transition($this->context, 'no-such-uuid', 'pending', 'provider_observed')
        );
    }

    public function testSanctionedRegressionFromEveryTerminalStatusToProviderObservedSucceeds(): void
    {
        foreach (['dispatched', 'failed', 'expired', 'abandoned', 'projection_rejected'] as $terminal) {
            $uuid = $this->seed($terminal);
            self::assertTrue(
                $this->originations->transition($this->context, $uuid, $terminal, 'provider_observed'),
                "{$terminal} -> provider_observed must be the sanctioned late-money regression"
            );
            $row = $this->originations->findByUuid($uuid);
            self::assertSame('provider_observed', $row['status']);
            self::assertTrue((bool) $row['live'], 'provider_observed is live');
        }
    }

    public function testLateSettlementConflictIsReachableFromEveryTerminalStatus(): void
    {
        foreach (['dispatched', 'failed', 'expired', 'abandoned', 'projection_rejected'] as $terminal) {
            $uuid = $this->seed($terminal);
            self::assertTrue(
                $this->originations->transition($this->context, $uuid, $terminal, 'late_settlement_conflict')
            );

            $row = $this->originations->findByUuid($uuid);
            self::assertSame('late_settlement_conflict', $row['status']);
            self::assertFalse((bool) $row['live']);
        }
    }

    public function testLateSettlementConflictItselfIsFullyTerminal(): void
    {
        self::assertSame([], CheckoutOriginationRepository::TRANSITIONS['late_settlement_conflict']);
    }

    /**
     * Defensive vocabulary guard: `$from === $to` is only vacuously legal for a KNOWN status.
     * Forces a row to an unrecognized status directly (bypassing the repository) to prove an
     * unknown value is never treated as a legal no-op merely because it matches itself.
     */
    public function testUnknownStatusIsNeverVacuouslyLegalEvenWhenFromEqualsTo(): void
    {
        $uuid = $this->seed('preparing');
        $this->connection->table('subscription_checkout_originations')
            ->where(['uuid' => $uuid])
            ->update(['status' => 'banana']);

        self::assertFalse($this->originations->transition($this->context, $uuid, 'banana', 'banana'));
        self::assertSame('banana', $this->originations->findByUuid($uuid)['status']);
    }

    // ==================================================================
    // customer_email: cleared ONLY by a definitive (terminal) completion
    // ==================================================================

    public function testCustomerEmailSurvivesANonTerminalTransition(): void
    {
        $uuid = $this->seed('pending', ['customer_email' => 'shopper@example.test']);

        self::assertTrue($this->originations->transition($this->context, $uuid, 'pending', 'provider_observed'));

        self::assertSame('shopper@example.test', $this->originations->findByUuid($uuid)['customer_email']);
    }

    public function testCustomerEmailIsClearedOnceATerminalStatusIsReached(): void
    {
        $uuid = $this->seed('provider_observed', ['customer_email' => 'shopper@example.test']);

        self::assertTrue($this->originations->transition($this->context, $uuid, 'provider_observed', 'dispatched'));

        self::assertNull($this->originations->findByUuid($uuid)['customer_email']);
    }

    public function testCustomerEmailIsClearedWhenCompleteInitializationReachesTheTerminalFailedStatus(): void
    {
        $uuid = $this->seed('initializing', ['customer_email' => 'shopper@example.test']);
        $token = $this->claimLease($uuid);

        self::assertTrue($this->originations->completeInitialization($this->context, $uuid, $token, 'failed'));

        self::assertNull($this->originations->findByUuid($uuid)['customer_email']);
    }

    public function testCustomerEmailSurvivesCompleteInitializationReachingTheLiveStatusPending(): void
    {
        $uuid = $this->seed('initializing', ['customer_email' => 'shopper@example.test']);
        $token = $this->claimLease($uuid);

        self::assertTrue($this->originations->completeInitialization($this->context, $uuid, $token, 'pending'));

        self::assertSame('shopper@example.test', $this->originations->findByUuid($uuid)['customer_email']);
    }

    // ==================================================================
    // initialization lease: claim / release / complete
    // ==================================================================

    public function testClaimInitializationOnAnEligibleRowReturnsTheRowAndStampsTheToken(): void
    {
        $uuid = $this->seed('initializing');

        $row = $this->claimInit($uuid, 'tok-a');

        self::assertNotNull($row);
        self::assertSame('tok-a', $row['initialization_claim_token']);
    }

    public function testClaimInitializationRefusesARowThatIsNotInitializing(): void
    {
        $uuid = $this->seed('pending');

        self::assertNull($this->claimInit($uuid, 'tok-a'));
    }

    public function testConcurrentLoserCannotClaimWhileTheLeaseIsStillLive(): void
    {
        $uuid = $this->seed('initializing');
        self::assertNotNull($this->claimInit($uuid, 'tok-a'));

        self::assertNull($this->claimInit($uuid, 'tok-b'));

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('tok-a', $row['initialization_claim_token']);
    }

    public function testWrongTokenCannotReleaseOrCompleteWhileTheLeaseIsHeld(): void
    {
        $uuid = $this->seed('initializing');
        self::assertNotNull($this->claimInit($uuid, 'tok-a'));

        self::assertFalse($this->originations->releaseInitialization($this->context, $uuid, 'tok-wrong'));
        self::assertFalse($this->originations->completeInitialization($this->context, $uuid, 'tok-wrong', 'pending'));

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('initializing', $row['status']);
        self::assertSame('tok-a', $row['initialization_claim_token']);
    }

    public function testReleaseInitializationClearsTheLeaseWithoutChangingStatus(): void
    {
        $uuid = $this->seed('initializing');
        self::assertNotNull($this->claimInit($uuid, 'tok-a'));

        self::assertTrue($this->originations->releaseInitialization($this->context, $uuid, 'tok-a'));

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('initializing', $row['status']);
        self::assertNull($row['initialization_claim_token']);
        self::assertNull($row['initialization_claimed_at']);

        // A released token cannot be replayed to release again.
        self::assertFalse($this->originations->releaseInitialization($this->context, $uuid, 'tok-a'));
    }

    public function testCompleteInitializationAdvancesStatusAndClearsTheLease(): void
    {
        $uuid = $this->seed('initializing');
        self::assertNotNull($this->claimInit($uuid, 'tok-a'));

        self::assertTrue($this->originations->completeInitialization(
            $this->context,
            $uuid,
            'tok-a',
            'pending',
            ['checkout_reference' => 'cs_completed'],
        ));

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['initialization_claim_token']);
        self::assertSame('cs_completed', $row['checkout_reference']);

        // Already advanced past `initializing`: the same token can never complete again.
        self::assertFalse($this->originations->completeInitialization($this->context, $uuid, 'tok-a', 'pending'));
    }

    public function testCompleteInitializationRefusesAnIllegalTargetStatusWithoutWriting(): void
    {
        $uuid = $this->seed('initializing');
        self::assertNotNull($this->claimInit($uuid, 'tok-a'));

        self::assertFalse($this->originations->completeInitialization($this->context, $uuid, 'tok-a', 'dispatched'));

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('initializing', $row['status'], 'an illegal target must never be written');
        self::assertSame('tok-a', $row['initialization_claim_token'], 'the lease must be untouched');
    }

    /**
     * The dispatch-lease authority for this exact scenario: key/token scoping alone still lets
     * a stale holder's completion/release slip through UNLESS the row's lease has genuinely
     * moved to a new token. A claims; its claim is forced stale (direct UPDATE of
     * initialization_claimed_at, exactly like ProviderEventRepositoryLeaseTest's
     * `forceStaleClaim()`); B then claims and MUST receive a different token. A's now-stale
     * token must be rejected by both release() and completeInitialization() while B holds it,
     * and B must still be able to complete using its own token afterward.
     */
    public function testOldTokenCannotReleaseOrCompleteAfterAnotherAcquiresTheLeaseViaStaleTakeover(): void
    {
        $uuid = $this->seed('initializing');

        $rowA = $this->originations->claimInitialization($this->context, $uuid, 'tok-a', $this->staleBefore(300));
        self::assertNotNull($rowA);

        $this->forceStaleClaim($uuid);

        $rowB = $this->originations->claimInitialization($this->context, $uuid, 'tok-b', $this->staleBefore(300));
        self::assertNotNull($rowB);
        self::assertSame('tok-b', $rowB['initialization_claim_token']);

        self::assertFalse($this->originations->releaseInitialization($this->context, $uuid, 'tok-a'));
        self::assertFalse($this->originations->completeInitialization($this->context, $uuid, 'tok-a', 'pending'));

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('initializing', $row['status']);
        self::assertSame('tok-b', $row['initialization_claim_token']);

        self::assertTrue($this->originations->completeInitialization($this->context, $uuid, 'tok-b', 'pending'));
        self::assertSame('pending', $this->originations->findByUuid($uuid)['status']);
    }

    // ==================================================================
    // Subject guard: open -> live exactly once
    // ==================================================================

    public function testLockAndClaimClaimsAFreshSubjectFromOpenToLive(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        $this->seedGuard($tenant, $subject, 'open');

        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origAAAAAAAA'));

        $row = $this->findGuard($tenant, $subject);
        self::assertSame('live', $row['state']);
        self::assertSame('origAAAAAAAA', $row['origination_uuid']);
    }

    public function testLockAndClaimInsertsDirectlyAsLiveWhenNoGuardRowExistsYet(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');

        self::assertNull($this->findGuard($tenant, $subject));
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origAAAAAAAA'));

        $row = $this->findGuard($tenant, $subject);
        self::assertSame('live', $row['state']);
        self::assertSame('origAAAAAAAA', $row['origination_uuid']);
    }

    public function testLockAndClaimIsIdempotentForTheSameOrigination(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origAAAAAAAA'));

        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origAAAAAAAA'));

        $row = $this->findGuard($tenant, $subject);
        self::assertSame(1, $row['revision'], 'a repeated idempotent claim must not bump the revision again');
    }

    /**
     * SQLite sequential equivalent of the real-concurrency PostgreSQL race below: no true
     * blocking is possible over a single in-memory connection, so this proves the same
     * end-state invariant (exactly one winner, exactly one row) by calling lockAndClaim()
     * sequentially for two different originations racing the SAME brand-new subject.
     */
    public function testGuardOpenToLiveExactlyOnceUnderASequentialClaimRace(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');

        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origWINNER01'));
        self::assertFalse($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origLOSER001'));

        $rows = $this->connection->table('subscription_checkout_subject_guards')
            ->where(['tenant_uuid' => $tenant, 'subject_key' => $subject])
            ->get();
        self::assertCount(1, $rows);
        self::assertSame('live', $rows[0]['state']);
        self::assertSame('origWINNER01', $rows[0]['origination_uuid']);
    }

    public function testLockAndClaimRefusesADifferentOriginationWhileAlreadyLive(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origWINNER01'));

        self::assertFalse($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origLOSER001'));

        self::assertSame('origWINNER01', $this->findGuard($tenant, $subject)['origination_uuid']);
    }

    public function testLockAndClaimRefusesWhileBlocked(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->block($this->context, $tenant, $subject, null, 'late settlement conflict'));

        self::assertFalse($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origAAAAAAAA'));

        self::assertSame('blocked', $this->findGuard($tenant, $subject)['state']);
    }

    // ==================================================================
    // Subject guard: release requires the matching origination
    // ==================================================================

    public function testReleaseRequiresTheMatchingOrigination(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origWINNER01'));

        self::assertFalse($this->guards->release($this->context, $tenant, $subject, 'origWRONGONE'));
        self::assertSame('live', $this->findGuard($tenant, $subject)['state']);

        self::assertTrue($this->guards->release($this->context, $tenant, $subject, 'origWINNER01'));
        $row = $this->findGuard($tenant, $subject);
        self::assertSame('open', $row['state']);
        self::assertNull($row['origination_uuid']);
    }

    public function testReleaseIsIdempotentWhenTheGuardIsAlreadyOpen(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origWINNER01'));
        self::assertTrue($this->guards->release($this->context, $tenant, $subject, 'origWINNER01'));

        self::assertTrue($this->guards->release($this->context, $tenant, $subject, 'origWINNER01'));
    }

    public function testReleaseRefusesABlockedGuard(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origWINNER01'));
        self::assertTrue($this->guards->block($this->context, $tenant, $subject, 'origWINNER01', 'operator hold'));

        self::assertFalse($this->guards->release($this->context, $tenant, $subject, 'origWINNER01'));
        self::assertSame('blocked', $this->findGuard($tenant, $subject)['state']);
    }

    public function testOnceReleasedTheSubjectCanBeClaimedAgainByANewOrigination(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origFIRST001'));
        self::assertTrue($this->guards->release($this->context, $tenant, $subject, 'origFIRST001'));

        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origSECOND01'));

        self::assertSame('origSECOND01', $this->findGuard($tenant, $subject)['origination_uuid']);
    }

    // ==================================================================
    // Subject guard: block()
    // ==================================================================

    public function testBlockForcesStateFromOpenWithANoOriginationOperatorHold(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        $this->seedGuard($tenant, $subject, 'open');

        self::assertTrue($this->guards->block($this->context, $tenant, $subject, null, 'manual hold'));

        $row = $this->findGuard($tenant, $subject);
        self::assertSame('blocked', $row['state']);
        self::assertNull($row['origination_uuid']);
        self::assertSame('manual hold', $row['blocked_reason']);
    }

    /**
     * The Critical fix: blocking must PERSIST the origination binding (not clear it), because
     * Task 9's operator reconciliation (design spec §3.8) needs to CAS `reopen()` against the
     * exact origination a `late_settlement_conflict` block was raised for.
     */
    public function testBlockForcesStateFromLivePersistingTheOriginationBinding(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origAAAAAAAA'));

        self::assertTrue(
            $this->guards->block($this->context, $tenant, $subject, 'origAAAAAAAA', 'late settlement conflict')
        );

        $row = $this->findGuard($tenant, $subject);
        self::assertSame('blocked', $row['state']);
        self::assertSame('origAAAAAAAA', $row['origination_uuid']);
        self::assertSame('late settlement conflict', $row['blocked_reason']);
    }

    public function testBlockPersistsTheOriginationBindingThroughTheInsertPathWhenNoRowExistsYet(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');

        self::assertTrue($this->guards->block($this->context, $tenant, $subject, 'origPRESET01', 'pre-emptive hold'));

        $row = $this->findGuard($tenant, $subject);
        self::assertSame('blocked', $row['state']);
        self::assertSame('origPRESET01', $row['origination_uuid']);
    }

    public function testBlockInsertsDirectlyAsANoOriginationHoldWhenNoRowExistsYet(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');

        self::assertTrue($this->guards->block($this->context, $tenant, $subject, null, 'pre-emptive hold'));

        $row = $this->findGuard($tenant, $subject);
        self::assertSame('blocked', $row['state']);
        self::assertNull($row['origination_uuid']);
    }

    // ==================================================================
    // Subject guard: reopen() -- Task 9's operator-reconciliation CAS path
    // ==================================================================

    public function testReopenOfABlockedGuardWithTheMatchingOriginationSucceeds(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origAAAAAAAA'));
        self::assertTrue($this->guards->block($this->context, $tenant, $subject, 'origAAAAAAAA', 'conflict'));

        self::assertTrue($this->guards->reopen($this->context, $tenant, $subject, 'origAAAAAAAA'));

        $row = $this->findGuard($tenant, $subject);
        self::assertSame('open', $row['state']);
        self::assertNull($row['origination_uuid']);
        self::assertNull($row['blocked_reason']);
    }

    public function testReopenOfABlockedGuardWithAWrongOriginationFailsWithoutWriting(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origAAAAAAAA'));
        self::assertTrue($this->guards->block($this->context, $tenant, $subject, 'origAAAAAAAA', 'conflict'));

        self::assertFalse($this->guards->reopen($this->context, $tenant, $subject, 'origWRONGONE'));

        $row = $this->findGuard($tenant, $subject);
        self::assertSame('blocked', $row['state']);
        self::assertSame('origAAAAAAAA', $row['origination_uuid']);
    }

    public function testReopenOfAStillLiveGuardWithTheMatchingOriginationSucceeds(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->lockAndClaim($this->context, $tenant, $subject, 'origAAAAAAAA'));

        self::assertTrue($this->guards->reopen($this->context, $tenant, $subject, 'origAAAAAAAA'));

        $row = $this->findGuard($tenant, $subject);
        self::assertSame('open', $row['state']);
        self::assertNull($row['origination_uuid']);
    }

    public function testReopenOfAnAlreadyOpenGuardFailsWithoutWriting(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        $this->seedGuard($tenant, $subject, 'open');

        self::assertFalse($this->guards->reopen($this->context, $tenant, $subject, 'origAAAAAAAA'));

        self::assertSame('open', $this->findGuard($tenant, $subject)['state']);
    }

    /**
     * A no-origination operator hold (`origination_uuid IS NULL`) can never be reopened by ANY
     * origination -- SQL equality never matches NULL, by design: that hold requires a separate,
     * explicit operator action rather than a caller merely guessing an origination uuid.
     */
    public function testReopenCannotOpenANullBindingBlockedGuardByAnyOrigination(): void
    {
        $tenant = 'tenantAAAA01';
        $subject = $this->uniqueKey('subject');
        self::assertTrue($this->guards->block($this->context, $tenant, $subject, null, 'no-origination hold'));

        self::assertFalse($this->guards->reopen($this->context, $tenant, $subject, 'origAAAAAAAA'));
        self::assertFalse($this->guards->reopen($this->context, $tenant, $subject, 'anyOriginationAtAll'));

        self::assertSame('blocked', $this->findGuard($tenant, $subject)['state']);
    }

    // ==================================================================
    // Unique-null pin: (gateway, provider_subscription_id) on SQLite
    // ==================================================================

    public function testProviderSubscriptionIdNullsDoNotCollideOnSqlite(): void
    {
        $first = $this->claim(['idempotency_key' => $this->uniqueKey('idem')]);
        $second = $this->claim(['idempotency_key' => $this->uniqueKey('idem')]);

        self::assertNull($first['provider_subscription_id']);
        self::assertNull($second['provider_subscription_id']);
        self::assertNotSame($first['uuid'], $second['uuid']);
        self::assertSame(2, $this->connection->table('subscription_checkout_originations')->count());
    }

    // ==================================================================
    // PostgreSQL-gated: unique-null pin + real concurrent guard race
    // ==================================================================

    public function testProviderSubscriptionIdNullsDoNotCollideOnPostgres(): void
    {
        $pg = $this->pgsqlConnection();
        $repo = new CheckoutOriginationRepository($pg);

        $first = $repo->claimPreparing($this->context, $this->row(['idempotency_key' => $this->uniqueKey('pgidem')]));
        $second = $repo->claimPreparing($this->context, $this->row(['idempotency_key' => $this->uniqueKey('pgidem')]));

        self::assertNull($first['provider_subscription_id']);
        self::assertNull($second['provider_subscription_id']);
        self::assertNotSame($first['uuid'], $second['uuid']);
    }

    /**
     * Real, independent second actor (a subprocess -- PHP has no threads) racing a genuinely
     * concurrent first claim of a brand-new subject against a connection that has already
     * inserted (but not yet committed) the winning row. The child's own INSERT blocks on
     * PostgreSQL's real row-level unique-index conflict wait until the parent connection
     * resolves; once it commits, the child's insert fails with a genuine unique violation and
     * must recover (rollback + re-read) rather than leaving its own connection's transaction
     * poisoned -- proving {@see CheckoutSubjectGuardRepository::lockAndClaim()}'s
     * begin/rollback-then-re-read recovery works against real PostgreSQL semantics, not just
     * SQLite's more forgiving error behavior.
     */
    public function testGuardOpenToLiveExactlyOnceUnderARealConcurrentClaimRace(): void
    {
        $pg = $this->pgsqlConnection();

        $tenant = 'racetenant01';
        $subject = $this->uniqueKey('pgrace');
        $winner = 'winnerorig01';
        $loser = 'loserorigin1';

        $pg->getTransactionManager()->begin();
        $pg->table('subscription_checkout_subject_guards')->insert([
            'uuid' => substr($this->uniqueKey('grd'), 0, 12),
            'tenant_uuid' => $tenant,
            'subject_key' => $subject,
            'state' => 'live',
            'origination_uuid' => $winner,
            'revision' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $handle = $this->launchRaceChild(['tenant' => $tenant, 'subjectKey' => $subject, 'originationUuid' => $loser]);
        usleep(300_000);
        $pg->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse(
            $result['ok'] ?? true,
            'the loser must fail once the winner has committed: ' . json_encode($result)
        );

        $rows = $pg->table('subscription_checkout_subject_guards')
            ->where(['tenant_uuid' => $tenant, 'subject_key' => $subject])
            ->get();
        self::assertCount(1, $rows, 'exactly one guard row may exist after the race');
        self::assertSame('live', $rows[0]['state']);
        self::assertSame($winner, $rows[0]['origination_uuid']);
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function row(array $overrides = []): array
    {
        self::$seq++;

        return array_merge([
            'subject_key' => 'subject-' . self::$seq,
            'gateway' => 'stripe',
            'provider_plan_identifier' => 'plan_' . self::$seq,
            'idempotency_key' => $this->uniqueKey('idem'),
            'request_fingerprint' => str_repeat('a', 64),
            'return_url' => 'https://shop.example.test/return',
            'cancel_url' => 'https://shop.example.test/cancel',
        ], $overrides);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function claim(array $overrides = []): array
    {
        return $this->originations->claimPreparing($this->context, $this->row($overrides));
    }

    /** Inserts a row directly at an arbitrary status, bypassing the repository under test. */
    private function seed(string $status, array $overrides = []): string
    {
        self::$seq++;
        $uuid = 'orig' . str_pad((string) self::$seq, 8, '0', STR_PAD_LEFT);

        $this->connection->table('subscription_checkout_originations')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'subject_key' => 'subject-' . self::$seq,
            'gateway' => 'stripe',
            'provider_plan_identifier' => 'plan_' . self::$seq,
            'idempotency_key' => $this->uniqueKey('idem'),
            'request_fingerprint' => str_repeat('a', 64),
            'return_url' => 'https://shop.example.test/return',
            'cancel_url' => 'https://shop.example.test/cancel',
            'status' => $status,
            'live' => !in_array($status, [
                'dispatched', 'failed', 'expired', 'abandoned', 'projection_rejected', 'late_settlement_conflict',
            ], true),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], $overrides));

        return $uuid;
    }

    private function claimLease(string $uuid): string
    {
        $token = 'tok' . self::$seq++;
        $row = $this->claimInit($uuid, $token);
        self::assertNotNull($row, 'test setup expected the initialization lease to be claimable');

        return $token;
    }

    /** @return array<string,mixed>|null */
    private function claimInit(string $uuid, string $token): ?array
    {
        return $this->originations->claimInitialization($this->context, $uuid, $token, $this->staleBefore());
    }

    private function staleBefore(int $seconds = 300): \DateTimeImmutable
    {
        return new \DateTimeImmutable("-{$seconds} seconds");
    }

    private function forceStaleClaim(string $uuid): void
    {
        $this->connection->table('subscription_checkout_originations')
            ->where(['uuid' => $uuid])
            ->update([
                'initialization_claimed_at' => (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s'),
            ]);
    }

    private function uniqueKey(string $prefix): string
    {
        self::$seq++;

        return $prefix . '-' . self::$seq . '-' . bin2hex(random_bytes(4));
    }

    private function seedGuard(string $tenantUuid, string $subjectKey, string $state): void
    {
        $this->connection->table('subscription_checkout_subject_guards')->insert([
            'uuid' => substr($this->uniqueKey('grd'), 0, 12),
            'tenant_uuid' => $tenantUuid,
            'subject_key' => $subjectKey,
            'state' => $state,
            'revision' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function findGuard(string $tenantUuid, string $subjectKey): ?array
    {
        return $this->connection->table('subscription_checkout_subject_guards')
            ->where(['tenant_uuid' => $tenantUuid, 'subject_key' => $subjectKey])
            ->limit(1)
            ->first();
    }

    /**
     * A real, reachable PostgreSQL connection for the tests gated on it, or a skip. Configurable
     * via `DB_PGSQL_*` env vars (mirrors thallo's own race-test convention); defaults to a local
     * `payvia_test` database as user `postgres`.
     */
    private function pgsqlConnection(): Connection
    {
        try {
            $connection = new Connection([
                'engine' => 'pgsql',
                'pgsql' => [
                    'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                    'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                    'db' => getenv('DB_PGSQL_DATABASE') ?: 'payvia_test',
                    'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                    'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                    'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
                ],
                'pooling' => ['enabled' => false],
            ]);
            // Migration first (idempotent, hasTable()-guarded): a fresh database has neither
            // table yet, so the cleanup deletes below would fail on a first run otherwise.
            (new CreateCheckoutOriginations())->up($connection->getSchemaBuilder());
            $connection->getPDO()->exec('DELETE FROM subscription_checkout_subject_guards');
            $connection->getPDO()->exec('DELETE FROM subscription_checkout_originations');

            return $connection;
        } catch (\Throwable $e) {
            self::markTestSkipped(
                'PostgreSQL not reachable (set DB_PGSQL_* env vars to run this test): ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/Fixtures/checkout-origination/subject_guard_race_child.php',
                json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
    }
}
