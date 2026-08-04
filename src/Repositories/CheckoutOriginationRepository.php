<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Checkout\ProjectionAcknowledgementRefused;
use Glueful\Extensions\Payvia\Contracts\SubscriptionProjectionAcknowledger;
use Glueful\Extensions\Payvia\Repositories\Concerns\DetectsUniqueViolations;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

/**
 * The origination ledger (design spec §3.3): the permanent correlation identity for a hosted
 * subscription checkout attempt. `status` walks a monotonic, idempotent state machine -- see
 * {@see self::TRANSITIONS} -- with exactly two sanctioned exceptions, both reachable from ANY
 * terminal status: regressing to `provider_observed` when a late webhook proves money actually
 * moved, or advancing to `late_settlement_conflict` when a newer reservation already owns the
 * subject. Every other out-of-map jump is refused WITHOUT writing anything.
 *
 * `initialization_claim_token`/`initialization_claimed_at` are a SEPARATE, narrower lease: while
 * `status` stays `initializing`, they fence concurrent/stale attempts to perform the actual
 * provider I/O so at most one is ever in flight -- mirroring provider_events' dispatch-claim
 * lease exactly, but scoped to this row's own `status` rather than a shared dispatch_status
 * column. The lease token is provider-I/O mutual exclusion, NOT ownership: it never appears in
 * `TRANSITIONS` and never changes `status` itself except via {@see completeInitialization()}.
 */
final class CheckoutOriginationRepository extends BaseRepository implements SubscriptionProjectionAcknowledger
{
    use DetectsUniqueViolations;

    private const TABLE = 'subscription_checkout_originations';

    /**
     * The legal status transition map. `$from === $to` is always vacuously legal (resolved by
     * {@see transition()}'s idempotent no-op check against the row's CURRENT status, not this
     * map), so terminal statuses need no self-loop entries here.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'preparing' => ['initializing'],
        'initializing' => ['pending', 'failed'],
        'pending' => ['provider_observed', 'expired', 'abandoned'],
        'provider_observed' => ['dispatched', 'projection_rejected'],
        // Terminal statuses: normally no further transition, EXCEPT the two sanctioned
        // exceptions below (design spec §3.3 "Live-guard vs origination identity").
        'dispatched' => ['provider_observed', 'late_settlement_conflict'],
        'failed' => ['provider_observed', 'late_settlement_conflict'],
        'expired' => ['provider_observed', 'late_settlement_conflict'],
        'abandoned' => ['provider_observed', 'late_settlement_conflict'],
        'projection_rejected' => ['provider_observed', 'late_settlement_conflict'],
        'late_settlement_conflict' => [],
    ];

    /** Statuses that are NOT live: `dispatched`/`late_settlement_conflict` are as final as it gets. */
    private const TERMINAL = [
        'dispatched',
        'failed',
        'expired',
        'abandoned',
        'projection_rejected',
        'late_settlement_conflict',
    ];

    /**
     * `projection_reason` is `VARCHAR(255)` (see the migration). The spec calls it a "bounded
     * reason" twice -- {@see boundedReason()} is that bound, enforced in {@see acknowledge()}
     * rather than left to whatever the underlying DB driver does with an over-length value: a
     * strict engine would hard-fail the UPDATE entirely, and since `acknowledge()`'s caller (the
     * finalizer's strict listener) runs inside `WebhookService::dispatch()`'s lease scope, that
     * failure would just release the lease and retry FOREVER with the identical over-length
     * reason -- never actually recovering.
     */
    private const MAX_PROJECTION_REASON_LENGTH = 255;

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
        return self::TABLE;
    }

    /**
     * Insert a new `preparing` origination. Same (tenant, idempotency_key) returns the EXISTING
     * row instead of raising -- a retried request before its first attempt ever committed.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function claimPreparing(ApplicationContext $context, array $row): array
    {
        $tenantUuid = $this->resolver->tenantUuid($context);
        $idempotencyKey = (string) ($row['idempotency_key'] ?? '');
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('Checkout originations require an idempotency_key.');
        }

        $now = $this->now();
        $payload = array_merge($row, [
            'uuid' => (string) ($row['uuid'] ?? Utils::generateNanoID(12)),
            'tenant_uuid' => $tenantUuid,
            'status' => 'preparing',
            'live' => true,
            'consumer_metadata' => $this->encodeJson($row['consumer_metadata'] ?? null),
            'created_at' => $now,
        ]);

        try {
            $this->db->table(self::TABLE)->insert($payload);

            // Re-fetch rather than returning $payload directly: $payload only carries the keys
            // this call happened to set, while a real row also carries every other (NULL)
            // column a caller may reasonably expect back (checkout_reference, etc.).
            $inserted = $this->findByUuid((string) $payload['uuid']);
            if ($inserted === null) {
                throw new \RuntimeException('Checkout origination insert did not persist.');
            }

            return $inserted;
        } catch (\Throwable $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = $this->findByIdempotencyKey($context, $idempotencyKey);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where(['uuid' => $uuid])->limit(1)->first();

        return $row !== null ? $this->normalizeRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(ApplicationContext $context, string $idempotencyKey): ?array
    {
        if ($idempotencyKey === '') {
            return null;
        }

        $row = $this->db->table(self::TABLE)
            ->where(['tenant_uuid' => $this->resolver->tenantUuid($context), 'idempotency_key' => $idempotencyKey])
            ->limit(1)
            ->first();

        return $row !== null ? $this->normalizeRow($row) : null;
    }

    /**
     * Global correlation lookup: a signed provider webhook carries (gateway, checkout_reference)
     * alone, with no request tenant context -- the same shape as the gateway-subscription
     * projection table's own (gateway, subscription id) correlation identity.
     *
     * @return array<string,mixed>|null
     */
    public function findByCheckoutReference(string $gateway, string $checkoutReference): ?array
    {
        if ($gateway === '' || $checkoutReference === '') {
            return null;
        }

        $row = $this->db->table(self::TABLE)
            ->where(['gateway' => $gateway, 'checkout_reference' => $checkoutReference])
            ->limit(1)
            ->first();

        return $row !== null ? $this->normalizeRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByProviderSubscriptionId(string $gateway, string $providerSubscriptionId): ?array
    {
        if ($gateway === '' || $providerSubscriptionId === '') {
            return null;
        }

        $row = $this->db->table(self::TABLE)
            ->where(['gateway' => $gateway, 'provider_subscription_id' => $providerSubscriptionId])
            ->limit(1)
            ->first();

        return $row !== null ? $this->normalizeRow($row) : null;
    }

    /**
     * CAS the status column: `uuid` + `status = $from` -> `status = $to` plus any extra `$set`
     * fields. Illegal (from, to) pairs per {@see TRANSITIONS} are refused WITHOUT ever issuing a
     * write. Legal pairs are attempted as a compare-and-swap; if the row has already moved to
     * `$to` (e.g. a redelivered webhook replaying the same transition), this is a no-op `true`
     * rather than a failure -- transitions are idempotent, never merely "first caller wins".
     *
     * `live` is always derived from `$to`'s terminality (never trusted from `$set`), and
     * `customer_email` is force-cleared the moment `$to` is a definitive (terminal) outcome --
     * initialization recovery data has no reason to survive past that point.
     *
     * @param array<string,mixed> $set
     */
    public function transition(
        ApplicationContext $context,
        string $uuid,
        string $from,
        string $to,
        array $set = [],
    ): bool {
        if ($uuid === '' || !$this->isLegalTransition($from, $to)) {
            return false;
        }

        $fields = $this->withDerivedFields($to, $set);
        $fields['status'] = $to;
        $fields['updated_at'] = $this->now();

        $affected = $this->db->table(self::TABLE)
            ->where(['uuid' => $uuid, 'status' => $from])
            ->update($fields);

        if ($affected > 0) {
            return true;
        }

        $current = $this->findByUuid($uuid);

        return $current !== null && $current['status'] === $to;
    }

    /**
     * The durable projection-acknowledgement CAS writer (design spec §3.6): the SOLE way a
     * required projection consumer records its verdict for a correlated origination's
     * `subscription.created` delivery. Implements {@see SubscriptionProjectionAcknowledger}
     * verbatim -- Payvia owns this contract, and `PayviaServiceProvider` binds this class under
     * it so a host (subscriptions 2.2's strict bridge) resolves it from the container.
     *
     * Priority order (deliberately checked in THIS sequence, matching the spec's own ordering):
     *
     * 1. **Repeat of the identical outcome for this exact `logicalEventKey`** -- a no-op,
     *    REGARDLESS of the row's current `status` or `required_projection_consumer`. This is
     *    what makes duplicate delivery safe even after the origination has already finalized
     *    past `provider_observed` (e.g. a redelivered strict-listener call racing a sibling
     *    delivery that already completed the whole pipeline) -- crucially, it is also what
     *    makes the crash-after-projection-before-acknowledgement recovery path work: a consumer
     *    that re-reads its own already-persisted receipt and re-calls this with the SAME outcome
     *    it originally computed always succeeds, whether or not the first call's write actually
     *    landed.
     * 2. **Conflicting second outcome for the SAME `logicalEventKey`** -- throws. A `logicalEventKey`
     *    can only ever have ONE durable verdict; a caller attempting to overwrite it with a
     *    different outcome is a bug, not something to silently accept.
     * 3. **Wrong consumer** -- the row's `required_projection_consumer` must match `$consumer`
     *    exactly (including "no consumer required at all", i.e. `null`, which no `$consumer`
     *    string can ever equal). Refused.
     * 4. **Wrong state** -- only `provider_observed` accepts ordinary acknowledgements.
     *    `late_settlement_conflict` is the SOLE exception: it accepts ONLY a matching `rejected`
     *    acknowledgement (an `accepted` attempt against a conflict is refused loudly -- money
     *    that already moved to a NEWER reservation can never be treated as this stale
     *    origination's own success), records the outcome/reason, and deliberately does NOT
     *    change `status` or touch the guard -- `late_settlement_conflict` has no further legal
     *    transition (`TRANSITIONS['late_settlement_conflict']` is permanently empty). Every other
     *    status is refused outright.
     *
     * The actual write is a genuine CAS: `uuid` + `status` + `required_projection_consumer` all
     * must still match, AND `projection_event_key` must still be anything OTHER than
     * `$logicalEventKey` (closing the race between two concurrent first-time acknowledgements for
     * the SAME new key -- whichever commits first "wins" the key, and the second racer's write is
     * refused by this same guard, re-reads, and is correctly classified as either an idempotent
     * repeat or a genuine conflict by the exact same logic the synchronous pre-checks above use).
     * A refused CAS re-reads once and re-classifies against the fresh row rather than assuming
     * the worst.
     */
    public function acknowledge(
        string $originationUuid,
        string $consumer,
        string $logicalEventKey,
        string $outcome,
        ?string $reason = null,
    ): void {
        if ($originationUuid === '' || $consumer === '' || $logicalEventKey === '') {
            throw new \InvalidArgumentException(
                'CheckoutOriginationRepository::acknowledge() requires a non-empty originationUuid, '
                    . 'consumer, and logicalEventKey.'
            );
        }
        if ($outcome !== 'accepted' && $outcome !== 'rejected') {
            throw new \InvalidArgumentException(sprintf(
                'CheckoutOriginationRepository::acknowledge() outcome must be "accepted" or "rejected", '
                    . 'got "%s".',
                $outcome
            ));
        }

        $row = $this->findByUuid($originationUuid);
        if ($row === null) {
            throw ProjectionAcknowledgementRefused::unknownOrigination($originationUuid);
        }

        if (!$this->classifyAcknowledgement($row, $consumer, $logicalEventKey, $outcome)) {
            // A matching repeat: already durably recorded, nothing left to write.
            return;
        }

        $status = (string) $row['status'];
        $affected = $this->db->table(self::TABLE)->executeModification(
            'UPDATE ' . self::TABLE . ' '
                . 'SET projection_event_key = ?, projection_outcome = ?, projection_reason = ?, updated_at = ? '
                . 'WHERE uuid = ? AND status = ? AND required_projection_consumer = ? '
                . 'AND (projection_event_key IS NULL OR projection_event_key != ?)',
            [
                $logicalEventKey,
                $outcome,
                $this->boundedReason($reason),
                $this->now(),
                $originationUuid,
                $status,
                $consumer,
                $logicalEventKey,
            ],
        );

        if ($affected > 0) {
            return;
        }

        // Refused: the row moved under a concurrent write between our read and this CAS.
        // Re-read once and re-classify against the fresh state -- a concurrent identical
        // acknowledgement racing us to the SAME outcome is still a legitimate no-op.
        $current = $this->findByUuid($originationUuid);
        if ($current === null) {
            throw ProjectionAcknowledgementRefused::unknownOrigination($originationUuid);
        }

        if (!$this->classifyAcknowledgement($current, $consumer, $logicalEventKey, $outcome)) {
            // A concurrent identical acknowledgement raced us to the SAME outcome and won: this
            // is still a legitimate no-op.
            return;
        }

        // classifyAcknowledgement() said "this should have been a legitimate write" both times,
        // yet the CAS still matched no row: something outside the documented CAS invariants moved
        // the row between the two reads. Refuse rather than silently reporting success for a
        // write that never actually landed.
        throw ProjectionAcknowledgementRefused::wrongState($originationUuid, (string) $current['status']);
    }

    /**
     * Shared classification logic {@see acknowledge()} runs BOTH before its first CAS attempt and
     * again (against a fresh read) after a refused CAS -- see that method's docblock for the
     * exact priority order. Returns `false` for a legitimate no-op (an exact repeat of the same
     * outcome already durably recorded for the same logical event key -- nothing left to write);
     * throws {@see ProjectionAcknowledgementRefused} for every genuine refusal; returns `true`
     * only when this IS a new, legitimate acknowledgement the caller must still go write.
     *
     * @param array<string,mixed> $row
     */
    private function classifyAcknowledgement(
        array $row,
        string $consumer,
        string $logicalEventKey,
        string $outcome,
    ): bool {
        $originationUuid = (string) $row['uuid'];
        $existingKey = $row['projection_event_key'] ?? null;

        if ($existingKey !== null && (string) $existingKey === $logicalEventKey) {
            $existingOutcome = $row['projection_outcome'] ?? null;
            if ($existingOutcome === $outcome) {
                return false; // repeat delivery of the identical outcome: no-op.
            }

            throw ProjectionAcknowledgementRefused::conflictingOutcome(
                $originationUuid,
                is_string($existingOutcome) ? $existingOutcome : 'none',
                $outcome
            );
        }

        $requiredConsumer = $row['required_projection_consumer'] ?? null;
        if ($requiredConsumer !== $consumer) {
            throw ProjectionAcknowledgementRefused::wrongConsumer(
                $originationUuid,
                is_string($requiredConsumer) ? $requiredConsumer : null,
                $consumer
            );
        }

        $status = (string) $row['status'];
        if ($status === 'late_settlement_conflict') {
            if ($outcome !== 'rejected') {
                throw ProjectionAcknowledgementRefused::lateSettlementConflictRequiresRejected(
                    $originationUuid,
                    $outcome
                );
            }

            return true;
        }

        if ($status !== 'provider_observed') {
            throw ProjectionAcknowledgementRefused::wrongState($originationUuid, $status);
        }

        return true;
    }

    /**
     * Claim the initialization provider-I/O lease: matches a row that is `status = initializing`
     * AND whose lease is either empty or older than `$staleBefore`, then stamps it with `$token`.
     * Returns the (normalized) row on success, null when another owner currently holds a live
     * lease (or the row isn't `initializing` at all).
     *
     * @return array<string,mixed>|null
     */
    public function claimInitialization(
        ApplicationContext $context,
        string $uuid,
        string $token,
        \DateTimeImmutable $staleBefore,
    ): ?array {
        if ($uuid === '' || $token === '') {
            return null;
        }

        $cutoff = $this->formatDateTime($staleBefore);
        $now = $this->now();

        // Raw SQL (mirrors ProviderEventRepository::acquireLogicalDispatchLease()): the query
        // builder's UPDATE validator rejects the OR this atomic empty-or-stale match needs.
        $affected = $this->db->table(self::TABLE)->executeModification(
            'UPDATE ' . self::TABLE . ' '
                . 'SET initialization_claim_token = ?, initialization_claimed_at = ?, updated_at = ? '
                . 'WHERE uuid = ? AND status = ? '
                . 'AND (initialization_claim_token IS NULL OR initialization_claimed_at < ?)',
            [$token, $now, $now, $uuid, 'initializing', $cutoff],
        );

        return $affected > 0 ? $this->findByUuid($uuid) : null;
    }

    /**
     * Release a held initialization lease WITHOUT touching `status`: matches only the exact
     * (uuid, token) pair, so a stale/wrong token changes nothing.
     */
    public function releaseInitialization(ApplicationContext $context, string $uuid, string $token): bool
    {
        if ($uuid === '' || $token === '') {
            return false;
        }

        $affected = $this->db->table(self::TABLE)
            ->where(['uuid' => $uuid, 'status' => 'initializing', 'initialization_claim_token' => $token])
            ->update([
                'initialization_claim_token' => null,
                'initialization_claimed_at' => null,
                'updated_at' => $this->now(),
            ]);

        return $affected > 0;
    }

    /**
     * Finalize a held initialization lease: matches only `status = initializing` AND the exact
     * held `$token`, then advances `status` to `$to` (must be a legal `initializing` -> `$to`
     * transition -- normally `pending` or `failed`) and clears the lease. A stale/wrong token, or
     * an illegal `$to`, changes nothing.
     *
     * @param array<string,mixed> $set
     */
    public function completeInitialization(
        ApplicationContext $context,
        string $uuid,
        string $token,
        string $to,
        array $set = [],
    ): bool {
        if ($uuid === '' || $token === '' || !$this->isLegalTransition('initializing', $to)) {
            return false;
        }

        $fields = $this->withDerivedFields($to, $set);
        $fields['status'] = $to;
        $fields['initialization_claim_token'] = null;
        $fields['initialization_claimed_at'] = null;
        $fields['updated_at'] = $this->now();

        $affected = $this->db->table(self::TABLE)
            ->where(['uuid' => $uuid, 'status' => 'initializing', 'initialization_claim_token' => $token])
            ->update($fields);

        return $affected > 0;
    }

    /**
     * Exposes {@see TERMINAL} as a query rather than a duplicated list -- e.g. so
     * `GatewaySubscriptionService`'s late-settlement-conflict detection (design spec §3.3/§3.4)
     * can decide whether a correlated origination is eligible for the terminal-status regression
     * (re-bind to `provider_observed`, or advance to `late_settlement_conflict`) without
     * hardcoding this repository's own terminal-status list a second time.
     */
    public static function isTerminalStatus(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /**
     * `$from === $to` is only vacuously legal for a KNOWN status (every real status is a key of
     * {@see TRANSITIONS}, including `late_settlement_conflict`'s empty list) -- an unrecognized
     * status string must never be treated as legal merely because it happens to match itself.
     */
    private function isLegalTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return array_key_exists($from, self::TRANSITIONS);
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @param array<string,mixed> $set
     * @return array<string,mixed>
     */
    private function withDerivedFields(string $to, array $set): array
    {
        $terminal = in_array($to, self::TERMINAL, true);
        $set['live'] = !$terminal;
        if ($terminal) {
            $set['customer_email'] = null;
        }
        if (isset($set['consumer_metadata'])) {
            $set['consumer_metadata'] = $this->encodeJson($set['consumer_metadata']);
        }

        return $set;
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : (string) json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $metadata = $row['consumer_metadata'] ?? null;
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            $row['consumer_metadata'] = is_array($decoded) ? $decoded : null;
        }

        return $row;
    }

    /**
     * @see MAX_PROJECTION_REASON_LENGTH. `mb_substr()` truncates on character boundaries, never
     * splitting a multi-byte character in half.
     */
    private function boundedReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return mb_substr($reason, 0, self::MAX_PROJECTION_REASON_LENGTH);
    }

    private function now(): string
    {
        return $this->db->getDriver()->formatDateTime();
    }

    private function formatDateTime(\DateTimeInterface $dateTime): string
    {
        return $this->db->getDriver()->formatDateTime($dateTime->format('Y-m-d H:i:s'));
    }
}
