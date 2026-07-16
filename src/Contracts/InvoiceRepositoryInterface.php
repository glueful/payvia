<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Invoice Repository Contract
 *
 * Defines persistence operations for the invoices table. Every method resolves the current
 * tenant through the injected `PayviaTenantResolver` and constrains tenant_uuid + identity;
 * a cross-tenant `invoice_uuid` is treated identically to an unknown one (non-revealing
 * not-found).
 *
 * Named `createInvoice` rather than `create`: `InvoiceRepository` extends
 * `Glueful\Repository\BaseRepository`, which already declares a concrete `create(array $data)`
 * method with a different (context-less) signature -- reusing that name for a context-first
 * tenant-aware signature is not override-compatible.
 */
interface InvoiceRepositoryInterface
{
    public function getTableName(): string;

    /**
     * @param array<string,mixed> $data
     * @return string UUID of created invoice
     */
    public function createInvoice(ApplicationContext $context, array $data): string;

    /**
     * Mark invoice as paid.
     */
    public function markPaid(
        ApplicationContext $context,
        string $invoiceUuid,
        ?\DateTimeImmutable $paidAt = null
    ): bool;

    /**
     * Mark invoice as canceled.
     */
    public function markCanceled(ApplicationContext $context, string $invoiceUuid): bool;

    /**
     * List invoices with optional filters.
     *
     * Supported filters:
     * - status: string
     * - user_uuid: string
     * - billing_plan_uuid: string
     * - payable_type: string
     * - payable_id: string
     * - metadata_contains: ['key' => string, 'value' => string]
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function list(ApplicationContext $context, array $filters = []): array;

    /**
     * Paginate invoices with optional filters.
     *
     * @param int $page
     * @param int $perPage
     * @param array<string,mixed> $filters
     * @return array<string,mixed> Paginated result (items + meta)
     */
    public function paginateWithFilters(
        ApplicationContext $context,
        int $page,
        int $perPage,
        array $filters = []
    ): array;
}
