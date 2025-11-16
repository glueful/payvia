<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Extensions\Payvia\Contracts\BillingPlanRepositoryInterface;
use Glueful\Repository\BaseRepository;
use Glueful\Helpers\Utils;

final class BillingPlanRepository extends BaseRepository implements BillingPlanRepositoryInterface
{
    public function getTableName(): string
    {
        return 'billing_plans';
    }

    public function create(array $data): string
    {
        $uuid = Utils::generateNanoID();
        $payload = array_merge($data, [
            'uuid' => $uuid,
            'created_at' => $this->db->getDriver()->formatDateTime(),
        ]);

        $this->db->table($this->getTableName())->insert($payload);

        return $uuid;
    }

    public function update(string $planUuid, array $data): bool
    {
        $affected = $this->db->table($this->getTableName())
            ->where(['uuid' => $planUuid])
            ->update(array_merge($data, [
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]));

        return (int) $affected > 0;
    }

    public function disable(string $planUuid): bool
    {
        $affected = $this->db->table($this->getTableName())
            ->where(['uuid' => $planUuid])
            ->update([
                'status' => 'inactive',
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]);

        return (int) $affected > 0;
    }

    public function list(array $filters = []): array
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
                'features',
                'metadata',
                'status',
                'created_at',
                'updated_at',
            ])
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

        if (isset($filters['features_contains']) && is_array($filters['features_contains'])) {
            $key = $filters['features_contains']['key'] ?? null;
            $value = $filters['features_contains']['value'] ?? null;
            if (is_string($key) && $key !== '' && is_scalar($value)) {
                $qb = $qb->whereJsonContains('features', (string) $value, '$.' . $key);
            }
        }

        return $qb->get();
    }
}
