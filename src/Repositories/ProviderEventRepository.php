<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

final class ProviderEventRepository extends BaseRepository implements ProviderEventRepositoryInterface
{
    public function getTableName(): string
    {
        return 'provider_events';
    }

    public function findByDeliveryKey(string $gateway, string $deliveryKey): ?array
    {
        return $this->db->table($this->getTableName())
            ->where(['gateway' => $gateway, 'delivery_key' => $deliveryKey])
            ->limit(1)
            ->first();
    }

    public function insertReceived(array $data): ?string
    {
        $uuid = Utils::generateNanoID(12);
        $now = $this->now();
        $row = array_merge($data, [
            'uuid' => $uuid,
            'status' => $data['status'] ?? 'received',
            'dispatch_status' => $data['dispatch_status'] ?? 'pending',
            'attempts' => $data['attempts'] ?? 0,
            'received_at' => $data['received_at'] ?? $now,
        ]);

        foreach (['normalized_payload', 'raw_payload'] as $jsonColumn) {
            if (isset($row[$jsonColumn]) && is_array($row[$jsonColumn])) {
                $row[$jsonColumn] = json_encode($row[$jsonColumn], JSON_THROW_ON_ERROR);
            }
        }

        try {
            $this->db->table($this->getTableName())->insert($row);
        } catch (\Throwable $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }

        return $uuid;
    }

    public function markProcessing(string $uuid): void
    {
        $this->updateUuid($uuid, ['status' => 'processing']);
    }

    public function markProcessed(string $uuid): void
    {
        $this->updateUuid($uuid, [
            'status' => 'processed',
            'processed_at' => $this->now(),
            'error' => null,
        ]);
    }

    public function markFailed(string $uuid, string $error): void
    {
        $this->updateUuid($uuid, [
            'status' => 'failed',
            'error' => substr($error, 0, 255),
        ]);
    }

    public function incrementAttempts(string $uuid): void
    {
        $this->db->table($this->getTableName())
            ->executeModification(
                'UPDATE provider_events SET attempts = attempts + 1 WHERE uuid = ?',
                [$uuid]
            );
    }

    public function isLogicalDispatched(string $gateway, string $logicalEventKey): bool
    {
        $row = $this->db->table($this->getTableName())
            ->where([
                'gateway' => $gateway,
                'logical_event_key' => $logicalEventKey,
                'dispatch_status' => 'dispatched',
            ])
            ->limit(1)
            ->first();

        return $row !== null;
    }

    public function claimLogicalForDispatch(string $gateway, string $logicalEventKey): int
    {
        return (int) $this->db->table($this->getTableName())
            ->where([
                'gateway' => $gateway,
                'logical_event_key' => $logicalEventKey,
                'dispatch_status' => 'pending',
            ])
            ->update([
                'dispatch_status' => 'dispatching',
                'dispatch_claimed_at' => $this->now(),
            ]);
    }

    public function reclaimStaleDispatching(string $gateway, string $logicalEventKey, int $staleSeconds): int
    {
        $cutoff = $this->formatDateTime(new \DateTimeImmutable('-' . $staleSeconds . ' seconds'));

        return (int) $this->db->table($this->getTableName())
            ->where([
                'gateway' => $gateway,
                'logical_event_key' => $logicalEventKey,
                'dispatch_status' => 'dispatching',
            ])
            ->where('dispatch_claimed_at', '<', $cutoff)
            ->update(['dispatch_claimed_at' => $this->now()]);
    }

    public function markLogicalDispatched(string $gateway, string $logicalEventKey): void
    {
        $this->db->table($this->getTableName())
            ->where(['gateway' => $gateway, 'logical_event_key' => $logicalEventKey])
            ->update([
                'dispatch_status' => 'dispatched',
                'dispatched_at' => $this->now(),
            ]);
    }

    public function markDispatched(string $uuid): void
    {
        $this->updateUuid($uuid, [
            'dispatch_status' => 'dispatched',
            'dispatched_at' => $this->now(),
        ]);
    }

    public function findDispatchable(int $limit = 100, int $staleSeconds = 300): array
    {
        $cutoff = $this->formatDateTime(new \DateTimeImmutable('-' . $staleSeconds . ' seconds'));

        return $this->db->table($this->getTableName())
            ->select(['*'])
            ->where('status', '=', 'processed')
            ->whereRaw(
                '(dispatch_status = ? OR (dispatch_status = ? AND dispatch_claimed_at < ?))',
                ['pending', 'dispatching', $cutoff]
            )
            ->orderBy(['received_at' => 'ASC'])
            ->limit($limit)
            ->get();
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->db->table($this->getTableName())
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->first();
    }

    public function isUniqueViolation(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'UNIQUE')
            || str_contains(strtolower($message), 'unique')
            || str_contains($message, '23000')
            || str_contains($message, '23505');
    }

    /** @param array<string,mixed> $data */
    private function updateUuid(string $uuid, array $data): void
    {
        $this->db->table($this->getTableName())
            ->where(['uuid' => $uuid])
            ->update($data);
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
