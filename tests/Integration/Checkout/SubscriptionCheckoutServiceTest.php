<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Checkout;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Checkout\CheckoutUnavailableException;
use Glueful\Extensions\Payvia\Checkout\DefinitiveSubscriptionCheckoutRejection;
use Glueful\Extensions\Payvia\Checkout\IdempotencyConflictException;
use Glueful\Extensions\Payvia\Checkout\OriginationLiveException;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutClaim;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutRequest;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutService;
use Glueful\Extensions\Payvia\Database\Migrations\CreateCheckoutOriginations;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Payvia\Tests\Support\FakeSubscriptionInitiationGateway;
use Glueful\Extensions\Payvia\Tests\Support\FixedTenantResolver;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Psr\Container\ContainerInterface;

/**
 * Task 5 (workspace self-serve checkout, design spec §3.2): SubscriptionCheckoutService's
 * atomic prepare()/initializeClaim() orchestration over Task 4's origination ledger and subject
 * guard. Most of this suite runs against the in-memory SQLite harness ({@see PayviaTestCase}); a
 * final test is gated on a real, reachable PostgreSQL instance and exercises `prepare()`'s
 * savepoint/re-read guard-claim path against genuine row-lock contention via a subprocess (PHP
 * has no threads), mirroring CheckoutOriginationLedgerTest's own race idiom.
 */
final class SubscriptionCheckoutServiceTest extends PayviaTestCase
{
    /**
     * Deliberately non-empty: CheckoutSubjectGuardRepository::lockAndClaim()/release() both
     * treat an EMPTY tenantUuid as invalid input and refuse immediately (unlike the origination
     * ledger, which tolerates '' as a legitimate single-store sentinel), so this suite's
     * resolver ({@see FixedTenantResolver}) must never resolve to ''.
     */
    private const TENANT = 'tenantAAAA01';

    private CheckoutOriginationRepository $originations;
    private CheckoutSubjectGuardRepository $guards;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateCheckoutOriginations());
        // Deliberately pass ONLY the connection (no context) here, mirroring
        // CheckoutOriginationLedgerTest's own convention: BaseRepository::getSharedConnection()
        // silently swaps the shared connection for a fresh, unmigrated `Connection::fromContext()`
        // whenever a non-null context is passed alongside a connection that itself has no
        // context -- passing both here would replace this test's migrated in-memory database.
        //
        // The origination repository's OWN resolver must be the SAME FixedTenantResolver the
        // service uses for the guard: CheckoutOriginationRepository resolves tenant_uuid
        // internally (never trusting a caller-supplied value), so a mismatched resolver here
        // would silently write origination rows under a different tenant than the guard rows
        // the same prepare() call claims, breaking every same-tenant lookup between them.
        $this->originations = new CheckoutOriginationRepository(
            $this->connection,
            resolver: new FixedTenantResolver(self::TENANT),
        );
        $this->guards = new CheckoutSubjectGuardRepository($this->connection);
    }

    // ==================================================================
    // construction: the one-connection invariant is enforced, not just assumed
    // ==================================================================

    /**
     * The constructor's `getConnection()` equality assertion is not dead defensive code: prove
     * it actually fires when the two repositories are (mis)wired to different connections,
     * rather than silently constructing a service whose "one transaction" would be a no-op.
     */
    public function testConstructorFailsFastWhenTheRepositoriesUseDifferentConnections(): void
    {
        $otherConnection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        (new CreateCheckoutOriginations())->up($otherConnection->getSchemaBuilder());
        $mismatchedGuards = new CheckoutSubjectGuardRepository($otherConnection);

        $fake = new FakeSubscriptionInitiationGateway();
        $this->bind(FakeSubscriptionInitiationGateway::class, $fake);
        $gateways = new GatewayManager($this->context->getContainer(), $this->context);
        $gateways->registerDriver('fakegw', FakeSubscriptionInitiationGateway::class);

        $this->expectException(\LogicException::class);
        new SubscriptionCheckoutService(
            $this->originations,
            $mismatchedGuards,
            $gateways,
            new FixedTenantResolver(self::TENANT),
        );
    }

    // ==================================================================
    // prepare(): validate before any write
    // ==================================================================

    public function testUnsupportedGatewayThrowsBeforeAnyWrite(): void
    {
        $fake = new FakeSubscriptionInitiationGateway();
        $service = $this->service($fake);

        $this->expectException(CheckoutUnavailableException::class);
        try {
            $service->prepare($this->context, $this->request(['gateway' => 'does-not-exist']), $this->noop());
        } finally {
            self::assertSame(0, $this->connection->table('subscription_checkout_originations')->count());
            self::assertSame(0, $this->connection->table('subscription_checkout_subject_guards')->count());
        }
    }

    public function testEmptyProviderPlanIdentifierThrowsBeforeAnyWrite(): void
    {
        $service = $this->service(new FakeSubscriptionInitiationGateway());

        $this->expectException(CheckoutUnavailableException::class);
        try {
            $service->prepare($this->context, $this->request(['providerPlanIdentifier' => '  ']), $this->noop());
        } finally {
            self::assertSame(0, $this->connection->table('subscription_checkout_originations')->count());
        }
    }

    // ==================================================================
    // prepare(): happy path
    // ==================================================================

    public function testHappyPathClaimsTheOriginationRowAndGuardAndReturnsAnInitializingClaim(): void
    {
        $service = $this->service(new FakeSubscriptionInitiationGateway());
        $request = $this->request();
        $bound = [];

        $continuation = function (SubscriptionCheckoutClaim $c) use (&$bound): void {
            $bound[] = $c;
        };
        $claim = $service->prepare($this->context, $request, $continuation);

        self::assertSame($request->originationUuid, $claim->originationUuid);
        self::assertSame('initializing', $claim->status);
        self::assertNull($claim->checkoutUrl);
        self::assertFalse($claim->replayed);

        // The continuation received an IMMUTABLE claim reflecting the pre-markPrepared state.
        self::assertCount(1, $bound);
        self::assertSame($request->originationUuid, $bound[0]->originationUuid);
        self::assertSame('preparing', $bound[0]->status);
        self::assertFalse($bound[0]->replayed);

        $row = $this->originations->findByUuid($request->originationUuid);
        self::assertSame('initializing', $row['status']);
        self::assertTrue((bool) $row['live']);

        $guard = $this->findGuard(self::TENANT, $request->subjectKey);
        self::assertSame('live', $guard['state']);
        self::assertSame($request->originationUuid, $guard['origination_uuid']);
    }

    /**
     * A caller need not pre-mint an `originationUuid` at all: an empty one falls back to a
     * freshly generated one, and that minted value -- not the empty string the request carried
     * -- is what actually gets persisted and returned.
     */
    public function testEmptyOriginationUuidMintsAFreshOneAndPersistsUnderIt(): void
    {
        $service = $this->service(new FakeSubscriptionInitiationGateway());
        $request = $this->request(['originationUuid' => '']);

        $claim = $service->prepare($this->context, $request, $this->noop());

        self::assertNotSame('', $claim->originationUuid);
        self::assertSame(12, strlen($claim->originationUuid), 'minted uuids match the nanoid(12) column width');
        self::assertSame('initializing', $claim->status);

        $row = $this->originations->findByUuid($claim->originationUuid);
        self::assertNotNull($row, 'the row must be persisted under the MINTED uuid');
        self::assertSame($request->idempotencyKey, $row['idempotency_key']);

        $guard = $this->findGuard(self::TENANT, $request->subjectKey);
        self::assertSame($claim->originationUuid, $guard['origination_uuid']);
    }

    // ==================================================================
    // prepare(): continuation throw rolls EVERYTHING back
    // ==================================================================

    public function testContinuationThrowRollsBackClaimGuardAndReservationTogether(): void
    {
        $service = $this->service(new FakeSubscriptionInitiationGateway());
        $request = $this->request();

        try {
            $service->prepare($this->context, $request, function (): void {
                throw new \RuntimeException('reservation failed');
            });
            self::fail('Expected the continuation throw to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('reservation failed', $e->getMessage());
        }

        self::assertNull($this->originations->findByUuid($request->originationUuid));
        self::assertSame(0, $this->connection->table('subscription_checkout_originations')->count());
        self::assertNull($this->findGuard(self::TENANT, $request->subjectKey));
    }

    // ==================================================================
    // prepare(): same-key replay
    // ==================================================================

    public function testSameKeyReplayWhileItsOwnGuardIsLiveSkipsBothGuardClaimAndContinuation(): void
    {
        $service = $this->service(new FakeSubscriptionInitiationGateway());
        $request = $this->request();

        $first = $service->prepare($this->context, $request, $this->noop());

        $calls = 0;
        $second = $service->prepare(
            $this->context,
            $this->request([
                'originationUuid' => 'origREPLAY01', // must be ignored: replay returns the FIRST row
                'idempotencyKey' => $request->idempotencyKey,
                'subjectKey' => $request->subjectKey,
                'gateway' => $request->gateway,
                'providerPlanIdentifier' => $request->providerPlanIdentifier,
                'consumerMetadata' => $request->consumerMetadata,
                'customerEmail' => $request->customerEmail,
                'returnUrl' => $request->returnUrl,
                'cancelUrl' => $request->cancelUrl,
                'requiredProjectionConsumer' => $request->requiredProjectionConsumer,
            ]),
            function () use (&$calls): void {
                $calls++;
            },
        );

        self::assertSame($first->originationUuid, $second->originationUuid);
        self::assertSame('initializing', $second->status);
        self::assertTrue($second->replayed);
        self::assertSame(0, $calls, 'the continuation must never run for a replay');

        self::assertSame(1, $this->connection->table('subscription_checkout_originations')->count());
        self::assertSame(1, $this->connection->table('subscription_checkout_subject_guards')->count());
    }

    public function testConcurrentSameKeyInsertRaceReReadsOneWinner(): void
    {
        // Simulates another session having already won the (tenant, idempotency_key) race and
        // committed BEFORE this call's own claimPreparing() attempt runs.
        $request = $this->request();
        $winnerUuid = 'origWINNER01';
        $this->seedOrigination($winnerUuid, 'initializing', [
            'idempotency_key' => $request->idempotencyKey,
            'subject_key' => $request->subjectKey,
            'request_fingerprint' => $this->fingerprintFor($request),
        ]);

        $service = $this->service(new FakeSubscriptionInitiationGateway());
        $calls = 0;
        $claim = $service->prepare($this->context, $request, function () use (&$calls): void {
            $calls++;
        });

        self::assertSame($winnerUuid, $claim->originationUuid);
        self::assertNotSame($request->originationUuid, $claim->originationUuid);
        self::assertTrue($claim->replayed);
        self::assertSame(0, $calls);
        self::assertSame(1, $this->connection->table('subscription_checkout_originations')->count());
    }

    public function testFingerprintMismatchOnReplayThrowsIdempotencyConflict(): void
    {
        $request = $this->request();
        $service = $this->service(new FakeSubscriptionInitiationGateway());
        $service->prepare($this->context, $request, $this->noop());

        $this->expectException(IdempotencyConflictException::class);
        try {
            $service->prepare(
                $this->context,
                $this->request([
                    'idempotencyKey' => $request->idempotencyKey,
                    'subjectKey' => $request->subjectKey,
                    // Different customerEmail -> different fingerprint for the SAME key.
                    'customerEmail' => 'someone-else@example.test',
                ]),
                $this->noop(),
            );
        } finally {
            self::assertSame(1, $this->connection->table('subscription_checkout_originations')->count());
        }
    }

    public function testTerminalSameKeyReplayReturnsTheStoredTerminalResult(): void
    {
        $request = $this->request();
        $this->seedOrigination($request->originationUuid, 'failed', [
            'idempotency_key' => $request->idempotencyKey,
            'subject_key' => $request->subjectKey,
            'request_fingerprint' => $this->fingerprintFor($request),
            'live' => false,
        ]);

        $service = $this->service(new FakeSubscriptionInitiationGateway());
        $calls = 0;
        $claim = $service->prepare($this->context, $request, function () use (&$calls): void {
            $calls++;
        });

        self::assertSame('failed', $claim->status);
        self::assertTrue($claim->replayed);
        self::assertSame(0, $calls);
        self::assertNull($this->findGuard(self::TENANT, $request->subjectKey));
    }

    // ==================================================================
    // prepare(): different key + already-live guard
    // ==================================================================

    public function testDifferentKeyWithLiveGuardRefusesAndRollsBackTheNewRow(): void
    {
        $service = $this->service(new FakeSubscriptionInitiationGateway());
        $first = $this->request();
        $service->prepare($this->context, $first, $this->noop());

        $second = $this->request(['subjectKey' => $first->subjectKey]);

        try {
            $service->prepare($this->context, $second, $this->noop());
            self::fail('Expected OriginationLiveException');
        } catch (OriginationLiveException $e) {
            self::assertStringContainsString($first->subjectKey, $e->getMessage());
        }

        self::assertNull($this->originations->findByUuid($second->originationUuid));
        self::assertSame(1, $this->connection->table('subscription_checkout_originations')->count());

        $guard = $this->findGuard(self::TENANT, $first->subjectKey);
        self::assertSame($first->originationUuid, $guard['origination_uuid']);
        self::assertSame(1, $guard['revision'], 'the refused claim must never bump the guard revision');
    }

    // ==================================================================
    // prepare(): single-store (sentinel, tenantUuid === '') deployments
    // ==================================================================

    /**
     * `SentinelTenantResolver` (tenantUuid always `''`) is the documented production fallback
     * for single-store deployments with no bound `CurrentTenantResolver`, and
     * `CheckoutOriginationRepository` already documents tolerating `''` as a legitimate
     * single-store sentinel -- so the subject guard must agree, end to end through the service,
     * not just refuse every claim with a misleading `OriginationLiveException`.
     */
    public function testSentinelTenantResolverSucceedsEndToEndForSingleStoreDeployments(): void
    {
        $originations = new CheckoutOriginationRepository($this->connection, resolver: new SentinelTenantResolver());
        $fake = new FakeSubscriptionInitiationGateway();
        $this->bind(FakeSubscriptionInitiationGateway::class, $fake);
        $gateways = new GatewayManager($this->context->getContainer(), $this->context);
        $gateways->registerDriver('fakegw', FakeSubscriptionInitiationGateway::class);

        $service = new SubscriptionCheckoutService(
            $originations,
            $this->guards,
            $gateways,
            new SentinelTenantResolver(),
        );

        $request = $this->request();
        $claim = $service->prepare($this->context, $request, $this->noop());

        self::assertSame('initializing', $claim->status);
        self::assertFalse($claim->replayed);

        $guard = $this->findGuard('', $request->subjectKey);
        self::assertNotNull($guard, 'the sentinel-tenant guard must actually be claimed, not silently skipped');
        self::assertSame('live', $guard['state']);
        self::assertSame($request->originationUuid, $guard['origination_uuid']);

        // The guard's live-attempt authority still functions under the sentinel tenant: a
        // second, different-key attempt at the SAME subject must still be refused -- proving
        // this isn't merely "doesn't crash" but the guard genuinely fences concurrent attempts.
        $second = $this->request(['subjectKey' => $request->subjectKey]);
        try {
            $service->prepare($this->context, $second, $this->noop());
            self::fail('Expected OriginationLiveException under the sentinel tenant too');
        } catch (OriginationLiveException $e) {
            self::assertStringContainsString($request->subjectKey, $e->getMessage());
        }

        self::assertNull($originations->findByUuid($second->originationUuid));
        self::assertSame($request->originationUuid, $this->findGuard('', $request->subjectKey)['origination_uuid']);
    }

    // ==================================================================
    // initializeClaim(): happy path
    // ==================================================================

    public function testInitializeClaimSuccessTransitionsToPendingClearsEmailAndLease(): void
    {
        $fake = new FakeSubscriptionInitiationGateway();
        $service = $this->service($fake);
        $request = $this->request();
        $claim = $service->prepare($this->context, $request, $this->noop());

        $result = $service->initializeClaim($this->context, $claim->originationUuid);

        self::assertSame('pending', $result->status);
        self::assertSame('https://checkout.fake.test/' . $claim->originationUuid, $result->checkoutUrl);
        self::assertSame(1, $fake->calls);
        self::assertSame($claim->originationUuid, $fake->requests[0]->originationUuid);

        $row = $this->originations->findByUuid($claim->originationUuid);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['customer_email']);
        self::assertNull($row['initialization_claim_token']);
        self::assertNull($row['initialization_claimed_at']);
        self::assertSame('cs_fake_' . $claim->originationUuid, $row['checkout_reference']);
    }

    public function testInitializeClaimOnAnAlreadyResolvedOriginationReturnsTheStoredResultWithZeroProviderCalls(): void
    {
        $fake = new FakeSubscriptionInitiationGateway();
        $service = $this->service($fake);
        $uuid = 'origALREADY1';
        $this->seedOrigination($uuid, 'pending', [
            'checkout_reference' => 'cs_existing',
            'checkout_url' => 'https://checkout.fake.test/existing',
        ]);

        $result = $service->initializeClaim($this->context, $uuid);

        self::assertSame('pending', $result->status);
        self::assertSame('https://checkout.fake.test/existing', $result->checkoutUrl);
        self::assertSame(0, $fake->calls);
    }

    public function testInitializeClaimOnAnUnknownOriginationThrowsCheckoutUnavailable(): void
    {
        $service = $this->service(new FakeSubscriptionInitiationGateway());

        $this->expectException(CheckoutUnavailableException::class);
        $service->initializeClaim($this->context, 'no-such-uuid');
    }

    // ==================================================================
    // initializeClaim(): recovery matrix
    // ==================================================================

    public function testUnknownFailureReleasesOnlyTheLeaseRetainsEverythingElseAndRethrows(): void
    {
        $fake = new FakeSubscriptionInitiationGateway();
        $fake->throw = new \RuntimeException('provider boom: unknown outcome');
        $service = $this->service($fake);
        $request = $this->request();
        $claim = $service->prepare($this->context, $request, $this->noop());

        try {
            $service->initializeClaim($this->context, $claim->originationUuid);
            self::fail('Expected the unknown failure to rethrow');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('provider boom', $e->getMessage());
        }

        $row = $this->originations->findByUuid($claim->originationUuid);
        self::assertSame('initializing', $row['status'], 'status must be retained on an unknown failure');
        self::assertSame($request->customerEmail, $row['customer_email'], 'email must be retained');
        self::assertSame($request->idempotencyKey, $row['idempotency_key'], 'idempotency key must be retained');
        self::assertNull($row['initialization_claim_token'], 'only the lease is released');
        self::assertNull($row['initialization_claimed_at']);

        $guard = $this->findGuard(self::TENANT, $request->subjectKey);
        self::assertSame('live', $guard['state'], 'the guard must be retained on an unknown failure');
        self::assertSame($claim->originationUuid, $guard['origination_uuid']);

        // A resumed replay calls the provider again with the SAME origination (and therefore
        // the same provider idempotency key a real driver would derive from it).
        $result = $service->initializeClaim($this->context, $claim->originationUuid);
        self::assertSame('pending', $result->status);
        self::assertSame(2, $fake->calls);
        self::assertSame($claim->originationUuid, $fake->requests[1]->originationUuid);
    }

    /**
     * Named regression guard (design spec §3.2): a plain, arbitrary `\RuntimeException` -- NOT
     * the typed {@see DefinitiveSubscriptionCheckoutRejection} -- must never be misclassified as
     * a definitive rejection. It must retain the guard/email/status exactly like any other
     * unknown failure, never advancing to `failed` or releasing the guard.
     */
    public function testArbitraryRuntimeExceptionIsNeverMisclassifiedAsADefinitiveRejection(): void
    {
        $fake = new FakeSubscriptionInitiationGateway();
        $fake->throw = new \RuntimeException('some ordinary unrelated failure');
        $service = $this->service($fake);
        $request = $this->request();
        $claim = $service->prepare($this->context, $request, $this->noop());

        try {
            $service->initializeClaim($this->context, $claim->originationUuid);
            self::fail('Expected the exception to propagate');
        } catch (DefinitiveSubscriptionCheckoutRejection $e) {
            self::fail('An arbitrary RuntimeException must never become the typed definitive rejection');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(DefinitiveSubscriptionCheckoutRejection::class, $e);
        }

        $row = $this->originations->findByUuid($claim->originationUuid);
        self::assertSame('initializing', $row['status']);
        self::assertSame('live', $this->findGuard(self::TENANT, $request->subjectKey)['state']);
    }

    public function testDefinitiveRejectionMarksFailedClearsEmailAndReleasesTheGuard(): void
    {
        $fake = new FakeSubscriptionInitiationGateway();
        $fake->throw = DefinitiveSubscriptionCheckoutRejection::forStripeError(
            ['message' => 'No such price', 'code' => 'resource_missing'],
            ['error' => ['message' => 'No such price']],
        );
        $service = $this->service($fake);
        $request = $this->request();
        $claim = $service->prepare($this->context, $request, $this->noop());

        $result = $service->initializeClaim($this->context, $claim->originationUuid);

        self::assertSame('failed', $result->status);
        self::assertNull($result->checkoutUrl);

        $row = $this->originations->findByUuid($claim->originationUuid);
        self::assertSame('failed', $row['status']);
        self::assertNull($row['customer_email']);
        self::assertNull($row['initialization_claim_token']);
        self::assertFalse((bool) $row['live']);

        $guard = $this->findGuard(self::TENANT, $request->subjectKey);
        self::assertSame('open', $guard['state'], 'the guard must be released back to open, not deleted');
        self::assertNull($guard['origination_uuid']);
    }

    // ==================================================================
    // initializeClaim(): the execution lease -- loser, stale takeover, old-owner refusal
    // ==================================================================

    public function testSimultaneousInitializeCallsMakeExactlyOneGatewayCallAndTheLoserCannotPersist(): void
    {
        $fake = new FakeSubscriptionInitiationGateway();
        $service = $this->service($fake);
        $request = $this->request();
        $claim = $service->prepare($this->context, $request, $this->noop());

        // Simulate an owner already in flight: claim the lease directly, exactly as
        // initializeClaim() itself would, but without ever calling the provider.
        $inFlightToken = $this->originations->claimInitialization(
            $this->context,
            $claim->originationUuid,
            'tok-inflight',
            new \DateTimeImmutable('-1 second'),
        );
        self::assertNotNull($inFlightToken);

        $result = $service->initializeClaim($this->context, $claim->originationUuid);

        self::assertSame('initializing', $result->status);
        self::assertNull($result->checkoutUrl);
        self::assertSame(0, $fake->calls, 'a concurrent loser must perform zero provider I/O');

        $row = $this->originations->findByUuid($claim->originationUuid);
        self::assertSame('tok-inflight', $row['initialization_claim_token'], 'the loser must not touch the lease');
    }

    public function testStaleLeaseIsReclaimableAndTheOwnerThenSucceeds(): void
    {
        $fake = new FakeSubscriptionInitiationGateway();
        $service = $this->service($fake);
        $request = $this->request();
        $claim = $service->prepare($this->context, $request, $this->noop());

        self::assertNotNull($this->originations->claimInitialization(
            $this->context,
            $claim->originationUuid,
            'tok-stale',
            new \DateTimeImmutable('-1 second'),
        ));
        $this->forceStaleClaim($claim->originationUuid);

        $result = $service->initializeClaim($this->context, $claim->originationUuid);

        self::assertSame('pending', $result->status);
        self::assertSame(1, $fake->calls);
    }

    public function testOldOwnerCannotLateWriteAfterAnotherReclaimsTheStaleLease(): void
    {
        $fake = new FakeSubscriptionInitiationGateway();
        $service = $this->service($fake);
        $request = $this->request();
        $claim = $service->prepare($this->context, $request, $this->noop());

        self::assertNotNull($this->originations->claimInitialization(
            $this->context,
            $claim->originationUuid,
            'tok-old',
            new \DateTimeImmutable('-1 second'),
        ));
        $this->forceStaleClaim($claim->originationUuid);

        $result = $service->initializeClaim($this->context, $claim->originationUuid);
        self::assertSame('pending', $result->status);

        // The old, superseded token can never complete or release after the takeover.
        self::assertFalse(
            $this->originations->completeInitialization($this->context, $claim->originationUuid, 'tok-old', 'pending')
        );
        self::assertFalse(
            $this->originations->releaseInitialization($this->context, $claim->originationUuid, 'tok-old')
        );
        self::assertSame('pending', $this->originations->findByUuid($claim->originationUuid)['status']);
    }

    // ==================================================================
    // initializeClaim(): the lease's staleness window is clock-injectable
    // ==================================================================

    public function testTheInitializationLeaseStalenessWindowUsesTheInjectedClockNotWallClockTime(): void
    {
        $frozenNow = new \DateTimeImmutable('2030-01-01 00:00:00');
        $clock = static fn (): \DateTimeImmutable => $frozenNow;

        // Not-yet-stale relative to the injected clock: 119s old, lease window is 120s.
        $fakeA = new FakeSubscriptionInitiationGateway();
        $serviceA = $this->service($fakeA, $clock);
        $requestA = $this->request();
        $claimA = $serviceA->prepare($this->context, $requestA, $this->noop());
        self::assertNotNull($this->originations->claimInitialization(
            $this->context,
            $claimA->originationUuid,
            'tok-fresh',
            new \DateTimeImmutable('-1 second'),
        ));
        $this->connection->table('subscription_checkout_originations')
            ->where(['uuid' => $claimA->originationUuid])
            ->update(['initialization_claimed_at' => $frozenNow->modify('-119 seconds')->format('Y-m-d H:i:s')]);

        $resultA = $serviceA->initializeClaim($this->context, $claimA->originationUuid);
        self::assertSame('initializing', $resultA->status, 'not yet stale relative to the injected clock');
        self::assertSame(0, $fakeA->calls);

        // Stale relative to the SAME injected clock: 121s old.
        $fakeB = new FakeSubscriptionInitiationGateway();
        $serviceB = $this->service($fakeB, $clock);
        $requestB = $this->request();
        $claimB = $serviceB->prepare($this->context, $requestB, $this->noop());
        self::assertNotNull($this->originations->claimInitialization(
            $this->context,
            $claimB->originationUuid,
            'tok-old',
            new \DateTimeImmutable('-1 second'),
        ));
        $this->connection->table('subscription_checkout_originations')
            ->where(['uuid' => $claimB->originationUuid])
            ->update(['initialization_claimed_at' => $frozenNow->modify('-121 seconds')->format('Y-m-d H:i:s')]);

        $resultB = $serviceB->initializeClaim($this->context, $claimB->originationUuid);
        self::assertSame('pending', $resultB->status, 'stale relative to the injected clock must be reclaimable');
        self::assertSame(1, $fakeB->calls);
    }

    // ==================================================================
    // prepare(): the ONE-transaction guarantee under the REAL provider factory wiring
    // ==================================================================

    /**
     * Every other test in this suite hand-threads ONE `Connection` object through the service
     * and both repositories directly (the `service()` helper) -- that alone can never catch a
     * wiring bug where the REAL `PayviaServiceProvider` factories resolve the service's
     * connection and the repositories' connections differently (pooling, an unseeded
     * `BaseRepository` static cache, or plain construction-order differences could all make the
     * one-transaction guarantee a silent no-op). This boots everything through the ACTUAL
     * factory methods (`makeCheckoutOriginationRepository()`, `makeCheckoutSubjectGuardRepository()`,
     * `makeSubscriptionCheckoutService()`) via container resolution, proving a continuation throw
     * still rolls back BOTH the origination row and the guard claim together.
     */
    public function testRealProviderFactoryWiringRollsBackBothTablesOnContinuationThrow(): void
    {
        // Built WITH a context, mirroring how the real framework binds `Connection::class` in
        // production (`CoreProvider`: `new Connection($config, $this->context)`) -- unlike this
        // suite's OWN `$this->connection` (built without one), which would otherwise trip
        // `BaseRepository::getSharedConnection()`'s "replace when the shared connection has no
        // context" branch the moment a context-bearing factory call also supplies a connection.
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ], $this->context);
        (new CreateCheckoutOriginations())->up($connection->getSchemaBuilder());

        $fake = new FakeSubscriptionInitiationGateway();
        $container = $this->realFactoryContainer($this->context, $connection, $fake, self::TENANT);

        $service = $container->get(SubscriptionCheckoutService::class);
        self::assertInstanceOf(SubscriptionCheckoutService::class, $service);

        $request = $this->request();
        try {
            $service->prepare($this->context, $request, function (): void {
                throw new \RuntimeException('reservation failed');
            });
            self::fail('Expected the continuation throw to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('reservation failed', $e->getMessage());
        }

        self::assertSame(0, $connection->table('subscription_checkout_originations')->count());
        self::assertNull(
            $connection->table('subscription_checkout_subject_guards')
                ->where(['tenant_uuid' => self::TENANT, 'subject_key' => $request->subjectKey])
                ->limit(1)
                ->first()
        );
    }

    // ==================================================================
    // PostgreSQL-gated: real concurrent different-key race
    // ==================================================================

    /**
     * Real, independent second actor (a subprocess) racing a genuinely concurrent FIRST claim of
     * a brand-new subject against a connection that has already inserted (but not yet committed)
     * a winning guard row. Mirrors CheckoutOriginationLedgerTest's own guard race exactly, but
     * drives the loser through the FULL `SubscriptionCheckoutService::prepare()` -- proving the
     * loser's freshly-inserted `preparing` origination row rolls back together with its failed
     * guard claim under genuine PostgreSQL row-lock contention, not just SQLite's forgiving
     * error behavior.
     */
    public function testConcurrentDifferentKeyPrepareRaceYieldsExactlyOneLiveGuardOnPostgres(): void
    {
        $pg = $this->pgsqlConnectionForService();

        $subject = $this->uniqueKey('svcrace');
        $winnerUuid = 'winnerorig01';
        $loserUuid = 'loserorigin1';

        $pg->getTransactionManager()->begin();
        $pg->table('subscription_checkout_subject_guards')->insert([
            'uuid' => substr($this->uniqueKey('grd'), 0, 12),
            'tenant_uuid' => self::TENANT,
            'subject_key' => $subject,
            'state' => 'live',
            'origination_uuid' => $winnerUuid,
            'revision' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $loserIdempotencyKey = $this->uniqueKey('svcidem');
        $handle = $this->launchPrepareRaceChild([
            'subjectKey' => $subject,
            'idempotencyKey' => $loserIdempotencyKey,
            'originationUuid' => $loserUuid,
        ]);
        usleep(300_000);
        $pg->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse(
            $result['ok'] ?? true,
            'the loser must fail once the winner has committed: ' . json_encode($result)
        );
        self::assertSame('Glueful\Extensions\Payvia\Checkout\OriginationLiveException', $result['exception'] ?? null);

        $guardRows = $pg->table('subscription_checkout_subject_guards')
            ->where(['tenant_uuid' => self::TENANT, 'subject_key' => $subject])
            ->get();
        self::assertCount(1, $guardRows, 'exactly one guard row may exist after the race');
        self::assertSame('live', $guardRows[0]['state']);
        self::assertSame($winnerUuid, $guardRows[0]['origination_uuid']);

        // Invariant: the loser's own `preparing` origination row must never persist.
        $originationRows = $pg->table('subscription_checkout_originations')
            ->where(['idempotency_key' => $loserIdempotencyKey])
            ->get();
        self::assertCount(0, $originationRows);
    }

    /**
     * PostgreSQL-gated variant of {@see testRealProviderFactoryWiringRollsBackBothTablesOnContinuationThrow()}:
     * the ONE-transaction guarantee under the REAL provider factory wiring, proven against a
     * real PostgreSQL connection rather than SQLite's more forgiving transaction semantics.
     */
    public function testRealProviderFactoryWiringRollsBackBothTablesOnContinuationThrowOnPostgres(): void
    {
        $connection = $this->pgsqlConnectionForService($this->context);

        $fake = new FakeSubscriptionInitiationGateway();
        $container = $this->realFactoryContainer($this->context, $connection, $fake, self::TENANT);
        $service = $container->get(SubscriptionCheckoutService::class);
        self::assertInstanceOf(SubscriptionCheckoutService::class, $service);

        $request = $this->request();
        try {
            $service->prepare($this->context, $request, function (): void {
                throw new \RuntimeException('reservation failed');
            });
            self::fail('Expected the continuation throw to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('reservation failed', $e->getMessage());
        }

        self::assertSame(
            0,
            $connection->table('subscription_checkout_originations')
                ->where(['idempotency_key' => $request->idempotencyKey])
                ->count()
        );
        self::assertNull(
            $connection->table('subscription_checkout_subject_guards')
                ->where(['tenant_uuid' => self::TENANT, 'subject_key' => $request->subjectKey])
                ->limit(1)
                ->first()
        );
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function service(
        FakeSubscriptionInitiationGateway $gateway,
        ?\Closure $clock = null,
    ): SubscriptionCheckoutService {
        $this->bind(FakeSubscriptionInitiationGateway::class, $gateway);

        $gateways = new GatewayManager($this->context->getContainer(), $this->context);
        $gateways->registerDriver('fakegw', FakeSubscriptionInitiationGateway::class);

        return new SubscriptionCheckoutService(
            $this->originations,
            $this->guards,
            $gateways,
            new FixedTenantResolver(self::TENANT),
            $clock,
        );
    }

    private function noop(): callable
    {
        return static function (): void {
        };
    }

    /**
     * A container that resolves the checkout services via `PayviaServiceProvider`'s OWN factory
     * methods (`makeCheckoutOriginationRepository()`, `makeCheckoutSubjectGuardRepository()`,
     * `makeSubscriptionCheckoutService()`) rather than this suite's hand-threaded `service()`
     * helper -- so a test built against it exercises the SAME wiring path production actually
     * uses, including the explicit `Connection::class` binding both repository factories now
     * depend on.
     */
    private function realFactoryContainer(
        ApplicationContext $context,
        Connection $connection,
        FakeSubscriptionInitiationGateway $fake,
        string $tenant,
    ): ContainerInterface {
        return new class ($context, $connection, $fake, $tenant) implements ContainerInterface {
            private ?GatewayManager $gateways = null;

            public function __construct(
                private ApplicationContext $context,
                private Connection $connection,
                private FakeSubscriptionInitiationGateway $fake,
                private string $tenant,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ApplicationContext::class => $this->context,
                    Connection::class => $this->connection,
                    PayviaTenantResolver::class => new FixedTenantResolver($this->tenant),
                    FakeSubscriptionInitiationGateway::class => $this->fake,
                    GatewayManager::class => $this->gatewayManager(),
                    CheckoutOriginationRepository::class
                        => PayviaServiceProvider::makeCheckoutOriginationRepository($this),
                    CheckoutSubjectGuardRepository::class
                        => PayviaServiceProvider::makeCheckoutSubjectGuardRepository($this),
                    SubscriptionCheckoutService::class
                        => PayviaServiceProvider::makeSubscriptionCheckoutService($this),
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    ApplicationContext::class,
                    Connection::class,
                    PayviaTenantResolver::class,
                    FakeSubscriptionInitiationGateway::class,
                    GatewayManager::class,
                    CheckoutOriginationRepository::class,
                    CheckoutSubjectGuardRepository::class,
                    SubscriptionCheckoutService::class,
                ], true);
            }

            private function gatewayManager(): GatewayManager
            {
                if ($this->gateways === null) {
                    $this->gateways = new GatewayManager($this, $this->context);
                    $this->gateways->registerDriver('fakegw', FakeSubscriptionInitiationGateway::class);
                }

                return $this->gateways;
            }
        };
    }

    /** @param array<string,mixed> $overrides */
    private function request(array $overrides = []): SubscriptionCheckoutRequest
    {
        self::$seq++;
        $values = array_replace([
            'originationUuid' => 'orig' . str_pad((string) self::$seq, 8, '0', STR_PAD_LEFT),
            'tenantUuid' => '',
            'subjectKey' => 'subject-' . self::$seq,
            'gateway' => 'fakegw',
            'providerPlanIdentifier' => 'plan_' . self::$seq,
            'consumerMetadata' => ['tier' => 'pro', 'seq' => (string) self::$seq],
            'customerEmail' => 'buyer' . self::$seq . '@example.test',
            'returnUrl' => 'https://shop.example.test/return',
            'cancelUrl' => 'https://shop.example.test/cancel',
            'idempotencyKey' => $this->uniqueKey('idem'),
            'requiredProjectionConsumer' => 'subscriptions',
        ], $overrides);

        return new SubscriptionCheckoutRequest(
            originationUuid: $values['originationUuid'],
            tenantUuid: $values['tenantUuid'],
            subjectKey: $values['subjectKey'],
            gateway: $values['gateway'],
            providerPlanIdentifier: $values['providerPlanIdentifier'],
            consumerMetadata: $values['consumerMetadata'],
            customerEmail: $values['customerEmail'],
            returnUrl: $values['returnUrl'],
            cancelUrl: $values['cancelUrl'],
            idempotencyKey: $values['idempotencyKey'],
            requiredProjectionConsumer: $values['requiredProjectionConsumer'],
        );
    }

    /** Recomputes the fingerprint the service would derive for a given request, for test seeding. */
    private function fingerprintFor(SubscriptionCheckoutRequest $request): string
    {
        $metadata = $request->consumerMetadata;
        ksort($metadata);

        $canonical = [
            'subjectKey' => $request->subjectKey,
            'gateway' => $request->gateway,
            'providerPlanIdentifier' => $request->providerPlanIdentifier,
            'consumerMetadata' => $metadata,
            'customerEmail' => $request->customerEmail,
            'returnUrl' => $request->returnUrl,
            'cancelUrl' => $request->cancelUrl,
            'requiredProjectionConsumer' => $request->requiredProjectionConsumer,
        ];

        return hash('sha256', (string) json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Inserts a row directly at an arbitrary status, bypassing the service under test. */
    private function seedOrigination(string $uuid, string $status, array $overrides = []): void
    {
        self::$seq++;
        $this->connection->table('subscription_checkout_originations')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'subject_key' => 'subject-' . self::$seq,
            'gateway' => 'fakegw',
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
    }

    private function forceStaleClaim(string $uuid): void
    {
        $this->connection->table('subscription_checkout_originations')
            ->where(['uuid' => $uuid])
            ->update(['initialization_claimed_at' => (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s')]);
    }

    /** @return array<string,mixed>|null */
    private function findGuard(string $tenantUuid, string $subjectKey): ?array
    {
        return $this->connection->table('subscription_checkout_subject_guards')
            ->where(['tenant_uuid' => $tenantUuid, 'subject_key' => $subjectKey])
            ->limit(1)
            ->first();
    }

    private function uniqueKey(string $prefix): string
    {
        self::$seq++;

        return $prefix . '-' . self::$seq . '-' . bin2hex(random_bytes(4));
    }

    /**
     * A real, reachable PostgreSQL connection for the test gated on it, or a skip. Configurable
     * via `DB_PGSQL_*` env vars (mirrors CheckoutOriginationLedgerTest's own convention);
     * defaults to a local `payvia_test` database as user `postgres`.
     *
     * `$context`, when passed, is threaded into the `Connection` constructor itself (mirroring
     * how `CoreProvider` binds the real `Connection::class` service in production) so
     * {@see realFactoryContainer()}'s "boot via the real provider factories" tests can pass this
     * connection alongside a context without tripping
     * `BaseRepository::getSharedConnection()`'s "replace when the shared connection has no
     * context" branch.
     */
    private function pgsqlConnectionForService(?ApplicationContext $context = null): Connection
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
            ], $context);
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
    private function launchPrepareRaceChild(array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/Fixtures/checkout-service/prepare_race_child.php',
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
