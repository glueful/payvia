<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

interface PaymentRepositoryInterface
{
    public function getTableName(): string;

    /** @param array<string,mixed> $data */
    public function createPayment(array $data): string;

    /** @return array<string,mixed>|null */
    public function findByReference(string $reference): ?array;

    /** @param array<string,mixed> $data */
    public function updateByReference(string $reference, array $data): bool;
}
