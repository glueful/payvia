<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Contracts\LogicalDispatchLeaseRepositoryInterface;
use Glueful\Extensions\Payvia\Database\Migrations\AddProviderEventDispatchClaimToken;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\FakeStrictPaymentEventListener;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Task 2: lease-based release-on-failure in `WebhookService::dispatch()`. Drives a REAL
 * `WebhookService` + `ProviderEventRepository` -- which also implements
 * {@see LogicalDispatchLeaseRepositoryInterface} and is wired here as the optional final lease
 * capability, exactly like `PayviaServiceProvider::makeWebhookService()` does in production --
 * with a plain throwing dispatcher standing in for the strict payment-event lane that doesn't
 * exist until Task 3 (this file is extended there).
 *
 * Task 3 extension: the tests below exercise the REAL tagged strict lane, composed via
 * {@see PayviaServiceProvider::composeStrictLane()} exactly like `makeWebhookService()` does, in
 * place of the plain throwing stand-in used above.
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

    /**
     * Mirrors `PayviaServiceProvider::makeWebhookService()`'s composition order for the piece
     * under test here -- ordinary delivery first, then the tagged strict lane composed through
     * the SAME `composeStrictLane()` production uses -- minus the chargeback dispatcher, which is
     * unrelated to these tests and would require its own repository/dependency wiring.
     *
     * @param list<mixed> $tagged
     * @param callable(PaymentProviderEvent):void $ordinary
     */
    private function strictComposedDispatcher(array $tagged, callable $ordinary): callable
    {
        $strict = PayviaServiceProvider::composeStrictLane($tagged);

        return static function (PaymentProviderEvent $event) use ($ordinary, $strict): void {
            $ordinary($event);
            foreach ($strict as $listener) {
                if ($listener->supports($event->event)) {
                    $listener->handle($event->event);
                }
            }
        };
    }

    /**
     * Queue disabled: `ingest()` calls `processStored()` inline, synchronously, exactly like the
     * real `WebhookController` does in production -- so a dispatch-phase failure propagates
     * straight out of `ingest()` itself, uncaught, just as it would out of the controller.
     *
     * @param callable(PaymentProviderEvent):void $dispatcher
     */
    private function inlineService(callable $dispatcher): WebhookService
    {
        return new WebhookService(
            $this->context,
            $this->manager(),
            $this->events,
            $dispatcher,
            null,
            false,
            null,
            $this->events,
        );
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

    public function testTaggedFailOnceStrictListenerReleasesTheLeaseThenImmediateRetryCompletesExactlyOnce(): void
    {
        $listener = new FakeStrictPaymentEventListener(failFirstN: 1);
        $service = $this->serviceWithDispatcher(
            $this->strictComposedDispatcher([$listener], static function (): void {
            })
        );
        $uuid = $this->insertReceivedEvent('REF_STRICT_FAIL_ONCE');

        try {
            $service->processStored($uuid);
            self::fail('expected the strict listener exception to escape');
        } catch (\RuntimeException) {
        }
        self::assertSame('pending', $this->dispatchStatusFor($uuid)); // released, not stuck

        $service->processStored($uuid); // immediate retry — no clock manipulation

        self::assertSame(2, $listener->callCount());
        self::assertCount(1, $listener->handled); // one successful handle(), not two
        self::assertSame('dispatched', $this->dispatchStatusFor($uuid));
    }

    public function testSupportsFalseStrictListenerIsNeverInvoked(): void
    {
        $listener = new FakeStrictPaymentEventListener(supportsResult: false);
        $service = $this->serviceWithDispatcher(
            $this->strictComposedDispatcher([$listener], static function (): void {
            })
        );
        $uuid = $this->insertReceivedEvent('REF_STRICT_UNSUPPORTED');

        $service->processStored($uuid);

        self::assertSame(0, $listener->callCount());
        self::assertSame([], $listener->handled);
        self::assertSame('dispatched', $this->dispatchStatusFor($uuid));
    }

    public function testEmptyStrictLaneIsBehaviorallyIdenticalToAPreLaneService(): void
    {
        $callsWithoutLane = [];
        $callsWithEmptyLane = [];
        $recorder = static function (array &$calls): callable {
            return static function (PaymentProviderEvent $event) use (&$calls): void {
                $calls[] = $event->event->logicalEventKey();
            };
        };

        $preLaneService = $this->serviceWithDispatcher($recorder($callsWithoutLane));
        $emptyLaneService = $this->serviceWithDispatcher(
            $this->strictComposedDispatcher([], $recorder($callsWithEmptyLane))
        );

        $preLaneUuid = $this->insertReceivedEvent('REF_NO_TAG_A');
        $emptyLaneUuid = $this->insertReceivedEvent('REF_NO_TAG_B');

        $preLaneService->processStored($preLaneUuid);
        $emptyLaneService->processStored($emptyLaneUuid);

        self::assertSame(['payment.succeeded:REF_NO_TAG_A'], $callsWithoutLane);
        self::assertSame(['payment.succeeded:REF_NO_TAG_B'], $callsWithEmptyLane);
        self::assertSame('dispatched', $this->dispatchStatusFor($preLaneUuid));
        self::assertSame('dispatched', $this->dispatchStatusFor($emptyLaneUuid));
    }

    public function testFirstStrictFailureProducesAnErrorAndTheSecondDeliveryOfTheSamePayloadExecutes(): void
    {
        $listener = new FakeStrictPaymentEventListener(failFirstN: 1);
        $service = $this->inlineService(
            $this->strictComposedDispatcher([$listener], static function (): void {
            })
        );

        $body = json_encode([
            'type' => EventType::PAYMENT_SUCCEEDED,
            'entity_id' => 'REF_INLINE',
            'delivery_key' => 'delivery-inline',
            'normalized' => ['reference' => 'REF_INLINE'],
        ], JSON_THROW_ON_ERROR);

        // First delivery: ingest() calls processStored() inline (queue disabled), so the strict
        // listener's failure propagates straight out of ingest() itself, uncaught -- exactly
        // what a real WebhookController::handle() call would let escape into the framework's
        // own error response, since the controller never catches it either.
        try {
            $service->ingest('fake', $body);
            self::fail('expected the strict listener exception to escape ingest()');
        } catch (\RuntimeException) {
        }

        // Second delivery of the IDENTICAL payload: insertReceived() sees the same delivery_key
        // and returns null, but findByDeliveryKey() resolves the SAME uuid recorded on the first
        // delivery, so ingest() must actually re-run processStored() on it (and this time
        // succeed) rather than falling through to the "duplicate" 200 short-circuit that only
        // fires when no stored row can be found at all.
        $result = $service->ingest('fake', $body);

        self::assertTrue($result->accepted);
        self::assertSame(200, $result->httpStatus);
        self::assertNotSame('duplicate', $result->message);
        self::assertSame(2, $listener->callCount());
        $stored = $this->events->findByDeliveryKey('fake', 'delivery-inline');
        self::assertNotNull($stored);
        self::assertSame('dispatched', $stored['dispatch_status']);
    }
}
