<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Support;

use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;

/**
 * Minimal stand-in for {@see ProviderEventRepositoryInterface} used to drive
 * {@see \Glueful\Extensions\Payvia\Support\CheckoutSandboxProof\IngestionPathProbe} in tests
 * without a real database. Every method beyond `findDispatchable()` is unused by the probe and
 * simply satisfies the contract.
 */
final class FakeProviderEventRepository implements ProviderEventRepositoryInterface
{
    public function __construct(private readonly bool $throwsOnRead = false)
    {
    }

    public function findByDeliveryKey(string $gateway, string $deliveryKey): ?array
    {
        return null;
    }

    public function insertReceived(array $data): ?string
    {
        return null;
    }

    public function markProcessing(string $uuid): void
    {
    }

    public function markProcessed(string $uuid): void
    {
    }

    public function markFailed(string $uuid, string $error): void
    {
    }

    public function incrementAttempts(string $uuid): void
    {
    }

    public function isLogicalDispatched(string $gateway, string $logicalEventKey): bool
    {
        return false;
    }

    public function claimLogicalForDispatch(string $gateway, string $logicalEventKey): int
    {
        return 0;
    }

    public function reclaimStaleDispatching(string $gateway, string $logicalEventKey, int $staleSeconds): int
    {
        return 0;
    }

    public function markLogicalDispatched(string $gateway, string $logicalEventKey): void
    {
    }

    public function markDispatched(string $uuid): void
    {
    }

    public function findDispatchable(int $limit = 100, int $staleSeconds = 300): array
    {
        if ($this->throwsOnRead) {
            throw new \RuntimeException('provider_events table does not exist');
        }

        return [];
    }

    public function findByUuid(string $uuid): ?array
    {
        return null;
    }
}
