<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

interface PaymentProviderEventInterface
{
    public function gateway(): string;

    public function type(): string;

    public function providerEventId(): ?string;

    public function deliveryKey(): string;

    public function logicalEventKey(): string;

    public function occurredAt(): \DateTimeImmutable;

    /** @return array<string,mixed> */
    public function normalized(): array;

    /** @return array<string,mixed> */
    public function raw(): array;
}
