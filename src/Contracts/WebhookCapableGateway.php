<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

interface WebhookCapableGateway
{
    /** @param array<string,mixed> $headers */
    public function verifyWebhookSignature(string $rawBody, array $headers): bool;

    /** @param array<string,mixed> $headers */
    public function parseWebhookEvent(string $rawBody, array $headers): PaymentProviderEventInterface;
}
