<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

interface SubscriptionCapableGateway
{
    /** @return array<string,mixed> */
    public function fetchSubscription(string $gatewaySubscriptionId): array;

    /** @return array<string,mixed> */
    public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): array;
}
