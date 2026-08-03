<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
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
final class CheckoutOriginationRepository extends BaseRepository
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

    private function now(): string
    {
        return $this->db->getDriver()->formatDateTime();
    }

    private function formatDateTime(\DateTimeInterface $dateTime): string
    {
        return $this->db->getDriver()->formatDateTime($dateTime->format('Y-m-d H:i:s'));
    }
}
