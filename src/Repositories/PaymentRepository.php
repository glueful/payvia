<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Repositories\Concerns\NormalizesAmountColumn;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

final class PaymentRepository extends BaseRepository implements PaymentRepositoryInterface
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
        return 'payments';
    }

    public function createPayment(ApplicationContext $context, array $data): string
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

    public function findByReference(ApplicationContext $context, string $reference): ?array
    {
        if ($reference === '') {
            return null;
        }

        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where(['tenant_uuid' => $this->resolver->tenantUuid($context), 'reference' => $reference])
            ->limit(1)
            ->get();

        $row = $rows[0] ?? null;

        return is_array($row) ? $this->normalizeAmountColumn($row) : null;
    }

    public function updateByReference(ApplicationContext $context, string $reference, array $data): bool
    {
        if ($reference === '' || $data === []) {
            return false;
        }

        $affected = $this->db->table($this->getTableName())
            ->where(['tenant_uuid' => $this->resolver->tenantUuid($context), 'reference' => $reference])
            ->update(array_merge($data, [
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]));

        return (int) $affected > 0;
    }
}
