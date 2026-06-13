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
            // Permanent (config/programmer) error: retrying cannot supply a context.
            // Drop the job so the worker records it complete instead of re-queuing.
            error_log('[Payvia] ProcessWebhookJob dropped: requires an ApplicationContext.');
            $this->delete();
            return;
        }

        $uuid = (string) ($this->getData()['provider_event_uuid'] ?? '');
        if ($uuid === '') {
            // Permanent error: a missing identifier will never appear on retry.
            error_log('[Payvia] ProcessWebhookJob dropped: missing provider_event_uuid.');
            $this->delete();
            return;
        }

        // A genuine processing failure here must propagate so the worker retries.
        app($context, WebhookService::class)->processStored($uuid);
    }
}
