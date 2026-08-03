<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Jobs;

use Glueful\Extensions\Payvia\Database\Migrations\AddProviderEventDispatchClaimToken;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Jobs\ProcessWebhookJob;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\FakeStrictPaymentEventListener;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Permanent (config/programmer) errors must drop the job rather than throw,
 * otherwise the base worker retries it up to getMaxAttempts() times for nothing.
 *
 * The base Job::delete() only flips the deleted flag when no driver is bound
 * (the case here), so we assert the job is marked deleted and no exception
 * escapes handle(). A genuine processing failure is covered by the WebhookService
 * integration tests — that path still throws and is therefore retried; the
 * queue-path fail-once/retry-completes case below drives that same genuine
 * failure through the real `ProcessWebhookJob::handle()` entry point.
 */
final class ProcessWebhookJobTest extends PayviaTestCase
{
    private ProviderEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateProviderEventsTable());
        $this->runMigration(new AddProviderEventDispatchClaimToken());
        $this->events = new ProviderEventRepository($this->connection);
    }

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

    /**
     * Queue path: `ProcessWebhookJob::handle()` resolves a REAL `WebhookService` (composed with
     * the tagged strict lane, exactly like `PayviaServiceProvider::makeWebhookService()`) from the
     * container and calls `processStored()` on it. A strict listener that fails on its first call
     * must let that failure escape `handle()` uncaught (so the worker retries the job); calling
     * `handle()` again -- a fresh job instance, mirroring a queue-driven retry -- must then
     * complete the SAME provider_events row exactly once.
     */
    public function testFailOnceStrictListenerThrowsOnFirstHandleThenRetryCompletes(): void
    {
        $listener = new FakeStrictPaymentEventListener(failFirstN: 1);
        $strict = PayviaServiceProvider::composeStrictLane([$listener]);
        $dispatcher = static function (PaymentProviderEvent $event) use ($strict): void {
            foreach ($strict as $l) {
                if ($l->supports($event->event)) {
                    $l->handle($event->event);
                }
            }
        };

        $webhookService = new WebhookService(
            $this->context,
            new GatewayManager($this->context->getContainer(), $this->context),
            $this->events,
            $dispatcher,
            null,
            false,
            null,
            $this->events,
        );
        $this->bind(WebhookService::class, $webhookService);

        $uuid = $this->events->insertReceived([
            'gateway' => 'fake',
            'source' => 'webhook',
            'delivery_key' => 'delivery-job-1',
            'logical_event_key' => 'payment.succeeded:REF_JOB',
            'type' => EventType::PAYMENT_SUCCEEDED,
            'signature_valid' => true,
            'normalized_payload' => ['reference' => 'REF_JOB'],
            'raw_payload' => [],
        ]);
        self::assertNotNull($uuid);
        $this->events->markProcessed($uuid);

        $firstAttempt = new ProcessWebhookJob(['provider_event_uuid' => $uuid], $this->context);
        try {
            $firstAttempt->handle();
            self::fail('expected the strict listener exception to escape handle()');
        } catch (\RuntimeException) {
        }
        self::assertFalse($firstAttempt->isDeleted(), 'a genuine failure must be retried, not dropped');

        $retry = new ProcessWebhookJob(['provider_event_uuid' => $uuid], $this->context);
        $retry->handle();

        self::assertSame(2, $listener->callCount());
        $row = $this->events->findByUuid($uuid);
        self::assertNotNull($row);
        self::assertSame('dispatched', $row['dispatch_status']);
    }
}
