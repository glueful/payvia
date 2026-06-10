<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

interface GatewaySubscriptionRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findByGatewaySubscription(string $gateway, string $gatewaySubscriptionId): ?array;

    /** @param array<string,mixed> $data */
    public function upsertByGatewayId(array $data): string;
}
