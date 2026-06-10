<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

interface ProviderEventRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findByDeliveryKey(string $gateway, string $deliveryKey): ?array;

    /** @param array<string,mixed> $data */
    public function insertReceived(array $data): ?string;

    public function markProcessing(string $uuid): void;

    public function markProcessed(string $uuid): void;

    public function markFailed(string $uuid, string $error): void;

    public function incrementAttempts(string $uuid): void;

    public function isLogicalDispatched(string $gateway, string $logicalEventKey): bool;

    public function claimLogicalForDispatch(string $gateway, string $logicalEventKey): int;

    public function reclaimStaleDispatching(string $gateway, string $logicalEventKey, int $staleSeconds): int;

    public function markLogicalDispatched(string $gateway, string $logicalEventKey): void;

    public function markDispatched(string $uuid): void;

    /** @return array<int,array<string,mixed>> */
    public function findDispatchable(int $limit = 100, int $staleSeconds = 300): array;

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array;
}
