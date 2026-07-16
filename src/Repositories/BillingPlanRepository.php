<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Contracts\BillingPlanRepositoryInterface;
use Glueful\Extensions\Payvia\Repositories\Concerns\NormalizesAmountColumn;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

final class BillingPlanRepository extends BaseRepository implements BillingPlanRepositoryInterface
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
        return 'billing_plans';
    }

    public function createPlan(ApplicationContext $context, array $data): string
    {
        $uuid = Utils::generateNanoID();
        $payload = array_merge($data, [
            'uuid' => $uuid,
            'tenant_uuid' => $this->resolver->tenantUuid($context),
            'created_at' => $this->db->getDriver()->formatDateTime(),
        ]);

        $this->db->table($this->getTableName())->insert($payload);

        return $uuid;
    }

    public function updatePlan(ApplicationContext $context, string $planUuid, array $data): bool
    {
        $affected = $this->db->table($this->getTableName())
            ->where(['uuid' => $planUuid, 'tenant_uuid' => $this->resolver->tenantUuid($context)])
            ->update(array_merge($data, [
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]));

        return (int) $affected > 0;
    }

    public function disable(ApplicationContext $context, string $planUuid): bool
    {
        $affected = $this->db->table($this->getTableName())
            ->where(['uuid' => $planUuid, 'tenant_uuid' => $this->resolver->tenantUuid($context)])
            ->update([
                'status' => 'inactive',
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]);

        return (int) $affected > 0;
    }

    public function list(ApplicationContext $context, array $filters = []): array
    {
        $qb = $this->db->table($this->getTableName())
            ->select([
                'uuid',
                'name',
                'description',
                'amount',
                'currency',
                'interval',
                'trial_days',
                'gateway',
                'gateway_product_id',
                'gateway_price_id',
                'metadata',
                'status',
                'created_at',
                'updated_at',
            ])
            ->where('tenant_uuid', '=', $this->resolver->tenantUuid($context))
            ->orderBy(['created_at' => 'DESC']);

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $qb = $qb->where('status', '=', $filters['status']);
        }

        if (isset($filters['interval']) && is_string($filters['interval']) && $filters['interval'] !== '') {
            $qb = $qb->where('interval', '=', $filters['interval']);
        }

        if (isset($filters['currency']) && is_string($filters['currency']) && $filters['currency'] !== '') {
            $qb = $qb->where('currency', '=', $filters['currency']);
        }

        return $this->normalizeAmountColumns($qb->get());
    }
}
