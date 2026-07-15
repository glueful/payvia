<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Contracts\InvoiceRepositoryInterface;
use Glueful\Extensions\Payvia\Repositories\Concerns\NormalizesAmountColumn;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

final class InvoiceRepository extends BaseRepository implements InvoiceRepositoryInterface
{
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
        return 'invoices';
    }

    public function createInvoice(ApplicationContext $context, array $data): string
    {
        $uuid = Utils::generateNanoID();
        $now = $this->db->getDriver()->formatDateTime();

        $number = $data['number'] ?? null;
        if (!is_string($number) || $number === '') {
            // Timestamp prefix for readability + the full NanoID for entropy.
            // Using only the last 4 chars of the 12-char uuid risked UNIQUE(tenant_uuid, number)
            // collisions; the full id keeps the generated number unique.
            $number = 'INV-' . date('Ymd-His') . '-' . strtoupper($uuid);
        }

        $payload = array_merge($data, [
            'uuid' => $uuid,
            'tenant_uuid' => $this->resolver->tenantUuid($context),
            'number' => $number,
            'created_at' => $now,
        ]);

        $this->db->table($this->getTableName())->insert($payload);

        return $uuid;
    }

    public function markPaid(
        ApplicationContext $context,
        string $invoiceUuid,
        ?\DateTimeImmutable $paidAt = null
    ): bool {
        $affected = $this->db->table($this->getTableName())
            ->where(['uuid' => $invoiceUuid, 'tenant_uuid' => $this->resolver->tenantUuid($context)])
            ->update([
                'status' => 'paid',
                'paid_at' => ($paidAt?->format('Y-m-d H:i:s')) ?? $this->db->getDriver()->formatDateTime(),
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]);

        return (int) $affected > 0;
    }

    public function markCanceled(ApplicationContext $context, string $invoiceUuid): bool
    {
        $affected = $this->db->table($this->getTableName())
            ->where(['uuid' => $invoiceUuid, 'tenant_uuid' => $this->resolver->tenantUuid($context)])
            ->update([
                'status' => 'canceled',
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]);

        return (int) $affected > 0;
    }

    public function list(ApplicationContext $context, array $filters = []): array
    {
        $qb = $this->db->table($this->getTableName())
            ->select([
                'uuid',
                'user_uuid',
                'billing_plan_uuid',
                'payable_type',
                'payable_id',
                'number',
                'amount',
                'currency',
                'status',
                'due_at',
                'paid_at',
                'metadata',
                'created_at',
                'updated_at',
            ])
            ->where('tenant_uuid', '=', $this->resolver->tenantUuid($context))
            ->orderBy(['created_at' => 'DESC']);

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $qb = $qb->where('status', '=', $filters['status']);
        }

        if (isset($filters['user_uuid']) && is_string($filters['user_uuid']) && $filters['user_uuid'] !== '') {
            $qb = $qb->where('user_uuid', '=', $filters['user_uuid']);
        }

        if (
            isset($filters['billing_plan_uuid'])
            && is_string($filters['billing_plan_uuid'])
            && $filters['billing_plan_uuid'] !== ''
        ) {
            $qb = $qb->where('billing_plan_uuid', '=', $filters['billing_plan_uuid']);
        }

        if (isset($filters['payable_type']) && is_string($filters['payable_type']) && $filters['payable_type'] !== '') {
            $qb = $qb->where('payable_type', '=', $filters['payable_type']);
        }

        if (isset($filters['payable_id']) && is_string($filters['payable_id']) && $filters['payable_id'] !== '') {
            $qb = $qb->where('payable_id', '=', $filters['payable_id']);
        }

        if (isset($filters['metadata_contains']) && is_array($filters['metadata_contains'])) {
            $key = $filters['metadata_contains']['key'] ?? null;
            $value = $filters['metadata_contains']['value'] ?? null;
            if (is_string($key) && $key !== '' && is_scalar($value)) {
                $qb = $qb->whereJsonContains('metadata', (string) $value, '$.' . $key);
            }
        }

        return $this->normalizeAmountColumns($qb->get());
    }

    public function paginateWithFilters(
        ApplicationContext $context,
        int $page,
        int $perPage,
        array $filters = []
    ): array {
        $qb = $this->db->table($this->getTableName())
            ->select([
                'uuid',
                'user_uuid',
                'billing_plan_uuid',
                'payable_type',
                'payable_id',
                'number',
                'amount',
                'currency',
                'status',
                'due_at',
                'paid_at',
                'metadata',
                'created_at',
                'updated_at',
            ])
            ->where('tenant_uuid', '=', $this->resolver->tenantUuid($context))
            ->orderBy(['created_at' => 'DESC']);

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $qb = $qb->where('status', '=', $filters['status']);
        }

        if (isset($filters['user_uuid']) && is_string($filters['user_uuid']) && $filters['user_uuid'] !== '') {
            $qb = $qb->where('user_uuid', '=', $filters['user_uuid']);
        }

        if (
            isset($filters['billing_plan_uuid'])
            && is_string($filters['billing_plan_uuid'])
            && $filters['billing_plan_uuid'] !== ''
        ) {
            $qb = $qb->where('billing_plan_uuid', '=', $filters['billing_plan_uuid']);
        }

        if (isset($filters['payable_type']) && is_string($filters['payable_type']) && $filters['payable_type'] !== '') {
            $qb = $qb->where('payable_type', '=', $filters['payable_type']);
        }

        if (isset($filters['payable_id']) && is_string($filters['payable_id']) && $filters['payable_id'] !== '') {
            $qb = $qb->where('payable_id', '=', $filters['payable_id']);
        }

        if (isset($filters['metadata_contains']) && is_array($filters['metadata_contains'])) {
            $key = $filters['metadata_contains']['key'] ?? null;
            $value = $filters['metadata_contains']['value'] ?? null;
            if (is_string($key) && $key !== '' && is_scalar($value)) {
                $qb = $qb->whereJsonContains('metadata', (string) $value, '$.' . $key);
            }
        }

        $result = $qb->paginate($page, $perPage);
        $result['data'] = $this->normalizeAmountColumns($result['data']);

        return $result;
    }
}
