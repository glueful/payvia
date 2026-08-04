<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Contracts\SubscriptionInitiationCapableGateway;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Helpers\Utils;

/**
 * Workspace self-serve checkout (design spec §3.2): the atomic prepare/initializeClaim
 * orchestration over Task 4's origination ledger ({@see CheckoutOriginationRepository}) and
 * subject guard ({@see CheckoutSubjectGuardRepository}).
 *
 * `prepare()` owns exactly ONE transaction. It validates the request BEFORE any write, claims
 * (or replays) the origination row, claims the subject guard ONLY for a genuinely new claim,
 * runs the caller's local reservation continuation, advances `preparing -> initializing`, and
 * commits -- or rolls EVERYTHING back together the instant anything after the claim throws. A
 * `preparing` row that somehow persists outside this owning transaction is an invariant
 * violation: this service never commits a row at that status.
 *
 * `initializeClaim()` is a separate, later call: it acquires Task 4's narrow execution lease,
 * calls the provider driver OUTSIDE of any database transaction (provider I/O must never hold a
 * DB transaction open), and persists the outcome via a single atomic CAS write. A concurrent
 * loser never touches the provider at all.
 */
final class SubscriptionCheckoutService
{
    /**
     * How long an `initializeClaim()` owner may hold the execution lease before another caller
     * may reclaim it as stale (mirrors provider_events' dispatch-claim lease default order of
     * magnitude). Clock-injectable via the constructor's `$clock` for deterministic tests.
     */
    public const INITIALIZATION_LEASE_SECONDS = 120;

    private \Closure $clock;

    /**
     * Deliberately takes NO `Connection` of its own. `prepare()`'s one-transaction guarantee is
     * only real if the transaction it begins/commits/rolls back is the SAME connection the
     * repositories actually write through -- so this derives its transaction manager from
     * `$originations`'s own connection ({@see connection()}) rather than accepting a separately
     * injected one that could silently drift from what the repositories resolve (pooling, an
     * unseeded `BaseRepository` static-cache fallback, or simple construction-order differences
     * would all make a separately injected connection a no-op rollback in production). The
     * constructor asserts the two repositories agree on which connection that is, failing fast
     * instead of silently misbehaving if a future wiring change ever breaks that invariant.
     */
    public function __construct(
        private readonly CheckoutOriginationRepository $originations,
        private readonly CheckoutSubjectGuardRepository $guards,
        private readonly GatewayManager $gateways,
        private readonly PayviaTenantResolver $resolver,
        ?\Closure $clock = null,
    ) {
        if ($this->originations->getConnection() !== $this->guards->getConnection()) {
            throw new \LogicException(
                'SubscriptionCheckoutService requires the origination repository and the subject '
                    . 'guard repository to share the SAME Connection instance -- prepare()\'s '
                    . 'single owning transaction cannot span two different connections.'
            );
        }

        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * The one connection `prepare()`'s transaction and `claimPreparingRow()`'s savepoint both
     * operate on -- always the origination repository's own connection (the constructor already
     * asserts the guard repository shares it).
     */
    private function connection(): Connection
    {
        return $this->originations->getConnection();
    }

    /**
     * @param callable(SubscriptionCheckoutClaim): void $bindLocalReservation
     */
    public function prepare(
        ApplicationContext $context,
        SubscriptionCheckoutRequest $request,
        callable $bindLocalReservation,
    ): SubscriptionCheckoutClaim {
        // Validate BEFORE any write: an unsupported gateway or an unusable plan identifier can
        // never succeed regardless of any concurrent state, so nothing is written for it.
        if (!$this->gateways->supports($request->gateway, 'subscription_checkout')) {
            throw CheckoutUnavailableException::gatewayDoesNotSupportSubscriptionCheckout($request->gateway);
        }
        if (trim($request->providerPlanIdentifier) === '') {
            throw CheckoutUnavailableException::missingProviderPlanIdentifier();
        }

        $fingerprint = $this->fingerprint($request);
        $tenantUuid = $this->resolver->tenantUuid($context);
        $tx = $this->connection()->getTransactionManager();

        $tx->begin();
        try {
            $row = $this->claimPreparingRow($context, $request, $fingerprint);

            if ($row['status'] !== 'preparing') {
                // A pre-existing origination for this idempotency key -- BEFORE touching the
                // guard and WITHOUT invoking the continuation. A matching fingerprint replays
                // the stored claim (terminal or not); a mismatch is a genuine conflict.
                if ((string) $row['request_fingerprint'] !== $fingerprint) {
                    throw IdempotencyConflictException::fingerprintMismatch($request->idempotencyKey);
                }

                $claim = $this->claimFromRow($row, replayed: true);
                $tx->commit();

                return $claim;
            }

            // Genuinely new claim: only now may the subject's live guard be touched.
            if (!$this->guards->lockAndClaim($context, $tenantUuid, $request->subjectKey, (string) $row['uuid'])) {
                throw OriginationLiveException::subjectAlreadyLive($request->subjectKey);
            }

            $localClaim = $this->claimFromRow($row, replayed: false);
            $bindLocalReservation($localClaim);

            $uuid = (string) $row['uuid'];
            if (!$this->originations->transition($context, $uuid, 'preparing', 'initializing')) {
                throw new \RuntimeException(
                    "Payvia: failed to advance checkout origination {$uuid} from preparing to initializing."
                );
            }

            $tx->commit();

            return new SubscriptionCheckoutClaim(
                originationUuid: $uuid,
                status: 'initializing',
                checkoutUrl: null,
                replayed: false,
            );
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    public function initializeClaim(ApplicationContext $context, string $originationUuid): SubscriptionCheckoutResult
    {
        $row = $this->originations->findByUuid($originationUuid);
        if ($row === null) {
            throw CheckoutUnavailableException::unknownOrigination($originationUuid);
        }

        if ((string) $row['status'] !== 'initializing') {
            // Already resolved (by this call's own earlier attempt, or another) -- zero
            // provider I/O, just report what is already on file.
            return $this->resultFromRow($row);
        }

        $token = Utils::generateNanoID(12);
        $staleBefore = ($this->clock)()->modify('-' . self::INITIALIZATION_LEASE_SECONDS . ' seconds');

        $claimed = $this->originations->claimInitialization($context, $originationUuid, $token, $staleBefore);
        if ($claimed === null) {
            // Concurrent loser: another attempt currently holds (or just released, by
            // completing) the lease. Zero provider I/O either way.
            $current = $this->originations->findByUuid($originationUuid);
            if ($current !== null && (string) $current['status'] !== 'initializing') {
                return $this->resultFromRow($current);
            }

            return new SubscriptionCheckoutResult($originationUuid, null, 'initializing');
        }

        try {
            $request = $this->requestFromRow($claimed);
            $gateway = $this->subscriptionCheckoutGateway((string) $claimed['gateway']);
            $outcome = $gateway->initializeSubscription($request);
        } catch (DefinitiveSubscriptionCheckoutRejection $e) {
            // The ONLY known-definitive outcome: fail, clear email/lease, and release the
            // matching guard so the subject may originate another attempt.
            $this->originations->completeInitialization($context, $originationUuid, $token, 'failed');
            $this->guards->release(
                $context,
                (string) $claimed['tenant_uuid'],
                (string) $claimed['subject_key'],
                $originationUuid,
            );

            return new SubscriptionCheckoutResult($originationUuid, null, 'failed');
        } catch (\Throwable $e) {
            // Every other failure is UNKNOWN: release only the execution lease. Status, email,
            // idempotency key, and the guard all stay exactly as they were so a replay can call
            // the provider again with the SAME provider idempotency key.
            $this->originations->releaseInitialization($context, $originationUuid, $token);
            throw $e;
        }

        $completed = $this->originations->completeInitialization($context, $originationUuid, $token, 'pending', [
            'checkout_reference' => $outcome['reference'],
            'checkout_url' => $outcome['checkout_url'],
            'provider_expires_at' => $outcome['expires_at'],
            // 'pending' is not a TERMINAL status, so the repository's own derived-fields logic
            // never clears customer_email for it -- but initialization recovery data has no
            // further use once the provider call has actually succeeded, so this service clears
            // it explicitly here.
            'customer_email' => null,
        ]);

        if (!$completed) {
            // The lease was fenced out from under a successful call (e.g. a stale-takeover
            // raced us to completion first) -- report whatever is now actually on file rather
            // than a result this call could not durably persist.
            $current = $this->originations->findByUuid($originationUuid);

            return $current !== null
                ? $this->resultFromRow($current)
                : new SubscriptionCheckoutResult($originationUuid, (string) $outcome['checkout_url'], 'pending');
        }

        return new SubscriptionCheckoutResult($originationUuid, (string) $outcome['checkout_url'], 'pending');
    }

    /**
     * Claims the origination row in a savepoint/re-read unique-race loop: on PostgreSQL, a
     * failed INSERT poisons the ambient transaction until a rollback (full or to a savepoint)
     * runs, so {@see CheckoutOriginationRepository::claimPreparing()}'s OWN unique-violation
     * recovery (a plain re-read) can only complete successfully when it runs outside of that
     * poisoned state. Wrapping the call in our own savepoint here lets us roll back to a clean
     * state and re-read the real winner ourselves whenever that happens; on SQLite (no
     * poisoning) the repository's own recovery already succeeds and this is a harmless no-op
     * savepoint around it.
     *
     * @return array<string,mixed>
     */
    private function claimPreparingRow(
        ApplicationContext $context,
        SubscriptionCheckoutRequest $request,
        string $fingerprint,
    ): array {
        $uuid = $request->originationUuid !== '' ? $request->originationUuid : Utils::generateNanoID(12);

        $row = [
            'uuid' => $uuid,
            'subject_key' => $request->subjectKey,
            'gateway' => $request->gateway,
            'provider_plan_identifier' => $request->providerPlanIdentifier,
            'idempotency_key' => $request->idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'customer_email' => $request->customerEmail,
            'return_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
            'required_projection_consumer' => $request->requiredProjectionConsumer,
            'consumer_metadata' => $request->consumerMetadata,
        ];

        $tx = $this->connection()->getTransactionManager();
        $tx->begin();
        try {
            $inserted = $this->originations->claimPreparing($context, $row);
            $tx->commit();

            return $inserted;
        } catch (\Throwable $e) {
            $tx->rollback();

            $existing = $this->originations->findByIdempotencyKey($context, $request->idempotencyKey);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * SHA-256 over the canonical JSON of the request's identity-defining fields (design spec
     * §3.2). `consumerMetadata` is key-sorted first so two logically identical payloads built
     * with keys in a different order still fingerprint identically.
     */
    private function fingerprint(SubscriptionCheckoutRequest $request): string
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

    /** @param array<string,mixed> $row */
    private function claimFromRow(array $row, bool $replayed): SubscriptionCheckoutClaim
    {
        return new SubscriptionCheckoutClaim(
            originationUuid: (string) $row['uuid'],
            status: (string) $row['status'],
            checkoutUrl: isset($row['checkout_url']) ? (string) $row['checkout_url'] : null,
            replayed: $replayed,
        );
    }

    /** @param array<string,mixed> $row */
    private function resultFromRow(array $row): SubscriptionCheckoutResult
    {
        return new SubscriptionCheckoutResult(
            originationUuid: (string) $row['uuid'],
            checkoutUrl: isset($row['checkout_url']) ? (string) $row['checkout_url'] : null,
            status: (string) $row['status'],
        );
    }

    /** @param array<string,mixed> $row */
    private function requestFromRow(array $row): SubscriptionCheckoutRequest
    {
        $metadata = $row['consumer_metadata'] ?? [];
        $requiredProjectionConsumer = $row['required_projection_consumer'] ?? null;

        return new SubscriptionCheckoutRequest(
            originationUuid: (string) $row['uuid'],
            tenantUuid: (string) $row['tenant_uuid'],
            subjectKey: (string) $row['subject_key'],
            gateway: (string) $row['gateway'],
            providerPlanIdentifier: (string) $row['provider_plan_identifier'],
            consumerMetadata: is_array($metadata) ? $metadata : [],
            customerEmail: (string) ($row['customer_email'] ?? ''),
            returnUrl: (string) $row['return_url'],
            cancelUrl: (string) $row['cancel_url'],
            idempotencyKey: (string) $row['idempotency_key'],
            requiredProjectionConsumer: $requiredProjectionConsumer !== null
                ? (string) $requiredProjectionConsumer
                : null,
        );
    }

    private function subscriptionCheckoutGateway(string $gateway): SubscriptionInitiationCapableGateway
    {
        $driver = $this->gateways->gateway($gateway);
        if (!$driver instanceof SubscriptionInitiationCapableGateway) {
            throw CheckoutUnavailableException::gatewayDoesNotSupportSubscriptionCheckout($gateway);
        }

        return $driver;
    }
}
