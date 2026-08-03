<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Contracts\LogicalDispatchLeaseRepositoryInterface;
use Glueful\Extensions\Payvia\Database\Migrations\AddProviderEventDispatchClaimToken;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Task 2: lease-based release-on-failure in `WebhookService::dispatch()`. Drives a REAL
 * `WebhookService` + `ProviderEventRepository` -- which also implements
 * {@see LogicalDispatchLeaseRepositoryInterface} and is wired here as the optional final lease
 * capability, exactly like `PayviaServiceProvider::makeWebhookService()` does in production --
 * with a plain throwing dispatcher standing in for the strict payment-event lane that doesn't
 * exist until Task 3 (this file is extended there).
 */
final class StrictDispatchFailureTest extends PayviaTestCase
{
    private ProviderEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateProviderEventsTable());
        $this->runMigration(new AddProviderEventDispatchClaimToken());
        $this->events = new ProviderEventRepository($this->connection);
        $this->bind(FakeWebhookGateway::class, new FakeWebhookGateway());
    }

    private function manager(): GatewayManager
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);

        return $manager;
    }

    /** @param callable(PaymentProviderEvent):void $dispatcher */
    private function serviceWithDispatcher(
        callable $dispatcher,
        ?LogicalDispatchLeaseRepositoryInterface $leases = null,
    ): WebhookService {
        return new WebhookService(
            $this->context,
            $this->manager(),
            $this->events,
            $dispatcher,
            null,
            false,
            null,
            $leases ?? $this->events,
        );
    }

    private function insertReceivedEvent(string $ref = 'REF_1'): string
    {
        $uuid = $this->events->insertReceived([
            'gateway' => 'fake',
            'source' => 'webhook',
            'delivery_key' => 'delivery-' . $ref,
            'logical_event_key' => 'payment.succeeded:' . $ref,
            'type' => EventType::PAYMENT_SUCCEEDED,
            'signature_valid' => true,
            'normalized_payload' => ['reference' => $ref],
            'raw_payload' => [],
        ]);
        self::assertNotNull($uuid);
        $this->events->markProcessed($uuid);

        return $uuid;
    }

    /**
     * Queue mode: `ingest()` only enqueues the uuid and never calls `processStored()` inline, so
     * the throwing dispatcher never runs during `ingest()` itself -- required here because an
     * inline-throwing `ingest()` would let the exception escape before a uuid could ever be
     * returned to the test.
     *
     * @param callable(PaymentProviderEvent):void $dispatcher
     * @return array{0: WebhookService, 1: string}
     */
    private function queuedEventWithDispatcher(callable $dispatcher, string $ref = 'REF_1'): array
    {
        $queued = [];
        $service = new WebhookService(
            $this->context,
            $this->manager(),
            $this->events,
            $dispatcher,
            null,
            true,
            static function (string $uuid) use (&$queued): void {
                $queued[] = $uuid;
            },
            $this->events,
        );

        $body = json_encode([
            'type' => EventType::PAYMENT_SUCCEEDED,
            'entity_id' => $ref,
            'delivery_key' => 'delivery-' . $ref,
            'normalized' => ['reference' => $ref],
        ], JSON_THROW_ON_ERROR);

        $result = $service->ingest('fake', $body);
        self::assertSame(202, $result->httpStatus);
        self::assertCount(1, $queued);

        return [$service, $queued[0]];
    }

    private function dispatchStatusFor(string $uuid): ?string
    {
        $row = $this->events->findByUuid($uuid);

        return $row !== null ? (string) $row['dispatch_status'] : null;
    }

    public function testDispatchFailureReleasesTheClaimAndRethrows(): void
    {
        $boom = new \RuntimeException('listener exploded');
        [$service, $uuid] = $this->queuedEventWithDispatcher(
            function () use ($boom): void {
                throw $boom;
            }
        );

        try {
            $service->processStored($uuid);
            self::fail('expected the dispatcher exception to escape');
        } catch (\RuntimeException $e) {
            self::assertSame($boom, $e); // ORIGINAL exception, not a wrapper
        }
        self::assertSame('pending', $this->dispatchStatusFor($uuid)); // released, NOT 'dispatching'
    }

    public function testImmediateRetryAfterFailureInvokesTheDispatcherAgainAndCompletes(): void
    {
        $calls = 0;
        $service = $this->serviceWithDispatcher(function () use (&$calls): void {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('fail once');
            }
        });
        $uuid = $this->insertReceivedEvent();

        try {
            $service->processStored($uuid);
        } catch (\RuntimeException) {
        }
        $service->processStored($uuid); // IMMEDIATE retry — no clock manipulation

        self::assertSame(2, $calls);
        self::assertSame('dispatched', $this->dispatchStatusFor($uuid));
    }

    public function testReleaseFailureLogsAndRethrowsTheOriginalException(): void
    {
        $boom = new \RuntimeException('listener exploded');
        $releaseFailure = new \RuntimeException('release backend unavailable');

        // Lease-capability double: acquires successfully, but the release call itself throws.
        $leases = new class ($releaseFailure) implements LogicalDispatchLeaseRepositoryInterface {
            public function __construct(private \Throwable $releaseFailure)
            {
            }

            public function acquireLogicalDispatchLease(
                string $gateway,
                string $logicalEventKey,
                int $staleSeconds = 300,
            ): ?string {
                return 'lease-token-1';
            }

            public function completeLogicalDispatch(
                string $gateway,
                string $logicalEventKey,
                string $leaseToken,
            ): bool {
                throw new \LogicException('completeLogicalDispatch must never be called on a failed dispatch');
            }

            public function releaseLogicalDispatch(
                string $gateway,
                string $logicalEventKey,
                string $leaseToken,
            ): bool {
                throw $this->releaseFailure;
            }
        };

        $service = $this->serviceWithDispatcher(function () use ($boom): void {
            throw $boom;
        }, $leases);
        $uuid = $this->insertReceivedEvent('REF_RELEASE_FAIL');

        $tmp = (string) tempnam(sys_get_temp_dir(), 'payvia-error-log-');
        $previous = ini_set('error_log', $tmp);
        self::assertNotFalse($previous);

        try {
            try {
                $service->processStored($uuid);
                self::fail('expected the ORIGINAL dispatcher exception to escape');
            } catch (\RuntimeException $e) {
                self::assertSame($boom, $e); // original exception surfaces by identity
            }

            $logged = (string) file_get_contents($tmp);
            self::assertStringContainsString('release backend unavailable', $logged);
            self::assertStringContainsString('fake', $logged);
            self::assertStringContainsString('payment.succeeded:REF_RELEASE_FAIL', $logged);
        } finally {
            ini_set('error_log', $previous);
            @unlink($tmp);
        }
    }
}
