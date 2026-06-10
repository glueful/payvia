<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

final class WebhookIngestResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly int $httpStatus,
        public readonly ?string $providerEventUuid = null,
        public readonly string $message = 'ok',
    ) {
    }
}
