<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

interface PaymentGatewayInterface
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function verify(string $reference, array $options = []): array;
}
