<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Jobs;

use Glueful\Queue\Job;
use Glueful\Extensions\Payvia\Services\WebhookService;

final class ProcessWebhookJob extends Job
{
    public function handle(): void
    {
        $context = $this->context;
        if ($context === null) {
            throw new \RuntimeException('ProcessWebhookJob requires an ApplicationContext.');
        }

        $uuid = (string) ($this->getData()['provider_event_uuid'] ?? '');
        if ($uuid === '') {
            throw new \RuntimeException('ProcessWebhookJob missing provider_event_uuid.');
        }

        app($context, WebhookService::class)->processStored($uuid);
    }
}
