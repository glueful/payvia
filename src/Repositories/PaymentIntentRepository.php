<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Repositories\Concerns\DetectsUniqueViolations;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

final class PaymentIntentRepository extends BaseRepository
{
    use DetectsUniqueViolations;

    public function getTableName(): string
    {
        return 'payment_intents';
    }

    public function findOpen(ApplicationContext $context, string $payableType, string $payableId): ?array
    {
        unset($context);

        if ($payableType === '' || $payableId === '') {
            return null;
        }

        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where([
                'payable_type' => $payableType,
                'payable_id' => $payableId,
                'status' => 'open',
            ])
            ->limit(1)
            ->get();

        $row = $rows[0] ?? null;
        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /** @param array<string,mixed> $row */
    public function createOpen(ApplicationContext $context, array $row): bool
    {
        unset($context);

        $payableType = (string) ($row['payable_type'] ?? '');
        $payableId = (string) ($row['payable_id'] ?? '');
        if ($payableType === '' || $payableId === '') {
            throw new \InvalidArgumentException('Payment intents require payable_type and payable_id.');
        }

        $payload = array_merge($row, [
            'uuid' => (string) ($row['uuid'] ?? Utils::generateNanoID()),
            'idempotency_key' => $this->openKey($payableType, $payableId),
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

    public function close(ApplicationContext $context, string $uuid, string $reference): void
    {
        unset($context);

        if ($uuid === '') {
            return;
        }

        $rows = $this->db->table($this->getTableName())
            ->select(['*'])
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->get();
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return;
        }

        $closeReference = $reference !== '' ? $reference : (string) ($row['reference'] ?? '');
        $this->db->table($this->getTableName())
            ->where(['uuid' => $uuid])
            ->update([
                'status' => 'closed',
                'idempotency_key' => $this->closedKey(
                    (string) $row['payable_type'],
                    (string) $row['payable_id'],
                    $closeReference
                ),
                'updated_at' => $this->db->getDriver()->formatDateTime(),
            ]);
    }

    private function openKey(string $payableType, string $payableId): string
    {
        return $payableType . ':' . $payableId;
    }

    private function closedKey(string $payableType, string $payableId, string $reference): string
    {
        return $payableType . ':' . $payableId . ':' . $reference;
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

        return $row;
    }
}
