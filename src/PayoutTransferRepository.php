<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Repositories\Concerns\DetectsUniqueViolations;
use Glueful\Extensions\Payvia\Repositories\Concerns\NormalizesAmountColumn;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

/**
 * Durable, pre-provider-I/O record of a payout transfer attempt
 * (`payvia_transfers`, Commerce Marketplace MV4 spec §3.4).
 *
 * A row is written by {@see insertPending()} BEFORE any gateway I/O runs, so
 * a lost/ambiguous provider response can be recovered by loading the row
 * instead of blindly re-attempting the transfer. `(tenant_uuid, gateway,
 * idempotency_key)` is unique -- a duplicate insert IS the caller's signal
 * that this idempotency key already has an attempt in flight (or resolved).
 */
final class PayoutTransferRepository extends BaseRepository
{
    use DetectsUniqueViolations;
    use NormalizesAmountColumn;

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
        return 'payvia_transfers';
    }

    public function findByIdempotencyKey(
        ApplicationContext $context,
        string $gateway,
        string $idempotencyKey
    ): ?array {
        if ($gateway === '' || $idempotencyKey === '') {
            return null;
        }

        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where([
                'tenant_uuid' => $this->resolver->tenantUuid($context),
                'gateway' => $gateway,
                'idempotency_key' => $idempotencyKey,
            ])
            ->limit(1)
            ->get();

        $row = $rows[0] ?? null;

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * Insert the pre-I/O attempt row. Returns false (never throws) when the
     * insert loses a `(tenant_uuid, gateway, idempotency_key)` race -- the
     * caller recovers via {@see findByIdempotencyKey()} instead of minting
     * another attempt.
     *
     * @param array<string,mixed> $row
     */
    public function insertPending(ApplicationContext $context, array $row): bool
    {
        $gateway = (string) ($row['gateway'] ?? '');
        $idempotencyKey = (string) ($row['idempotency_key'] ?? '');
        if ($gateway === '' || $idempotencyKey === '') {
            throw new \InvalidArgumentException('Payout transfers require gateway and idempotency_key.');
        }

        $payload = array_merge($row, [
            'uuid' => (string) ($row['uuid'] ?? Utils::generateNanoID()),
            'tenant_uuid' => $this->resolver->tenantUuid($context),
            'status' => 'pending',
            'request_payload' => $this->encodeJson($row['request_payload'] ?? []),
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
     * Record the provider's classified result against the row identified by
     * $uuid. Never widens the tenant scope -- the update is a no-op for a
     * uuid that does not belong to the resolved tenant.
     *
     * @param array<string,mixed> $data
     */
    public function setResult(ApplicationContext $context, string $uuid, array $data): void
    {
        if ($uuid === '') {
            return;
        }

        if (array_key_exists('raw_payload', $data)) {
            $data['raw_payload'] = $this->encodeJson($data['raw_payload']);
        }

        $this->db->table($this->getTableName())
            ->where(['uuid' => $uuid, 'tenant_uuid' => $this->resolver->tenantUuid($context)])
            ->update(array_merge($data, [
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]));
    }

    private function encodeJson(mixed $payload): string
    {
        return (string) json_encode($payload ?? [], JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $row */
    private function normalizeRow(array $row): array
    {
        foreach (['request_payload', 'raw_payload'] as $column) {
            $value = $row[$column] ?? null;
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                $row[$column] = is_array($decoded) ? $decoded : null;
            }
        }

        return $this->normalizeAmountColumn($row);
    }
}
