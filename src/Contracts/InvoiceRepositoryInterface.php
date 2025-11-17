<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * Invoice Repository Contract
 *
 * Defines persistence operations for the invoices table.
 */
interface InvoiceRepositoryInterface
{
    public function getTableName(): string;

    /**
     * @param array<string,mixed> $data
     * @return string UUID of created invoice
     */
    public function create(array $data): string;

    /**
     * Mark invoice as paid.
     */
    public function markPaid(string $invoiceUuid, ?\DateTimeImmutable $paidAt = null): bool;

    /**
     * Mark invoice as canceled.
     */
    public function markCanceled(string $invoiceUuid): bool;

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
    public function list(array $filters = []): array;

    /**
     * Paginate invoices with optional filters.
     *
     * @param int $page
     * @param int $perPage
     * @param array<string,mixed> $filters
     * @return array<string,mixed> Paginated result (items + meta)
     */
    public function paginateWithFilters(int $page, int $perPage, array $filters = []): array;
}
