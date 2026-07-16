<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\InvoiceRepositoryInterface;

/**
 * Invoice Service
 *
 * Thin application service over the invoices repository for creating
 * and updating invoices. Intended to be composed with PaymentService
 * in the host application to implement full billing flows.
 */
final class InvoiceService
{
    public function __construct(
        private InvoiceRepositoryInterface $invoices,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(ApplicationContext $context, array $data): string
    {
        return $this->invoices->createInvoice($context, $data);
    }

    public function markPaid(
        ApplicationContext $context,
        string $invoiceUuid,
        ?\DateTimeImmutable $paidAt = null
    ): bool {
        return $this->invoices->markPaid($context, $invoiceUuid, $paidAt);
    }

    public function markCanceled(ApplicationContext $context, string $invoiceUuid): bool
    {
        return $this->invoices->markCanceled($context, $invoiceUuid);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed> Paginated payload (items + meta)
     */
    public function list(ApplicationContext $context, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->invoices->paginateWithFilters($context, $page, $perPage, $filters);
    }
}
