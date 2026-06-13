<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Jobs;

use Glueful\Extensions\Payvia\Jobs\ProcessWebhookJob;
use PHPUnit\Framework\TestCase;

/**
 * Permanent (config/programmer) errors must drop the job rather than throw,
 * otherwise the base worker retries it up to getMaxAttempts() times for nothing.
 *
 * The base Job::delete() only flips the deleted flag when no driver is bound
 * (the case here), so we assert the job is marked deleted and no exception
 * escapes handle(). A genuine processing failure is covered by the WebhookService
 * integration tests — that path still throws and is therefore retried.
 */
final class ProcessWebhookJobTest extends TestCase
{
    public function testDropsWhenContextIsMissing(): void
    {
        // Constructed with no ApplicationContext.
        $job = new ProcessWebhookJob(['provider_event_uuid' => 'evt_1']);

        $job->handle();

        self::assertTrue($job->isDeleted(), 'Job with no context must be dropped, not retried.');
    }

    public function testDropsWhenProviderEventUuidIsMissing(): void
    {
        // A real (final) context; the uuid-missing branch returns before the
        // context is ever dereferenced, so no container wiring is needed.
        $context = new \Glueful\Bootstrap\ApplicationContext(
            basePath: sys_get_temp_dir(),
            environment: 'testing'
        );
        $job = new ProcessWebhookJob([], $context);

        $job->handle();

        self::assertTrue($job->isDeleted(), 'Job with no provider_event_uuid must be dropped, not retried.');
    }
}
