<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
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
    public function create(ApplicationContext $context, array $data): string
    {
        return $this->plans->createPlan($context, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(ApplicationContext $context, string $planUuid, array $data): bool
    {
        return $this->plans->updatePlan($context, $planUuid, $data);
    }

    public function disable(ApplicationContext $context, string $planUuid): bool
    {
        return $this->plans->disable($context, $planUuid);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function list(ApplicationContext $context, array $filters = []): array
    {
        return $this->plans->list($context, $filters);
    }
}
