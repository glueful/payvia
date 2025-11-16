<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Extensions\Payvia\Contracts\BillingPlanRepositoryInterface;

/**
 * Billing Plan Service
 *
 * Lightweight application service for managing billing plans. This is
 * intentionally generic; applications can build richer domain-specific
 * services on top.
 */
final class BillingPlanService
{
    public function __construct(
        private BillingPlanRepositoryInterface $plans,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): string
    {
        return $this->plans->create($data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(string $planUuid, array $data): bool
    {
        return $this->plans->update($planUuid, $data);
    }

    public function disable(string $planUuid): bool
    {
        return $this->plans->disable($planUuid);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        return $this->plans->list($filters);
    }
}
