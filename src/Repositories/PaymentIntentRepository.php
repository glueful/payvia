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

    /**
     * Service-enforced closed status set (payment-links Task 1). No DB enum -- matches every
     * other status column in this schema. `initializing` and `open` are the two statuses that
     * hold the payable's active idempotency port (see {@see activePortKey()}); the other three
     * are terminal/superseded outcomes, re-keyed by attempt uuid to free that port.
     *
     * @var list<string>
     */
    public const STATUSES = ['initializing', 'open', 'superseded', 'closed', 'failed'];

    /** @var list<string> */
    private const ACTIVE_STATUSES = ['initializing', 'open'];

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
                'status' => 'open',
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

    /** @param array<string,mixed> $row */
    public function createOpen(ApplicationContext $context, array $row): bool
    {
        $payableType = (string) ($row['payable_type'] ?? '');
        $payableId = (string) ($row['payable_id'] ?? '');
        if ($payableType === '' || $payableId === '') {
            throw new \InvalidArgumentException('Payment intents require payable_type and payable_id.');
        }

        $payload = array_merge($row, [
            'uuid' => (string) ($row['uuid'] ?? Utils::generateNanoID()),
            'tenant_uuid' => $this->resolver->tenantUuid($context),
            'idempotency_key' => $this->activePortKey($payableType, $payableId),
            'status' => 'open',
            'payload' => $this->encodePayload($row['payload'] ?? null),
            'created_at' => $this->db->getDriver()->formatDateTime(),
        ]);

        try {
            $this->db->table($this->getTableName())->insert($payload);
            return true;
        } catch (\Throwable $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
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
            'status' => 'initializing',
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
     * @param array<string,mixed>|null $payload
     */
    public function markOpen(ApplicationContext $context, string $uuid, string $reference, ?array $payload = null): bool
    {
        if ($uuid === '' || $reference === '') {
            return false;
        }

        $affected = $this->db->table($this->getTableName())
            ->where([
                'uuid' => $uuid,
                'tenant_uuid' => $this->resolver->tenantUuid($context),
                'status' => 'initializing',
            ])
            ->update([
                'reference' => $reference,
                'status' => 'open',
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
        return $this->retire($context, $uuid, self::ACTIVE_STATUSES, 'superseded');
    }

    /**
     * Close a successfully collected `open` intent. Kept at its original (context, uuid,
     * reference) shape for every existing caller -- {@see \Glueful\Extensions\Payvia\Services
     * \ConfirmationDispatcher} included -- but `$reference` is no longer needed to compute the
     * re-keyed idempotency_key (see {@see retire()}): the row's own attempt uuid is used instead,
     * which -- unlike a reference -- is always present even for a row that never reached `open`.
     * Accepted only for call-site compatibility.
     */
    public function close(ApplicationContext $context, string $uuid, string $reference = ''): void
    {
        unset($reference);
        $this->retire($context, $uuid, ['open'], 'closed');
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
        return $this->retire($context, $uuid, ['initializing'], 'failed');
    }

    /**
     * Shared terminal/superseded transition: reads the row (tenant-scoped), refuses if it is
     * missing or not currently in one of `$fromStatuses`, otherwise CASes to `$toStatus` and
     * re-keys `idempotency_key` to {@see retiredKey()} -- freeing the active port the instant the
     * row leaves `initializing`/`open`.
     *
     * @param list<string> $fromStatuses
     */
    private function retire(ApplicationContext $context, string $uuid, array $fromStatuses, string $toStatus): bool
    {
        if ($uuid === '') {
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
