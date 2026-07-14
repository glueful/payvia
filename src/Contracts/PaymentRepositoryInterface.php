<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

use Glueful\Bootstrap\ApplicationContext;

interface PaymentRepositoryInterface
{
    public function getTableName(): string;

    /** @param array<string,mixed> $data */
    public function createPayment(ApplicationContext $context, array $data): string;

    /** @return array<string,mixed>|null */
    public function findByReference(ApplicationContext $context, string $reference): ?array;

    /** @param array<string,mixed> $data */
    public function updateByReference(ApplicationContext $context, string $reference, array $data): bool;
}
