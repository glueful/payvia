<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Billing Plan Repository Contract
 *
 * Defines persistence operations for the billing_plans table. Every method resolves the
 * current tenant through the injected `PayviaTenantResolver` and constrains
 * tenant_uuid + identity; a cross-tenant `plan_uuid` is treated identically to an unknown one
 * (non-revealing not-found).
 *
 * Named `createPlan`/`updatePlan` rather than `create`/`update`: `BillingPlanRepository` extends
 * `Glueful\Repository\BaseRepository`, which already declares concrete `create(array $data)`/
 * `update(string $uuid, array $data)` methods with a different (context-less) signature --
 * reusing those names for a context-first tenant-aware signature is not override-compatible.
 */
interface BillingPlanRepositoryInterface
{
    public function getTableName(): string;

    /**
     * @param array<string,mixed> $data
     * @return string UUID of created plan
     */
    public function createPlan(ApplicationContext $context, array $data): string;

    /**
     * @param string $planUuid
     * @param array<string,mixed> $data
     */
    public function updatePlan(ApplicationContext $context, string $planUuid, array $data): bool;

    public function disable(ApplicationContext $context, string $planUuid): bool;

    /**
     * List plans with optional filters.
     *
     * Supported filters:
     * - status: string
     * - interval: string
     * - currency: string
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function list(ApplicationContext $context, array $filters = []): array;
}
