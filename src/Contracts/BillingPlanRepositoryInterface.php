<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * Billing Plan Repository Contract
 *
 * Defines persistence operations for the billing_plans table.
 */
interface BillingPlanRepositoryInterface
{
    public function getTableName(): string;

    /**
     * @param array<string,mixed> $data
     * @return string UUID of created plan
     */
    public function create(array $data): string;

    /**
     * @param string $planUuid
     * @param array<string,mixed> $data
     */
    public function update(string $planUuid, array $data): bool;

    public function disable(string $planUuid): bool;

    /**
     * List plans with optional filters.
     *
     * Supported filters:
     * - status: string
     * - interval: string
     * - currency: string
     * - features_contains: ['key' => string, 'value' => string]
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function list(array $filters = []): array;
}
