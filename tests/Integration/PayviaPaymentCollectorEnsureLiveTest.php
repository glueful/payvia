<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Payvia\Contracts\HostedSessionRenewalCapableGateway;
use Glueful\Extensions\Payvia\Contracts\HostedSessionStateCapableGateway;
use Glueful\Extensions\Payvia\Contracts\InitiationCapableGateway;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Database\Migrations\AddPaymentIntentAttemptLifecycle;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Services\PayviaPaymentCollector;
use Glueful\Extensions\Payvia\Services\ProviderSessionStateUnknownException;
use Glueful\Extensions\Payvia\Services\SessionRenewalUnavailableException;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Ensure-live hosted sessions (payment-links Task 2, spec §2.1 / Ruling 5).
 *
 * `initiate()` guarantees ONE provably live hosted session per payable:
 *
 *   no intent          -> claim an attempt (BEFORE provider I/O), create, open it
 *   confirmed live     -> the SAME url, no second session
 *   confirmed dead     -> supersede the old attempt, claim a NEW one, create
 *   unknown state      -> typed fail-closed; the existing intent is never replaced
 *   renewal impossible -> typed fail-closed (Paystack, Ruling 6)
 *
 * A fresh session is NEVER minted unconditionally, and provider liveness probes always run
 * outside database transactions (asserted directly below) so a slow provider can never hold
 * payable-scoped locks.
 */
final class PayviaPaymentCollectorEnsureLiveTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePaymentIntentsTable());
        $this->runMigration(new AddPaymentIntentAttemptLifecycle());
    }

    // ==================================================================
    // create / confirmed-live / confirmed-dead / unknown
    // ==================================================================

    public function testWithNoIntentTheAttemptIsClaimedBeforeProviderIoAndThenOpened(): void
    {
        $gateway = new EnsureLiveGateway();
        $connection = $this->connection;
        $observed = [];
        $gateway->onInitialize = function (string $attemptUuid) use ($connection, &$observed): void {
            // Proof that the claim precedes provider I/O -- and that nothing holds a transaction
            // (or therefore a row lock) while the provider is being called.
            $rows = $connection->table('payment_intents')->select(['*'])->get();
            $observed = ['rows' => $rows, 'level' => $connection->getTransactionManager()->getLevel()];
        };

        [$collector, $intents] = $this->collector($gateway);
        $result = $collector->initiate($this->context, $this->payable('ord-create'));

        self::assertCount(1, $observed['rows']);
        self::assertSame('initializing', $observed['rows'][0]['status']);
        self::assertNull($observed['rows'][0]['reference']);
        self::assertSame(0, $observed['level']);

        $attempt = $gateway->attemptUuids[0];
        self::assertSame($observed['rows'][0]['uuid'], $attempt);

        self::assertSame('ok', $result->status);
        self::assertSame('sess_' . $attempt, $result->payload['reference']);
        self::assertSame('https://checkout.test/' . $attempt, $result->payload['checkout_url']);

        $open = $intents->findOpen($this->context, 'commerce_order', 'ord-create');
        self::assertIsArray($open);
        self::assertSame($attempt, $open['uuid']);
        self::assertSame('sess_' . $attempt, $open['reference']);
        self::assertSame('https://checkout.test/' . $attempt, $open['payload']['checkout_url']);
    }

    public function testAConfirmedLiveSessionIsReusedWithoutMintingASecondOne(): void
    {
        $gateway = new EnsureLiveGateway();
        [$collector, $intents] = $this->collector($gateway);
        $payable = $this->payable('ord-live');

        $first = $collector->initiate($this->context, $payable);
        $gateway->state = HostedSessionStateCapableGateway::STATE_LIVE;
        $second = $collector->initiate($this->context, $payable);

        self::assertSame($first->payload['reference'], $second->payload['reference']);
        self::assertSame($first->payload['checkout_url'], $second->payload['checkout_url']);
        self::assertSame(1, $gateway->initializeCalls, 'a live session must never be replaced');
        self::assertSame(1, $gateway->stateCalls);
        self::assertSame(0, $gateway->abandonCalls);
        self::assertSame([$first->payload['reference']], $gateway->probedReferences);
        self::assertCount(1, $this->rows('ord-live'));
        self::assertNull($intents->findOpen($this->context, 'commerce_order', 'nope'));
    }

    public function testACompletedSessionIsNeverReplacedEither(): void
    {
        $gateway = new EnsureLiveGateway();
        [$collector] = $this->collector($gateway);
        $payable = $this->payable('ord-completed');

        $first = $collector->initiate($this->context, $payable);
        $gateway->state = HostedSessionStateCapableGateway::STATE_COMPLETED;
        $second = $collector->initiate($this->context, $payable);

        self::assertSame($first->payload['reference'], $second->payload['reference']);
        self::assertSame(1, $gateway->initializeCalls);
        self::assertSame(0, $gateway->abandonCalls);
    }

    public function testAProvenDeadSessionSupersedesTheOldAttemptAndClaimsANewOne(): void
    {
        $gateway = new EnsureLiveGateway();
        [$collector, $intents] = $this->collector($gateway);
        $payable = $this->payable('ord-renew');

        $first = $collector->initiate($this->context, $payable);
        $gateway->state = HostedSessionStateCapableGateway::STATE_DEAD;
        $gateway->abandon = HostedSessionRenewalCapableGateway::RENEWAL_CONFIRMED_DEAD;
        $second = $collector->initiate($this->context, $payable);

        self::assertSame(2, $gateway->initializeCalls);
        self::assertSame(1, $gateway->abandonCalls);
        self::assertNotSame($first->payload['reference'], $second->payload['reference']);

        // A later, provider-proven renewal claims a NEW attempt uuid -- and therefore a new
        // provider idempotency key/reference.
        [$firstAttempt, $secondAttempt] = $gateway->attemptUuids;
        self::assertNotSame($firstAttempt, $secondAttempt);

        $rows = $this->rows('ord-renew');
        self::assertCount(2, $rows, 'the old attempt is preserved, never deleted');
        $byUuid = array_column($rows, null, 'uuid');
        self::assertSame('superseded', $byUuid[$firstAttempt]['status']);
        // Re-keyed off the active port so the successor could claim it.
        self::assertStringContainsString($firstAttempt, (string) $byUuid[$firstAttempt]['idempotency_key']);
        self::assertSame('open', $byUuid[$secondAttempt]['status']);
        self::assertSame('commerce_order:ord-renew', $byUuid[$secondAttempt]['idempotency_key']);

        // Its provider reference stays webhook-addressable on the superseded row.
        self::assertSame($first->payload['reference'], $byUuid[$firstAttempt]['reference']);

        $open = $intents->findOpen($this->context, 'commerce_order', 'ord-renew');
        self::assertIsArray($open);
        self::assertSame($secondAttempt, $open['uuid']);
    }

    public function testAnUnknownProviderStateFailsClosedAndNeverReplacesTheIntent(): void
    {
        $gateway = new EnsureLiveGateway();
        [$collector, $intents] = $this->collector($gateway);
        $payable = $this->payable('ord-unknown');

        $first = $collector->initiate($this->context, $payable);
        $gateway->state = HostedSessionStateCapableGateway::STATE_UNKNOWN;

        try {
            $collector->initiate($this->context, $payable);
            self::fail('an unknown provider state must fail closed');
        } catch (ProviderSessionStateUnknownException $e) {
            self::assertSame('commerce_order', $e->payableType);
            self::assertSame('ord-unknown', $e->payableId);
            self::assertSame($first->payload['reference'], $e->reference);
        }

        self::assertSame(1, $gateway->initializeCalls, 'fail-closed never mints a replacement');
        self::assertSame(0, $gateway->abandonCalls, 'an unknown state is never expired away');
        $open = $intents->findOpen($this->context, 'commerce_order', 'ord-unknown');
        self::assertIsArray($open);
        self::assertSame($first->payload['reference'], $open['reference']);
        self::assertCount(1, $this->rows('ord-unknown'));
    }

    public function testAProbeThatThrowsIsAnUnknownStateNotADeadSession(): void
    {
        $gateway = new EnsureLiveGateway();
        [$collector, $intents] = $this->collector($gateway);
        $payable = $this->payable('ord-probe-throws');

        $collector->initiate($this->context, $payable);
        $gateway->throwOnState = true;

        $this->expectException(ProviderSessionStateUnknownException::class);

        try {
            $collector->initiate($this->context, $payable);
        } finally {
            self::assertSame(1, $gateway->initializeCalls);
            self::assertIsArray($intents->findOpen($this->context, 'commerce_order', 'ord-probe-throws'));
        }
    }

    public function testAnAbandonThatCannotProveDeathNeverFreesTheIntent(): void
    {
        $gateway = new EnsureLiveGateway();
        [$collector, $intents] = $this->collector($gateway);
        $payable = $this->payable('ord-unproven');

        $first = $collector->initiate($this->context, $payable);
        $gateway->state = HostedSessionStateCapableGateway::STATE_DEAD;
        $gateway->abandon = HostedSessionRenewalCapableGateway::RENEWAL_UNKNOWN;

        try {
            $collector->initiate($this->context, $payable);
            self::fail('an unprovable death must fail closed');
        } catch (ProviderSessionStateUnknownException) {
        }

        self::assertSame(1, $gateway->initializeCalls);
        $open = $intents->findOpen($this->context, 'commerce_order', 'ord-unproven');
        self::assertIsArray($open);
        self::assertSame($first->payload['reference'], $open['reference']);
    }

    public function testARefetchThatContradictsTheProbeKeepsTheExistingSession(): void
    {
        // The expire -> re-fetch result is the authority: if it says the session is still live,
        // the intent stays exactly as it was and the caller gets the same url back.
        $gateway = new EnsureLiveGateway();
        [$collector] = $this->collector($gateway);
        $payable = $this->payable('ord-contradiction');

        $first = $collector->initiate($this->context, $payable);
        $gateway->state = HostedSessionStateCapableGateway::STATE_DEAD;
        $gateway->abandon = HostedSessionRenewalCapableGateway::RENEWAL_STILL_LIVE;
        $second = $collector->initiate($this->context, $payable);

        self::assertSame($first->payload['reference'], $second->payload['reference']);
        self::assertSame(1, $gateway->initializeCalls);
        self::assertCount(1, $this->rows('ord-contradiction'));
    }

    public function testAGatewayWithoutRenewalFailsClosedOnADeadSession(): void
    {
        // Ruling 6: Paystack cannot prove an old authorization url dead, so 2.6.0 refuses rather
        // than guessing -- a second initialization would risk a double charge.
        $gateway = new LivenessOnlyGateway();
        [$collector, $intents] = $this->collector($gateway, LivenessOnlyGateway::class);
        $payable = $this->payable('ord-no-renewal');

        $first = $collector->initiate($this->context, $payable);
        $gateway->state = HostedSessionStateCapableGateway::STATE_DEAD;

        try {
            $collector->initiate($this->context, $payable);
            self::fail('renewal is unavailable for this gateway');
        } catch (SessionRenewalUnavailableException $e) {
            self::assertSame('fake', $e->gateway);
            self::assertSame('ord-no-renewal', $e->payableId);
        }

        self::assertSame(1, $gateway->initializeCalls);
        $open = $intents->findOpen($this->context, 'commerce_order', 'ord-no-renewal');
        self::assertIsArray($open);
        self::assertSame($first->payload['reference'], $open['reference']);
    }

    public function testALivenessCapableGatewayStillReusesAConfirmedLiveSession(): void
    {
        $gateway = new LivenessOnlyGateway();
        [$collector] = $this->collector($gateway, LivenessOnlyGateway::class);
        $payable = $this->payable('ord-paystack-live');

        $first = $collector->initiate($this->context, $payable);
        $second = $collector->initiate($this->context, $payable);

        self::assertSame($first->payload['reference'], $second->payload['reference']);
        self::assertSame(1, $gateway->initializeCalls);
    }

    // ==================================================================
    // per-attempt idempotency
    // ==================================================================

    public function testATransportTimeoutRetriesTheSameAttemptAndReturnsTheSameSession(): void
    {
        $gateway = new EnsureLiveGateway();
        [$collector, $intents] = $this->collector($gateway);
        $payable = $this->payable('ord-timeout');

        $gateway->throwOnInitialize = true;
        try {
            $collector->initiate($this->context, $payable);
            self::fail('the transport failure must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('transport timeout', $e->getMessage());
        }

        // The claimed attempt survives as `initializing` -- an unknown outcome is NEVER a
        // deterministic failure, so the port stays held for the retry.
        $rows = $this->rows('ord-timeout');
        self::assertCount(1, $rows);
        self::assertSame('initializing', $rows[0]['status']);
        self::assertNull($intents->findOpen($this->context, 'commerce_order', 'ord-timeout'));

        $gateway->throwOnInitialize = false;
        $result = $collector->initiate($this->context, $payable);

        self::assertSame(2, $gateway->initializeCalls);
        self::assertSame(
            $gateway->attemptUuids[0],
            $gateway->attemptUuids[1],
            'the retry must replay the SAME attempt uuid, and therefore the same provider key'
        );
        self::assertSame('sess_' . $gateway->attemptUuids[0], $result->payload['reference']);
        self::assertCount(1, $this->rows('ord-timeout'));
        self::assertSame(0, $gateway->stateCalls, 'an initializing row is resumed, never probed');
    }

    public function testARenewalAfterATimeoutRetryClaimsAFreshAttempt(): void
    {
        $gateway = new EnsureLiveGateway();
        [$collector] = $this->collector($gateway);
        $payable = $this->payable('ord-timeout-renew');

        $gateway->throwOnInitialize = true;
        try {
            $collector->initiate($this->context, $payable);
        } catch (\RuntimeException) {
        }
        $gateway->throwOnInitialize = false;
        $collector->initiate($this->context, $payable);

        $gateway->state = HostedSessionStateCapableGateway::STATE_DEAD;
        $gateway->abandon = HostedSessionRenewalCapableGateway::RENEWAL_CONFIRMED_DEAD;
        $collector->initiate($this->context, $payable);

        self::assertSame($gateway->attemptUuids[0], $gateway->attemptUuids[1]);
        self::assertNotSame($gateway->attemptUuids[1], $gateway->attemptUuids[2]);
    }

    public function testAnUntrustedCheckoutUrlNeverEntersAnIntentPayload(): void
    {
        // The gateway itself refuses the url (its own trust boundary); the collector must leave
        // nothing usable behind -- no open intent, no stored checkout url.
        $gateway = new EnsureLiveGateway();
        $gateway->throwOnInitialize = true;
        $gateway->initializeError = 'Paystack transaction returned no usable checkout URL';
        [$collector, $intents] = $this->collector($gateway);

        try {
            $collector->initiate($this->context, $this->payable('ord-hostile'));
            self::fail('the gateway rejection must propagate');
        } catch (\RuntimeException) {
        }

        self::assertNull($intents->findOpen($this->context, 'commerce_order', 'ord-hostile'));
        $rows = $this->rows('ord-hostile');
        self::assertCount(1, $rows);
        self::assertSame('initializing', $rows[0]['status']);
        self::assertNull($rows[0]['reference']);
        self::assertNull($rows[0]['payload']);
    }

    public function testAnAttemptRetiredMidFlightNeverBecomesAnUnpersistedSuccess(): void
    {
        // The provider session is real, but the row that was supposed to record it is gone (a
        // concurrent actor retired it while the provider call was in flight). Reporting 'ok'
        // would hand out a checkout url no webhook could ever attribute.
        $gateway = new EnsureLiveGateway();
        $connection = $this->connection;
        $gateway->onInitialize = static function (string $attemptUuid) use ($connection): void {
            $connection->table('payment_intents')->where(['uuid' => $attemptUuid])->update([
                'status' => 'failed',
                'idempotency_key' => 'retired:' . $attemptUuid,
            ]);
        };

        [$collector, $intents] = $this->collector($gateway);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unpersisted success');

        try {
            $collector->initiate($this->context, $this->payable('ord-lost-row'));
        } finally {
            self::assertNull($intents->findOpen($this->context, 'commerce_order', 'ord-lost-row'));
        }
    }

    // ==================================================================
    // PostgreSQL-gated: one-open-attempt serialization under concurrent renewal
    // ==================================================================

    /**
     * Two genuinely concurrent renewals of the SAME payable -- a real second process (PHP has no
     * threads), racing against real PostgreSQL row-level semantics. Both find the seeded session
     * confirmed dead and both try to renew; the `(tenant_uuid, idempotency_key)` active port is
     * the backstop that makes exactly one of them the successor attempt. Whichever loses recovers
     * the winner's attempt -- and because the provider session derives from the attempt uuid,
     * both callers converge on the SAME live session rather than two competing checkouts.
     *
     * Skips cleanly when PostgreSQL is unreachable (mirrors CheckoutOriginationLedgerTest).
     */
    public function testConcurrentRenewalConvergesOnExactlyOneOpenAttempt(): void
    {
        $pg = $this->pgsqlConnection();
        $payableId = 'race-' . bin2hex(random_bytes(4));
        $intents = new PaymentIntentRepository($pg);

        $seed = $intents->claimAttempt($this->context, [
            'payable_type' => 'commerce_order',
            'payable_id' => $payableId,
            'gateway' => 'fake',
            'amount' => 4999,
            'currency' => 'GHS',
        ]);
        self::assertTrue($intents->markOpen(
            $this->context,
            (string) $seed['uuid'],
            'sess_seed_' . $payableId,
            ['checkout_url' => 'https://checkout.test/seed'],
        ));

        $gate = sys_get_temp_dir() . '/payvia-ensure-live-gate-' . bin2hex(random_bytes(6));
        $handle = $this->launchRaceChild(['payableId' => $payableId, 'gate' => $gate]);

        $gateway = new EnsureLiveGateway();
        $gateway->state = HostedSessionStateCapableGateway::STATE_DEAD;
        $gateway->abandon = HostedSessionRenewalCapableGateway::RENEWAL_CONFIRMED_DEAD;
        $gateway->onInitialize = static function (): void {
            // Widen the provider-I/O window so both processes are genuinely inside it at once.
            usleep(250_000);
        };
        [$collector] = $this->collector($gateway, EnsureLiveGateway::class, $pg);

        // Give the child time to boot and start polling, then release both at once.
        usleep(400_000);
        touch($gate);

        $mine = $collector->initiate($this->context, $this->payable($payableId));
        $theirs = $this->collectRaceChild($handle);
        @unlink($gate);

        self::assertTrue($theirs['ok'] ?? false, 'the racing renewal failed: ' . json_encode($theirs));
        self::assertSame(
            $mine->payload['reference'],
            $theirs['reference'] ?? null,
            'both renewals must converge on the same provider session'
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $pg->table('payment_intents')
            ->select(['*'])
            ->where(['payable_type' => 'commerce_order', 'payable_id' => $payableId])
            ->get();
        $byStatus = array_count_values(array_map(static fn(array $r): string => (string) $r['status'], $rows));

        self::assertSame(1, $byStatus['open'] ?? 0, 'exactly one OPEN attempt may survive the race');
        self::assertSame(1, $byStatus['superseded'] ?? 0, 'the seeded attempt is superseded exactly once');
        self::assertCount(2, $rows, 'no third attempt may be minted');
        self::assertSame(
            $mine->payload['reference'],
            (string) $intents->findOpen($this->context, 'commerce_order', $payableId)['reference'],
        );
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /**
     * A real, reachable PostgreSQL connection at the Task-1 shape, or a skip. Mirrors
     * PaymentIntentAttemptLifecycleMigrationTest's helper (007 has no hasTable() guard, so the
     * table is always dropped and rebuilt for a deterministic starting shape).
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
            $connection->getPDO()->exec('DROP TABLE IF EXISTS payment_intents');
            (new CreatePaymentIntentsTable())->up($connection->getSchemaBuilder());
            (new AddPaymentIntentAttemptLifecycle())->up($connection->getSchemaBuilder());

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
                dirname(__DIR__) . '/Fixtures/payment-intents/ensure_live_race_child.php',
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

    /** @return array{0: PayviaPaymentCollector, 1: PaymentIntentRepository} */
    private function collector(
        PaymentGatewayInterface $gateway,
        ?string $class = null,
        ?Connection $connection = null
    ): array {
        $class ??= EnsureLiveGateway::class;
        $this->bind($class, $gateway);

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', $class);

        $config = require __DIR__ . '/../../config/payvia.php';
        $config['default_gateway'] = 'fake';
        $config['gateways']['fake'] = ['enabled' => true, 'driver' => 'fake'];
        $this->context->mergeConfigDefaults('payvia', $config);

        $intents = new PaymentIntentRepository($connection ?? $this->connection);

        return [new PayviaPaymentCollector($manager, $intents), $intents];
    }

    private function payable(string $id): PayableReference
    {
        return new PayableReference('commerce_order', $id, 4999, 'GHS', 'Order ' . $id, [
            'callback_url' => 'https://shop.test/return',
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $payableId): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->connection->table('payment_intents')
            ->select(['*'])
            ->where(['payable_type' => 'commerce_order', 'payable_id' => $payableId])
            ->get();

        return $rows;
    }
}

/**
 * A hosted gateway that can both prove liveness and prove death (Stripe-shaped). Its session
 * reference is derived from the attempt uuid it is handed, exactly as the real drivers derive
 * their idempotency key/reference -- so "same attempt uuid" and "same provider session" are the
 * same statement here.
 */
class EnsureLiveGateway implements
    PaymentGatewayInterface,
    InitiationCapableGateway,
    HostedSessionStateCapableGateway,
    HostedSessionRenewalCapableGateway
{
    public int $initializeCalls = 0;
    public int $stateCalls = 0;
    public int $abandonCalls = 0;
    /** @var list<string> */
    public array $attemptUuids = [];
    /** @var list<string> */
    public array $probedReferences = [];
    public string $state = HostedSessionStateCapableGateway::STATE_LIVE;
    public string $abandon = HostedSessionRenewalCapableGateway::RENEWAL_CONFIRMED_DEAD;
    public bool $throwOnInitialize = false;
    public string $initializeError = 'transport timeout';
    public bool $throwOnState = false;
    public ?\Closure $onInitialize = null;

    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    public function initialize(PayableReference $payable, array $options = []): array
    {
        $this->initializeCalls++;
        $attempt = (string) ($options['attempt_uuid'] ?? '');
        if ($attempt === '') {
            throw new \RuntimeException('fake gateway requires an attempt_uuid');
        }
        $this->attemptUuids[] = $attempt;

        if ($this->onInitialize !== null) {
            ($this->onInitialize)($attempt, $options);
        }
        if ($this->throwOnInitialize) {
            throw new \RuntimeException($this->initializeError);
        }

        return [
            'reference' => 'sess_' . $attempt,
            'checkout_url' => 'https://checkout.test/' . $attempt,
        ];
    }

    public function hostedSessionState(string $reference): string
    {
        $this->stateCalls++;
        $this->probedReferences[] = $reference;
        if ($this->throwOnState) {
            throw new \RuntimeException('provider unreachable');
        }

        return $this->state;
    }

    public function abandonHostedSession(string $reference): string
    {
        $this->abandonCalls++;

        return $this->abandon;
    }
}

/**
 * Paystack-shaped: liveness-capable, renewal-INcapable (Ruling 6). Deliberately not a subclass of
 * {@see EnsureLiveGateway} -- the whole point is that it does not implement the renewal contract.
 */
final class LivenessOnlyGateway implements
    PaymentGatewayInterface,
    InitiationCapableGateway,
    HostedSessionStateCapableGateway
{
    public int $initializeCalls = 0;
    public int $stateCalls = 0;
    public string $state = HostedSessionStateCapableGateway::STATE_LIVE;

    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    public function initialize(PayableReference $payable, array $options = []): array
    {
        $this->initializeCalls++;
        $attempt = (string) ($options['attempt_uuid'] ?? '');
        if ($attempt === '') {
            throw new \RuntimeException('fake gateway requires an attempt_uuid');
        }

        return [
            'reference' => 'psref_' . $attempt,
            'checkout_url' => 'https://checkout.test/' . $attempt,
        ];
    }

    public function hostedSessionState(string $reference): string
    {
        $this->stateCalls++;

        return $this->state;
    }
}
