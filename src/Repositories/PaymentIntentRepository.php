<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Repositories\Concerns\DetectsUniqueViolations;
use Glueful\Extensions\Payvia\Repositories\Concerns\NormalizesAmountColumn;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

final class PaymentIntentRepository extends BaseRepository
{
    use DetectsUniqueViolations;
    use NormalizesAmountColumn;

    public const STATUS_INITIALIZING = 'initializing';
    public const STATUS_OPEN = 'open';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_FAILED = 'failed';

    /**
     * Service-enforced closed status set (payment-links Task 1). No DB enum -- matches every
     * other status column in this schema. Enforcement is real, not just documentation:
     * {@see retire()} rejects any `$toStatus` outside this set before writing anything.
     * `initializing` and `open` are the two statuses that hold the payable's active idempotency
     * port (see {@see activePortKey()}); the other three are terminal/superseded outcomes,
     * re-keyed by attempt uuid to free that port.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_INITIALIZING,
        self::STATUS_OPEN,
        self::STATUS_SUPERSEDED,
        self::STATUS_CLOSED,
        self::STATUS_FAILED,
    ];

    /**
     * Payload key holding the unix timestamp of the last PROVIDER-CONFIRMED liveness observation
     * for an open attempt. See {@see recordLivenessConfirmation()}.
     */
    public const LIVENESS_CONFIRMED_AT = 'liveness_confirmed_at';

    /** @var list<string> */
    private const ACTIVE_STATUSES = [self::STATUS_INITIALIZING, self::STATUS_OPEN];

    private readonly PayviaTenantResolver $resolver;

    public function __construct(
        ?Connection $connection = null,
        ?ApplicationContext $context = null,
        ?PayviaTenantResolver $resolver = null,
    ) {
        parent::__construct($connection, $context);
        $this->resolver = $resolver ?? new SentinelTenantResolver();
    }

    public function getTableName(): string
    {
        return 'payment_intents';
    }

    public function findOpen(ApplicationContext $context, string $payableType, string $payableId): ?array
    {
        if ($payableType === '' || $payableId === '') {
            return null;
        }

        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where([
                'tenant_uuid' => $this->resolver->tenantUuid($context),
                'payable_type' => $payableType,
                'payable_id' => $payableId,
                'status' => self::STATUS_OPEN,
            ])
            ->limit(1)
            ->get();

        $row = $rows[0] ?? null;
        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * The active-port lookup: a row that either holds the port pending provider I/O
     * (`initializing`) or already succeeded (`open`). Unlike {@see findOpen()} (kept exactly as
     * every current caller expects it), this is what {@see claimAttempt()} recovers through when
     * an insert loses the `(tenant_uuid, idempotency_key)` race against an in-flight attempt.
     */
    public function findActive(ApplicationContext $context, string $payableType, string $payableId): ?array
    {
        if ($payableType === '' || $payableId === '') {
            return null;
        }

        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where([
                'tenant_uuid' => $this->resolver->tenantUuid($context),
                'payable_type' => $payableType,
                'payable_id' => $payableId,
            ])
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->limit(1)
            ->get();

        $row = $rows[0] ?? null;
        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * Reference-addressable lookup (payment-links Task 3): the composite
     * `UNIQUE(tenant_uuid, gateway, reference)` added by 012 makes this exact and
     * unambiguous -- unlike {@see findOpen()}/{@see findActive()} (both scoped by payable
     * and status), this finds the ONE row a webhook's own reference belongs to, whatever
     * its current status. A superseded/closed/failed attempt carries its OWN reference,
     * distinct from the payable's current open attempt's -- a webhook confirming an OLD
     * reference must resolve to THAT row, never "whichever attempt is open" for the
     * payable.
     *
     * @return array<string,mixed>|null
     */
    public function findByReference(ApplicationContext $context, string $gateway, string $reference): ?array
    {
        if ($gateway === '' || $reference === '') {
            return null;
        }

        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where([
                'tenant_uuid' => $this->resolver->tenantUuid($context),
                'gateway' => $gateway,
                'reference' => $reference,
            ])
            ->limit(1)
            ->get();

        $row = $rows[0] ?? null;
        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }

        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where(['uuid' => $uuid, 'tenant_uuid' => $this->resolver->tenantUuid($context)])
            ->limit(1)
            ->get();

        $row = $rows[0] ?? null;
        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @throws DuplicateReferenceException when the insert collides with
     *         `UNIQUE(tenant_uuid, gateway, reference)` against a DIFFERENT, already-retired
     *         attempt -- e.g. a gateway with a fixed, time-boxed idempotency key (Stripe replays
     *         the same checkout session id for ~24h) handing back an identical reference for a
     *         retried attempt on a payable whose earlier attempt already closed under it. This is
     *         NOT the same as the active port being taken (that case still returns `false`, as
     *         always -- recover via {@see findOpen()}): there, a LIVE row for this exact payable
     *         exists to recover from. Here, the colliding row belongs to a different, terminal
     *         attempt -- there is nothing live to hand back, so silently returning `false` would
     *         let the caller believe this was an ordinary race and go on to fabricate an
     *         unpersisted "success" from the gateway result it already has in hand.
     */
    public function createOpen(ApplicationContext $context, array $row): bool
    {
        $payableType = (string) ($row['payable_type'] ?? '');
        $payableId = (string) ($row['payable_id'] ?? '');
        if ($payableType === '' || $payableId === '') {
            throw new \InvalidArgumentException('Payment intents require payable_type and payable_id.');
        }

        $gateway = (string) ($row['gateway'] ?? '');
        $reference = $row['reference'] ?? null;

        $payload = array_merge($row, [
            'uuid' => (string) ($row['uuid'] ?? Utils::generateNanoID()),
            'tenant_uuid' => $this->resolver->tenantUuid($context),
            'idempotency_key' => $this->activePortKey($payableType, $payableId),
            'status' => self::STATUS_OPEN,
            'payload' => $this->encodePayload($row['payload'] ?? null),
            'created_at' => $this->db->getDriver()->formatDateTime(),
        ]);

        try {
            $this->db->table($this->getTableName())->insert($payload);
            return true;
        } catch (\Throwable $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            if ($this->findActive($context, $payableType, $payableId) !== null) {
                // The active port (idempotency_key) is taken by a LIVE row (initializing or
                // open) for THIS payable -- the ordinary, recoverable race. Unchanged from
                // before this migration.
                return false;
            }

            // No live row claims this payable's port, yet the insert still collided: it can
            // only be the new UNIQUE(tenant_uuid, gateway, reference), against a different,
            // already-retired attempt. Nothing to recover -- surface it.
            throw new DuplicateReferenceException(
                $payableType,
                $payableId,
                $gateway,
                is_string($reference) ? $reference : null
            );
        }
    }

    /**
     * Claim a session attempt BEFORE any provider I/O runs (payment-links Task 1): inserts an
     * `initializing` row holding the payable's active idempotency port
     * ({@see activePortKey()}), with `reference` still null and the row's own `uuid` standing in
     * as the attempt identity -- no second "attempt id" column exists.
     *
     * A transport timeout or crash between this claim and the eventual {@see markOpen()}/
     * {@see fail()} leaves the row `initializing`; retrying this same call for the same payable
     * loses the `(tenant_uuid, idempotency_key)` race and recovers the EXISTING row (same attempt
     * uuid, same idempotency key) instead of minting a new one -- exactly the same
     * insert-or-recover shape as {@see createOpen()} and `CheckoutOriginationRepository::
     * claimPreparing()`. If recovery finds nothing (the winning row was already retired between
     * the failed insert and the recovery read), the original exception propagates.
     *
     * IMPORTANT: the recovered row is fetched via {@see findActive()}, so it may come back with
     * `status === self::STATUS_OPEN` rather than `self::STATUS_INITIALIZING` -- e.g. a caller
     * that re-enters after the FIRST attempt already completed its provider round trip and called
     * {@see markOpen()}. Callers MUST branch on the returned row's `status` rather than assume
     * `initializing`: an `open` row already has a real `reference` and needs no further provider
     * I/O at all, while an `initializing` one still does.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function claimAttempt(ApplicationContext $context, array $row): array
    {
        $payableType = (string) ($row['payable_type'] ?? '');
        $payableId = (string) ($row['payable_id'] ?? '');
        $gateway = (string) ($row['gateway'] ?? '');
        if ($payableType === '' || $payableId === '' || $gateway === '') {
            throw new \InvalidArgumentException(
                'Payment intent attempts require payable_type, payable_id, and gateway.'
            );
        }

        $tenant = $this->resolver->tenantUuid($context);
        $uuid = (string) ($row['uuid'] ?? Utils::generateNanoID());

        $payload = array_merge($row, [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'gateway' => $gateway,
            'idempotency_key' => $this->activePortKey($payableType, $payableId),
            'reference' => null,
            'status' => self::STATUS_INITIALIZING,
            'payload' => $this->encodePayload($row['payload'] ?? null),
            'created_at' => $this->db->getDriver()->formatDateTime(),
        ]);

        try {
            $this->db->table($this->getTableName())->insert($payload);

            $inserted = $this->findByUuid($context, $uuid);
            if ($inserted === null) {
                throw new \RuntimeException('Payment intent attempt insert did not persist.');
            }

            return $inserted;
        } catch (\Throwable $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = $this->findActive($context, $payableType, $payableId);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * Advance a claimed attempt from `initializing` to `open` once the provider has handed back
     * a `reference` -- the ONLY point at which `reference` is ever populated. The active port
     * key does not change: `initializing` and `open` share it (see {@see activePortKey()}), so
     * the payable stays exclusively claimed straight through to a terminal transition.
     *
     * A CAS: matches only a row that is still `initializing`. Returns `false` (no-op) if the
     * uuid is unknown, belongs to another tenant, or has already left `initializing` --
     * indistinguishable to the caller by design, mirroring {@see close()}'s non-revealing shape.
     *
     * `$payload === null` SKIPS the `payload` column entirely rather than overwriting it with
     * NULL: {@see claimAttempt()} may already have written a claim-time payload, and a caller
     * that omits `$payload` here (e.g. a bare reference confirmation with no fresh provider data)
     * must not destroy it -- a later {@see findOpen()} reader depends on it (e.g. `checkout_url`).
     *
     * @param array<string,mixed>|null $payload
     * @throws DuplicateReferenceException when `$reference` collides with
     *         `UNIQUE(tenant_uuid, gateway, reference)` against a DIFFERENT, already-retired
     *         attempt -- see {@see createOpen()}'s docblock for the same reachable scenario. Only
     *         this constraint is reachable from this method's UPDATE (it never touches
     *         `idempotency_key`), so any unique violation caught here is unambiguously this case.
     */
    public function markOpen(ApplicationContext $context, string $uuid, string $reference, ?array $payload = null): bool
    {
        if ($uuid === '' || $reference === '') {
            return false;
        }

        $tenant = $this->resolver->tenantUuid($context);
        $row = $this->findByUuid($context, $uuid);
        if ($row === null || (string) $row['status'] !== self::STATUS_INITIALIZING) {
            return false;
        }

        $fields = [
            'status' => self::STATUS_OPEN,
            'reference' => $reference,
            'updated_at' => $this->db->getDriver()->formatDateTime(),
        ];
        if ($payload !== null) {
            $fields['payload'] = $this->encodePayload($payload);
        }

        try {
            $affected = $this->db->table($this->getTableName())
                ->where(['uuid' => $uuid, 'tenant_uuid' => $tenant, 'status' => self::STATUS_INITIALIZING])
                ->update($fields);
        } catch (\Throwable $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            throw new DuplicateReferenceException(
                (string) $row['payable_type'],
                (string) $row['payable_id'],
                (string) $row['gateway'],
                $reference
            );
        }

        return $affected > 0;
    }

    /**
     * Stamp a provider-CONFIRMED liveness observation onto an open attempt (payment-links Task 2,
     * fix round 1). The collector reads it to skip re-probing the provider on every repeat
     * `initiate()` inside a short cooldown window -- otherwise a shopper hammering "pay" (or an
     * abusive client) turns one hosted checkout into an unbounded stream of provider round trips,
     * and a provider rate-limit answer becomes a fail-closed unknown for everyone.
     *
     * Deliberately narrow:
     *  - legal only from `open` (a claimed-but-unopened attempt has nothing to confirm);
     *  - written only for a CONFIRMED-LIVE probe, so a dead/unknown answer can never buy itself
     *    a quiet period;
     *  - a read-modify-write of the JSON payload, which is acceptable because the value is a
     *    cache hint: two racing writers can at worst lose one timestamp, costing one extra probe.
     *
     * @param int $observedAt unix seconds
     */
    public function recordLivenessConfirmation(ApplicationContext $context, string $uuid, int $observedAt): bool
    {
        if ($uuid === '') {
            return false;
        }

        $tenant = $this->resolver->tenantUuid($context);
        $row = $this->findByUuid($context, $uuid);
        if ($row === null || (string) $row['status'] !== self::STATUS_OPEN) {
            return false;
        }

        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $payload[self::LIVENESS_CONFIRMED_AT] = $observedAt;

        $affected = $this->db->table($this->getTableName())
            ->where(['uuid' => $uuid, 'tenant_uuid' => $tenant, 'status' => self::STATUS_OPEN])
            ->update([
                'payload' => $this->encodePayload($payload),
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]);

        return $affected > 0;
    }

    /**
     * Abandon an attempt in favor of a successor (e.g. the caller decided to retry with a fresh
     * attempt rather than recover this one). Legal from either active status -- re-keys and
     * frees the port exactly like {@see close()}/{@see fail()}.
     */
    public function supersede(ApplicationContext $context, string $uuid): bool
    {
        return $this->retire($context, $uuid, self::ACTIVE_STATUSES, self::STATUS_SUPERSEDED);
    }

    /**
     * Close a successfully collected `open` intent. Kept at its original (context, uuid,
     * reference) shape for every existing caller -- {@see \Glueful\Extensions\Payvia\Services
     * \ConfirmationDispatcher} included -- but `$reference` is no longer needed to compute the
     * re-keyed idempotency_key (see {@see retire()}): the row's own attempt uuid is used instead,
     * which -- unlike a reference -- is always present even for a row that never reached `open`.
     *
     * @param string $reference Vestigial, kept only for call-site compatibility -- never read.
     * @deprecated $reference is unused; safe to drop once every caller (Task 2's collector
     *             rewrite) stops passing it. The method itself is NOT deprecated.
     */
    public function close(ApplicationContext $context, string $uuid, string $reference = ''): void
    {
        unset($reference);
        $this->retire($context, $uuid, [self::STATUS_OPEN], self::STATUS_CLOSED);
    }

    /**
     * Settle a reference-addressed intent row on webhook confirmation (payment-links
     * Task 3): CASes to `closed` from `open` (the ordinary case) OR from an already-retired
     * `superseded`/`failed` row -- a late confirmation arriving under an OLD attempt's own
     * reference. `closed` is deliberately excluded from `$fromStatuses`: a re-delivered
     * webhook for an already-settled row is a harmless no-op, not a transition to repeat.
     *
     * Never resurrects a retired row back to `open` -- the honest write is a transition to
     * `closed` FROM whatever status the row was actually in, recording that a confirmation
     * was received without ever making a superseded/failed attempt look live again.
     */
    public function settle(ApplicationContext $context, string $uuid): bool
    {
        return $this->retire(
            $context,
            $uuid,
            [self::STATUS_OPEN, self::STATUS_SUPERSEDED, self::STATUS_FAILED],
            self::STATUS_CLOSED
        );
    }

    /**
     * Mark an attempt `failed`, freeing its active port for a fresh {@see claimAttempt()}.
     * Reserved for a CLASSIFIED deterministic rejection -- a transport timeout or otherwise
     * unknown provider outcome must leave the row `initializing` for same-attempt retry instead
     * of ever calling this. Legal only from `initializing`: a row that already reached `open`
     * collected successfully and is closed, not failed.
     */
    public function fail(ApplicationContext $context, string $uuid): bool
    {
        return $this->retire($context, $uuid, [self::STATUS_INITIALIZING], self::STATUS_FAILED);
    }

    /**
     * The stale-orphan batch (OUTSTANDING: orphan-intent expiry/sweeper): rows still holding a
     * payable's ACTIVE idempotency port ({@see activePortKey()}) whose last touch --
     * `COALESCE(updated_at, created_at)`, because a claimed-but-never-opened attempt has no
     * `updated_at` at all -- predates `$cutoff`. Ordered by `id` ASC (the table's stable,
     * monotonic insertion order) and capped at `$limit`, so a sweep is a bounded batch and
     * repeated runs make forward progress: every row this returns is about to leave the active
     * statuses, so the NEXT call's own WHERE clause excludes it -- no cursor or offset is needed,
     * and a row a concurrent sweeper retired first simply stops matching.
     *
     * Read-only and tenant-scoped like every other method here. The decision to retire is the
     * caller's, made one row at a time through {@see expireStale()}'s CAS.
     *
     * @return list<array<string,mixed>>
     */
    public function findStale(ApplicationContext $context, \DateTimeInterface $cutoff, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where(['tenant_uuid' => $this->resolver->tenantUuid($context)])
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereRaw(
                'COALESCE(updated_at, created_at) < ?',
                [$this->db->getDriver()->formatDateTime($cutoff->format('Y-m-d H:i:s'))]
            )
            ->orderBy(['id' => 'ASC'])
            ->limit($limit)
            ->get();

        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * Retire an orphaned attempt the sweeper found (OUTSTANDING: orphan-intent expiry/sweeper):
     * `initializing`/`open` -> `failed`, through the SAME {@see retire()} CAS every other terminal
     * transition uses, which re-keys `idempotency_key` and therefore FREES the payable's active
     * port. Unlike {@see fail()} this is legal from `open` too: an abandoned hosted session is
     * exactly an `open` row nobody ever came back to.
     *
     * The CAS is what makes an overlapping sweep safe: two sweepers that both selected the same
     * row race on `status`, exactly one UPDATE matches, and the loser returns `false` without
     * re-keying the row a second time or stamping a misleading `updated_at`.
     *
     * AGE IS THE ONLY CRITERION the caller applies, and that is deliberate: nothing in this table
     * can honestly answer whether a payer is still coming back. Retiring is safe anyway BECAUSE it
     * frees the port -- a swept payer who returns later converges through
     * {@see \Glueful\Extensions\Payvia\Services\PayviaPaymentCollector::initiate()}'s create path
     * (no active row is found, so a fresh attempt is claimed and a new provider session created).
     * The swept row keeps its own `reference`, so a late webhook for the abandoned session still
     * resolves to it via {@see findByReference()} and settles through {@see settle()}.
     */
    public function expireStale(ApplicationContext $context, string $uuid): bool
    {
        return $this->retire($context, $uuid, self::ACTIVE_STATUSES, self::STATUS_FAILED);
    }

    /**
     * Shared terminal/superseded transition: reads the row (tenant-scoped), refuses if it is
     * missing or not currently in one of `$fromStatuses`, otherwise CASes to `$toStatus` and
     * re-keys `idempotency_key` to {@see retiredKey()} -- freeing the active port the instant the
     * row leaves `initializing`/`open`. `$toStatus` is validated against {@see STATUSES} --
     * the service-enforced closed set is a real runtime guard here, not just documentation.
     *
     * @param list<string> $fromStatuses
     */
    private function retire(ApplicationContext $context, string $uuid, array $fromStatuses, string $toStatus): bool
    {
        if ($uuid === '' || !in_array($toStatus, self::STATUSES, true)) {
            return false;
        }

        $tenant = $this->resolver->tenantUuid($context);

        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where(['uuid' => $uuid, 'tenant_uuid' => $tenant])
            ->limit(1)
            ->get();
        $row = $rows[0] ?? null;
        if (!is_array($row) || !in_array((string) $row['status'], $fromStatuses, true)) {
            // Non-revealing: the row may not exist, may belong to another tenant, or may
            // already be past the status this transition requires -- none of these are the
            // caller's business to distinguish (mirrors the original close()'s shape).
            return false;
        }

        $affected = $this->db->table($this->getTableName())
            ->where(['uuid' => $uuid, 'tenant_uuid' => $tenant, 'status' => (string) $row['status']])
            ->update([
                'status' => $toStatus,
                'idempotency_key' => $this->retiredKey(
                    (string) $row['payable_type'],
                    (string) $row['payable_id'],
                    $uuid
                ),
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]);

        return $affected > 0;
    }

    /**
     * The active-port key: `initializing` and `open` rows for the same payable share this value,
     * so `UNIQUE(tenant_uuid, idempotency_key)` permits at most one attempt in flight (or open)
     * per payable at a time. Unchanged from 007.
     */
    private function activePortKey(string $payableType, string $payableId): string
    {
        return $payableType . ':' . $payableId;
    }

    /**
     * The retired key: keyed by the attempt's OWN uuid (globally unique) rather than its
     * reference, because a `failed` attempt may never have obtained one. Frees the active port
     * for a successor {@see claimAttempt()} the instant a row is re-keyed to this.
     */
    private function retiredKey(string $payableType, string $payableId, string $uuid): string
    {
        return $payableType . ':' . $payableId . ':' . $uuid;
    }

    private function encodePayload(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        return (string) json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $row */
    private function normalizeRow(array $row): array
    {
        $payload = $row['payload'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $row['payload'] = is_array($decoded) ? $decoded : null;
        }

        return $this->normalizeAmountColumn($row);
    }
}
