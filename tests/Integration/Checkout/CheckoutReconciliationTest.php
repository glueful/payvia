<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Checkout;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Checkout\CheckoutReconciliationRefused;
use Glueful\Extensions\Payvia\Checkout\CheckoutReconciliationService;
use Glueful\Extensions\Payvia\Database\Migrations\AddCheckoutOriginationReconciliationColumns;
use Glueful\Extensions\Payvia\Database\Migrations\CreateCheckoutOriginations;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tests\Support\FixedTenantResolver;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Psr\Container\ContainerInterface;

/**
 * Task 9 (workspace self-serve checkout, design spec §3.8): operator reconciliation.
 * `CheckoutReconciliationService::resolve()` is the ONLY sanctioned way to move a
 * `projection_rejected`, `late_settlement_conflict`, or stuck Paystack `pending` origination
 * forward -- same one-transaction discipline as `SubscriptionCheckoutService::prepare()`,
 * exactly two explicit resolutions, and a hard NEVER-rule set (no auto-refund, no rewriting a
 * committed rejected ack receipt, no activation, no bare `ignore`).
 */
final class CheckoutReconciliationTest extends PayviaTestCase
{
    private const TENANT = 'tenantAAAA01';

    private CheckoutOriginationRepository $originations;
    private CheckoutSubjectGuardRepository $guards;
    private CheckoutReconciliationService $service;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateCheckoutOriginations());
        $this->runMigration(new AddCheckoutOriginationReconciliationColumns());
        $this->originations = new CheckoutOriginationRepository($this->connection);
        $this->guards = new CheckoutSubjectGuardRepository($this->connection);
        $this->service = new CheckoutReconciliationService($this->originations, $this->guards);
    }

    // ==================================================================
    // construction: the one-connection invariant is enforced
    // ==================================================================

    public function testConstructorFailsFastWhenTheRepositoriesUseDifferentConnections(): void
    {
        $otherConnection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        (new CreateCheckoutOriginations())->up($otherConnection->getSchemaBuilder());
        $mismatchedGuards = new CheckoutSubjectGuardRepository($otherConnection);

        $this->expectException(\LogicException::class);
        new CheckoutReconciliationService($this->originations, $mismatchedGuards);
    }

    // ==================================================================
    // resolve(): refused before any write
    // ==================================================================

    public function testABareIgnoreResolutionIsRefused(): void
    {
        $uuid = $this->seed('pending');
        $this->guards->lockAndClaim($this->context, self::TENANT, $this->subjectFor($uuid), $uuid);

        $this->expectException(CheckoutReconciliationRefused::class);
        try {
            $this->service->resolve($this->context, $uuid, 'ignore', 'operator note', $this->noop());
        } finally {
            self::assertSame('pending', $this->originations->findByUuid($uuid)['status']);
        }
    }

    public function testAnUnrecognizedResolutionStringIsRefused(): void
    {
        $uuid = $this->seed('pending');

        $this->expectException(CheckoutReconciliationRefused::class);
        $this->service->resolve($this->context, $uuid, 'something_else', 'operator note', $this->noop());
    }

    /** @dataProvider emptyNoteProvider */
    public function testAnEmptyAuditNoteIsRefusedForEitherResolution(string $resolution, string $note): void
    {
        $uuid = $resolution === CheckoutReconciliationService::RESOLUTION_PROVIDER_CONFIRMED_DEAD
            ? $this->seed('pending')
            : $this->seed('projection_rejected');

        $this->expectException(CheckoutReconciliationRefused::class);
        try {
            $this->service->resolve($this->context, $uuid, $resolution, $note, $this->noop());
        } finally {
            self::assertNull($this->originations->findByUuid($uuid)['reconciliation_note']);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function emptyNoteProvider(): array
    {
        return [
            'confirmed_dead, empty string' => [CheckoutReconciliationService::RESOLUTION_PROVIDER_CONFIRMED_DEAD, ''],
            'confirmed_dead, whitespace only' => [
                CheckoutReconciliationService::RESOLUTION_PROVIDER_CONFIRMED_DEAD,
                "   \t  ",
            ],
            'canceled_or_refunded, empty string' => [
                CheckoutReconciliationService::RESOLUTION_PROVIDER_CANCELED_OR_REFUNDED,
                '',
            ],
        ];
    }

    public function testAnUnknownOriginationIsRefused(): void
    {
        $this->expectException(CheckoutReconciliationRefused::class);
        $this->service->resolve(
            $this->context,
            'no-such-uuid1',
            CheckoutReconciliationService::RESOLUTION_PROVIDER_CONFIRMED_DEAD,
            'operator note',
            $this->noop(),
        );
    }

    /** @dataProvider notReconcilableStatusProvider */
    public function testStatusesOutsideTheReconcilableSetAreRefused(string $status): void
    {
        $uuid = $this->seed($status);

        $this->expectException(CheckoutReconciliationRefused::class);
        try {
            $this->service->resolve(
                $this->context,
                $uuid,
                CheckoutReconciliationService::RESOLUTION_PROVIDER_CANCELED_OR_REFUNDED,
                'operator note',
                $this->noop(),
            );
        } finally {
            self::assertSame($status, $this->originations->findByUuid($uuid)['status']);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function notReconcilableStatusProvider(): array
    {
        return [
            'preparing' => ['preparing'],
            'initializing' => ['initializing'],
            'provider_observed' => ['provider_observed'],
            'dispatched' => ['dispatched'],
            'failed' => ['failed'],
            'expired' => ['expired'],
            'abandoned' => ['abandoned'],
        ];
    }

    // ==================================================================
    // resolve(): provider_confirmed_dead on a stuck Paystack `pending` row
    // ==================================================================

    public function testProviderConfirmedDeadOnAStuckPendingRowAdvancesToAbandonedAndOpensTheGuard(): void
    {
        $uuid = $this->seed('pending');
        $subject = $this->subjectFor($uuid);
        self::assertTrue($this->guards->lockAndClaim($this->context, self::TENANT, $subject, $uuid));

        $released = [];
        $this->service->resolve(
            $this->context,
            $uuid,
            CheckoutReconciliationService::RESOLUTION_PROVIDER_CONFIRMED_DEAD,
            'Confirmed with Paystack support: no charge, no subscription.',
            function (string $originationUuid) use (&$released): void {
                $released[] = $originationUuid;
            },
        );

        self::assertSame([$uuid], $released);

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('abandoned', $row['status']);
        self::assertFalse((bool) $row['live']);
        self::assertSame('provider_confirmed_dead', $row['reconciliation_resolution']);
        self::assertSame(
            'Confirmed with Paystack support: no charge, no subscription.',
            $row['reconciliation_note']
        );
        self::assertNotNull($row['reconciled_at']);

        $guard = $this->findGuard(self::TENANT, $subject);
        self::assertSame('open', $guard['state']);
        self::assertNull($guard['origination_uuid']);
    }

    public function testProviderConfirmedDeadIsRefusedOnceProviderMoneyHasBeenObserved(): void
    {
        // A `projection_rejected` row can ONLY be reached via `provider_observed`, so it always
        // counts as money-observed regardless of `provider_subscription_id` -- see the money-
        // observed definition in the service's own docblock.
        $uuid = $this->seed('projection_rejected');
        $subject = $this->subjectFor($uuid);
        $this->guards->block($this->context, self::TENANT, $subject, $uuid, 'projection rejected');

        $this->expectException(CheckoutReconciliationRefused::class);
        try {
            $this->service->resolve(
                $this->context,
                $uuid,
                CheckoutReconciliationService::RESOLUTION_PROVIDER_CONFIRMED_DEAD,
                'trying to claim nothing happened',
                $this->noop(),
            );
        } finally {
            $row = $this->originations->findByUuid($uuid);
            self::assertSame('projection_rejected', $row['status']);
            self::assertNull($row['reconciliation_resolution']);
            self::assertSame('blocked', $this->findGuard(self::TENANT, $subject)['state']);
        }
    }

    /**
     * A `pending` row that somehow already carries a `provider_subscription_id` (defensive
     * belt-and-suspenders case) must also refuse `provider_confirmed_dead` -- the money-observed
     * check is `provider_subscription_id !== null OR status in {...}`, not status alone.
     */
    public function testProviderConfirmedDeadIsRefusedWhenAPendingRowAlreadyCarriesAProviderSubscriptionId(): void
    {
        $uuid = $this->seed('pending', ['provider_subscription_id' => 'sub_already_exists']);

        $this->expectException(CheckoutReconciliationRefused::class);
        $this->service->resolve(
            $this->context,
            $uuid,
            CheckoutReconciliationService::RESOLUTION_PROVIDER_CONFIRMED_DEAD,
            'operator note',
            $this->noop(),
        );
    }

    /**
     * The mirror-image regression: a `pending` row that DID observe money (the same defensive
     * `provider_subscription_id` case as above) resolved via `provider_canceled_or_refunded`
     * must NOT collapse to `abandoned` -- that would write status=`abandoned` ("nothing
     * happened") beside resolution=`provider_canceled_or_refunded` ("something happened and was
     * undone"), a self-contradictory permanent record. `pending` stays `pending` as history,
     * exactly like the `projection_rejected`/`late_settlement_conflict` cases.
     */
    public function testProviderCanceledOrRefundedOnAPendingRowThatObservedMoneyPreservesPendingAsHistory(): void
    {
        $uuid = $this->seed('pending', ['provider_subscription_id' => 'sub_already_exists']);
        $subject = $this->subjectFor($uuid);
        $this->guards->lockAndClaim($this->context, self::TENANT, $subject, $uuid);

        $this->service->resolve(
            $this->context,
            $uuid,
            CheckoutReconciliationService::RESOLUTION_PROVIDER_CANCELED_OR_REFUNDED,
            'Canceled the subscription that was created despite the stuck local state.',
            $this->noop(),
        );

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('pending', $row['status'], 'money was observed, so pending must stay pending as history');
        self::assertSame('provider_canceled_or_refunded', $row['reconciliation_resolution']);
        self::assertSame(
            'Canceled the subscription that was created despite the stuck local state.',
            $row['reconciliation_note']
        );

        $guard = $this->findGuard(self::TENANT, $subject);
        self::assertSame('open', $guard['state']);
        self::assertNull($guard['origination_uuid']);
    }

    // ==================================================================
    // resolve(): provider_canceled_or_refunded on projection_rejected / late_settlement_conflict
    // ==================================================================

    public function testProviderCanceledOrRefundedOnAProjectionRejectedRowKeepsItsStatusAndOpensTheGuard(): void
    {
        $uuid = $this->seed('projection_rejected', [
            'projection_event_key' => 'evt-committed-1',
            'projection_outcome' => 'rejected',
            'projection_reason' => 'plan mismatch at projection time',
        ]);
        $subject = $this->subjectFor($uuid);
        $this->guards->block($this->context, self::TENANT, $subject, $uuid, 'projection rejected');

        $released = [];
        $this->service->resolve(
            $this->context,
            $uuid,
            CheckoutReconciliationService::RESOLUTION_PROVIDER_CANCELED_OR_REFUNDED,
            'Refunded the customer via the Stripe dashboard, ref #12345.',
            function (string $originationUuid) use (&$released): void {
                $released[] = $originationUuid;
            },
        );

        self::assertSame([$uuid], $released);

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('projection_rejected', $row['status'], 'the terminal status stays as history');
        self::assertFalse((bool) $row['live']);
        self::assertSame('provider_canceled_or_refunded', $row['reconciliation_resolution']);
        self::assertSame('Refunded the customer via the Stripe dashboard, ref #12345.', $row['reconciliation_note']);

        // The committed projection ack receipt is NEVER rewritten.
        self::assertSame('evt-committed-1', $row['projection_event_key']);
        self::assertSame('rejected', $row['projection_outcome']);
        self::assertSame('plan mismatch at projection time', $row['projection_reason']);

        $guard = $this->findGuard(self::TENANT, $subject);
        self::assertSame('open', $guard['state']);
        self::assertNull($guard['origination_uuid']);
    }

    public function testProviderCanceledOrRefundedOnALateSettlementConflictRowKeepsItsStatusAndOpensTheGuard(): void
    {
        $uuid = $this->seed('late_settlement_conflict', [
            'projection_event_key' => 'evt-conflict-1',
            'projection_outcome' => 'rejected',
            'projection_reason' => 'a newer origination already owns the subject',
        ]);
        $subject = $this->subjectFor($uuid);
        $this->guards->block($this->context, self::TENANT, $subject, $uuid, 'late settlement conflict');

        $this->service->resolve(
            $this->context,
            $uuid,
            CheckoutReconciliationService::RESOLUTION_PROVIDER_CANCELED_OR_REFUNDED,
            'Canceled the stale provider subscription manually.',
            $this->noop(),
        );

        $row = $this->originations->findByUuid($uuid);
        self::assertSame(
            'late_settlement_conflict',
            $row['status'],
            'late_settlement_conflict is permanently terminal; it needs no status transition out of it'
        );
        self::assertSame('provider_canceled_or_refunded', $row['reconciliation_resolution']);
        self::assertSame('evt-conflict-1', $row['projection_event_key'], 'the committed ack receipt is untouched');

        $guard = $this->findGuard(self::TENANT, $subject);
        self::assertSame('open', $guard['state']);
    }

    public function testProviderCanceledOrRefundedIsRefusedWhenProviderMoneyWasNeverObserved(): void
    {
        $uuid = $this->seed('pending');
        $subject = $this->subjectFor($uuid);
        $this->guards->lockAndClaim($this->context, self::TENANT, $subject, $uuid);

        $this->expectException(CheckoutReconciliationRefused::class);
        try {
            $this->service->resolve(
                $this->context,
                $uuid,
                CheckoutReconciliationService::RESOLUTION_PROVIDER_CANCELED_OR_REFUNDED,
                'nothing to cancel here',
                $this->noop(),
            );
        } finally {
            $row = $this->originations->findByUuid($uuid);
            self::assertSame('pending', $row['status']);
            self::assertNull($row['reconciliation_resolution']);
            self::assertSame('live', $this->findGuard(self::TENANT, $subject)['state']);
        }
    }

    // ==================================================================
    // resolve(): the ONE-transaction guarantee -- continuation throw rolls EVERYTHING back
    // ==================================================================

    public function testContinuationThrowRollsBackTheStatusNoteAndGuardTogether(): void
    {
        $uuid = $this->seed('pending');
        $subject = $this->subjectFor($uuid);
        $this->guards->lockAndClaim($this->context, self::TENANT, $subject, $uuid);

        try {
            $this->service->resolve(
                $this->context,
                $uuid,
                CheckoutReconciliationService::RESOLUTION_PROVIDER_CONFIRMED_DEAD,
                'operator note',
                function (): void {
                    throw new \RuntimeException('release failed');
                },
            );
            self::fail('Expected the continuation throw to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('release failed', $e->getMessage());
        }

        $row = $this->originations->findByUuid($uuid);
        self::assertSame('pending', $row['status'], 'the status write must roll back with everything else');
        self::assertNull($row['reconciliation_resolution']);
        self::assertNull($row['reconciliation_note']);

        $guard = $this->findGuard(self::TENANT, $subject);
        self::assertSame('live', $guard['state'], 'the guard reopen must roll back too');
        self::assertSame($uuid, $guard['origination_uuid']);
    }

    // ==================================================================
    // resolve(): the guard binding moved out from under the caller -- refusal, not a crash
    // ==================================================================

    /**
     * `CheckoutSubjectGuardRepository::block()` is unconditional -- a second block call with a
     * DIFFERENT origination can overwrite the binding an earlier reconciliation attempt expected.
     * `resolve()` must surface `reopen()`'s CAS failure as a typed refusal (and roll back the
     * origination's own status/note write) rather than crash or silently "succeed" without
     * actually freeing the subject.
     */
    public function testAGuardBindingThatMovedToADifferentOriginationIsSurfacedAsARefusalNotACrash(): void
    {
        $uuid = $this->seed('projection_rejected');
        $subject = $this->subjectFor($uuid);
        $this->guards->block($this->context, self::TENANT, $subject, $uuid, 'projection rejected');

        // A different origination's block() call overwrites the binding before this
        // reconciliation attempt runs.
        $this->guards->block($this->context, self::TENANT, $subject, 'origOTHERONE', 'unrelated hold');

        $this->expectException(CheckoutReconciliationRefused::class);
        try {
            $this->service->resolve(
                $this->context,
                $uuid,
                CheckoutReconciliationService::RESOLUTION_PROVIDER_CANCELED_OR_REFUNDED,
                'operator note',
                $this->noop(),
            );
        } finally {
            // The origination's own status/note write rolled back together with the failed
            // guard reopen -- NOT left half-applied.
            $row = $this->originations->findByUuid($uuid);
            self::assertNull($row['reconciliation_resolution']);
            self::assertSame('projection_rejected', $row['status']);

            $guard = $this->findGuard(self::TENANT, $subject);
            self::assertSame('origOTHERONE', $guard['origination_uuid'], 'the other hold must remain untouched');
        }
    }

    // ==================================================================
    // resolve(): the ONE-transaction guarantee under the REAL provider factory wiring
    // ==================================================================

    public function testRealProviderFactoryWiringRollsBackTheStatusNoteAndGuardTogetherOnContinuationThrow(): void
    {
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ], $this->context);
        (new CreateCheckoutOriginations())->up($connection->getSchemaBuilder());
        (new AddCheckoutOriginationReconciliationColumns())->up($connection->getSchemaBuilder());

        $container = $this->realFactoryContainer($this->context, $connection);
        $service = $container->get(CheckoutReconciliationService::class);
        self::assertInstanceOf(CheckoutReconciliationService::class, $service);

        self::$seq++;
        $uuid = 'orig' . str_pad((string) self::$seq, 8, '0', STR_PAD_LEFT);
        $subject = 'subject-' . self::$seq;
        $connection->table('subscription_checkout_originations')->insert($this->row($uuid, 'pending', $subject));
        $connection->table('subscription_checkout_subject_guards')->insert([
            'uuid' => substr($this->uniqueKey('grd'), 0, 12),
            'tenant_uuid' => self::TENANT,
            'subject_key' => $subject,
            'state' => 'live',
            'origination_uuid' => $uuid,
            'revision' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        try {
            $service->resolve(
                $this->context,
                $uuid,
                CheckoutReconciliationService::RESOLUTION_PROVIDER_CONFIRMED_DEAD,
                'operator note',
                function (): void {
                    throw new \RuntimeException('release failed');
                },
            );
            self::fail('Expected the continuation throw to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('release failed', $e->getMessage());
        }

        $row = $connection->table('subscription_checkout_originations')->where(['uuid' => $uuid])->limit(1)->first();
        self::assertSame('pending', $row['status']);

        $guard = $connection->table('subscription_checkout_subject_guards')
            ->where(['tenant_uuid' => self::TENANT, 'subject_key' => $subject])
            ->limit(1)
            ->first();
        self::assertSame('live', $guard['state']);
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function noop(): callable
    {
        return static function (): void {
        };
    }

    /** Inserts a row directly at an arbitrary status, bypassing the service under test. */
    private function seed(string $status, array $overrides = []): string
    {
        self::$seq++;
        $uuid = 'orig' . str_pad((string) self::$seq, 8, '0', STR_PAD_LEFT);
        $this->connection->table('subscription_checkout_originations')
            ->insert($this->row($uuid, $status, $this->subjectFor($uuid), $overrides));

        return $uuid;
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function row(string $uuid, string $status, string $subjectKey, array $overrides = []): array
    {
        return array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'subject_key' => $subjectKey,
            'gateway' => 'paystack',
            'provider_plan_identifier' => 'plan_' . $uuid,
            'idempotency_key' => $this->uniqueKey('idem'),
            'request_fingerprint' => str_repeat('a', 64),
            'return_url' => 'https://shop.example.test/return',
            'cancel_url' => 'https://shop.example.test/cancel',
            'status' => $status,
            'live' => !in_array($status, [
                'dispatched', 'failed', 'expired', 'abandoned', 'projection_rejected', 'late_settlement_conflict',
            ], true),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], $overrides);
    }

    private function subjectFor(string $uuid): string
    {
        return 'subject-for-' . $uuid;
    }

    private function uniqueKey(string $prefix): string
    {
        self::$seq++;

        return $prefix . '-' . self::$seq . '-' . bin2hex(random_bytes(4));
    }

    /** @return array<string,mixed>|null */
    private function findGuard(string $tenantUuid, string $subjectKey): ?array
    {
        return $this->connection->table('subscription_checkout_subject_guards')
            ->where(['tenant_uuid' => $tenantUuid, 'subject_key' => $subjectKey])
            ->limit(1)
            ->first();
    }

    private function realFactoryContainer(ApplicationContext $context, Connection $connection): ContainerInterface
    {
        return new class ($context, $connection, self::TENANT) implements ContainerInterface {
            public function __construct(
                private ApplicationContext $context,
                private Connection $connection,
                private string $tenant,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ApplicationContext::class => $this->context,
                    Connection::class => $this->connection,
                    // The origination repository always needs a resolver; this merely proves
                    // CheckoutReconciliationService's OWN constructor never asks the container
                    // for one directly (it takes only the two shared repositories).
                    PayviaTenantResolver::class => new FixedTenantResolver($this->tenant),
                    CheckoutOriginationRepository::class
                        => PayviaServiceProvider::makeCheckoutOriginationRepository($this),
                    CheckoutSubjectGuardRepository::class
                        => PayviaServiceProvider::makeCheckoutSubjectGuardRepository($this),
                    CheckoutReconciliationService::class
                        => PayviaServiceProvider::makeCheckoutReconciliationService($this),
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    ApplicationContext::class,
                    Connection::class,
                    PayviaTenantResolver::class,
                    CheckoutOriginationRepository::class,
                    CheckoutSubjectGuardRepository::class,
                    CheckoutReconciliationService::class,
                ], true);
            }
        };
    }
}
