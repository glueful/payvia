<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Repository\BaseRepository;
use Glueful\Helpers\Utils;

final class PaymentRepository extends BaseRepository implements PaymentRepositoryInterface
{
    public function getTableName(): string
    {
        return 'payments';
    }

    public function createPayment(array $data): string
    {
        $uuid = Utils::generateNanoID();
        $payload = array_merge($data, [
            'uuid' => $uuid,
            'created_at' => $this->db->getDriver()->formatDateTime(),
        ]);

        $this->db->table($this->getTableName())->insert($payload);

        return $uuid;
    }

    public function findByReference(string $reference): ?array
    {
        if ($reference === '') {
            return null;
        }

        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where(['reference' => $reference])
            ->limit(1)
            ->get();

        return $rows[0] ?? null;
    }

    public function updateByReference(string $reference, array $data): bool
    {
        if ($reference === '' || $data === []) {
            return false;
        }

        $affected = $this->db->table($this->getTableName())
            ->where(['reference' => $reference])
            ->update(array_merge($data, [
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]));

        return (int) $affected > 0;
    }
}
