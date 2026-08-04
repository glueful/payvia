<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Webhooks;

use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\ProviderEventPayloadUpdaterInterface;
use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Task 6: `WebhookService::processStored()`'s applier callable may now return a replacement
 * `PaymentProviderEventInterface` (built via `ProviderEvent::withNormalized()`) carrying
 * enrichment discovered while applying the event. Drives the REAL `ProviderEventRepository`
 * (which now also implements the additive `ProviderEventPayloadUpdaterInterface` capability)
 * against a real sqlite `Connection`, with a hand-built test applier double -- no consumer of
 * this plumbing exists yet (Task 7 wires correlation into `GatewaySubscriptionService`).
 */
final class EventReplacementTest extends PayviaTestCase
{
    private ProviderEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateProviderEventsTable());
        $this->events = new ProviderEventRepository($this->connection);
    }

    private function gatewayManager(): GatewayManager
    {
        return new GatewayManager($this->context->getContainer(), $this->context);
    }

    /** @return array<string,mixed> */
    private function row(string $deliveryKey = 'd1', string $entityId = 'R1'): array
    {
        return [
            'gateway' => 'paystack',
            'source' => 'webhook',
            'provider_event_id' => null,
            'delivery_key' => $deliveryKey,
            'logical_event_key' => EventType::PAYMENT_SUCCEEDED . ':' . $entityId,
            'type' => EventType::PAYMENT_SUCCEEDED,
            'signature_valid' => true,
            'normalized_payload' => ['reference' => $entityId, 'status' => 'success'],
            'raw_payload' => ['raw' => true],
        ];
    }

    /**
     * An applier double that appends `enriched => true` to the normalized payload via
     * `ProviderEvent::withNormalized()` -- exactly the shape a real correlation-enriching
     * applier (Task 7) would return.
     *
     * @return callable(PaymentProviderEventInterface):?PaymentProviderEventInterface
     */
    private function enrichingApplier(): callable
    {
        return static function (PaymentProviderEventInterface $event): ?PaymentProviderEventInterface {
            self::assertInstanceOf(ProviderEvent::class, $event);

            return $event->withNormalized(array_merge($event->normalized(), ['enriched' => true]));
        };
    }

    private function strictSpy(): object
    {
        return new class implements StrictPaymentEventListener {
            /** @var list<array<string,mixed>> */
            public array $seen = [];

            public function supports(PaymentProviderEventInterface $event): bool
            {
                return true;
            }

            public function handle(PaymentProviderEventInterface $event): void
            {
                $this->seen[] = $event->normalized();
            }
        };
    }

    /** @param StrictPaymentEventListener $spy */
    private function dispatcherToSpy(object $spy): callable
    {
        return static function (PaymentProviderEvent $event) use ($spy): void {
            if ($spy->supports($event->event)) {
                $spy->handle($event->event);
            }
        };
    }

    public function testReplacementNormalizedPayloadIsPersistedBeforeMarkProcessed(): void
    {
        $uuid = $this->events->insertReceived($this->row('d-order-1', 'R-order-1'));
        self::assertNotNull($uuid);

        $recorder = new OrderRecordingProviderEvents($this->events);
        $service = new WebhookService(
            context: $this->context,
            gateways: $this->gatewayManager(),
            events: $recorder,
            applier: $this->enrichingApplier(),
            payloadUpdater: $recorder,
        );

        $service->processStored($uuid);

        self::assertSame(['replaceNormalizedPayload', 'markProcessed'], $recorder->order);

        $stored = $this->events->findByUuid($uuid);
        self::assertNotNull($stored);
        self::assertSame('processed', $stored['status']);
        $persisted = json_decode((string) $stored['normalized_payload'], true);
        self::assertTrue($persisted['enriched'] ?? false, 'the enriched payload must be durably persisted');
    }

    public function testFirstDeliveryDispatchesTheEnrichedObjectToAStrictListenerSpy(): void
    {
        $uuid = $this->events->insertReceived($this->row('d-first-1', 'R-first-1'));
        self::assertNotNull($uuid);

        $spy = $this->strictSpy();
        $service = new WebhookService(
            context: $this->context,
            gateways: $this->gatewayManager(),
            events: $this->events,
            dispatcher: $this->dispatcherToSpy($spy),
            applier: $this->enrichingApplier(),
            payloadUpdater: $this->events,
        );

        $service->processStored($uuid);

        self::assertCount(1, $spy->seen, 'the strict listener must see the event on THIS first delivery');
        self::assertTrue(
            $spy->seen[0]['enriched'] ?? false,
            'a test that only sees enrichment on retry, not on this first dispatch, is a failure (spec sec 3.4)'
        );
    }

    public function testRetryReconstructsTheIdenticalEnrichedEventFromStorageWithoutReapplying(): void
    {
        $uuid = $this->events->insertReceived($this->row('d-retry-1', 'R-retry-1'));
        self::assertNotNull($uuid);

        $applierCalls = 0;
        $applier = function (PaymentProviderEventInterface $event) use (&$applierCalls): ?PaymentProviderEventInterface {
            $applierCalls++;
            self::assertInstanceOf(ProviderEvent::class, $event);

            return $event->withNormalized(array_merge($event->normalized(), ['enriched' => true]));
        };

        // First delivery's dispatch throws -- processing (apply + persist) already succeeded,
        // so the row stays status='processed' but dispatch_status never reaches 'dispatched'.
        $failingDispatcher = static function (PaymentProviderEvent $event): void {
            throw new \RuntimeException('simulated dispatch-phase failure');
        };

        $service = new WebhookService(
            context: $this->context,
            gateways: $this->gatewayManager(),
            events: $this->events,
            dispatcher: $failingDispatcher,
            applier: $applier,
            payloadUpdater: $this->events,
        );

        try {
            $service->processStored($uuid);
            self::fail('expected the simulated dispatch failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated dispatch-phase failure', $e->getMessage());
        }

        self::assertSame(1, $applierCalls);
        $afterFirstAttempt = $this->events->findByUuid($uuid);
        self::assertNotNull($afterFirstAttempt);
        self::assertSame('processed', $afterFirstAttempt['status']);
        self::assertNotSame('dispatched', $afterFirstAttempt['dispatch_status']);

        // Backdate the first attempt's claim so the retry's default staleSeconds:300 window
        // deterministically reclaims it, rather than depending on 300 real seconds elapsing --
        // mirrors DisputeWebhookDispatchTest's identical backdating pattern.
        $this->connection->table('provider_events')
            ->where(['uuid' => $uuid])
            ->update([
                'dispatch_claimed_at' => $this->connection->getDriver()
                    ->formatDateTime((new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s')),
            ]);

        // Retry: a spying dispatcher this time, no more throw. Since the row is already
        // status='processed', processStored() takes the early-return reconstruct-and-redispatch
        // branch -- the applier must NOT run again, yet the dispatched event must still carry
        // the enrichment, because it was durably persisted on the first attempt.
        $spy = $this->strictSpy();
        $retryService = new WebhookService(
            context: $this->context,
            gateways: $this->gatewayManager(),
            events: $this->events,
            dispatcher: $this->dispatcherToSpy($spy),
            applier: $applier,
            payloadUpdater: $this->events,
        );

        $retryService->processStored($uuid);

        self::assertSame(1, $applierCalls, 'a retry via the already-processed branch must never re-run the applier');
        self::assertCount(1, $spy->seen);
        self::assertTrue($spy->seen[0]['enriched'] ?? false, 'retry must reconstruct the SAME enriched event');

        $afterRetry = $this->events->findByUuid($uuid);
        self::assertNotNull($afterRetry);
        self::assertSame('dispatched', $afterRetry['dispatch_status']);
    }

    public function testMissingUpdaterBindingFailsClosedAndLeavesTheEventRetryable(): void
    {
        $uuid = $this->events->insertReceived($this->row('d-missing-updater-1', 'R-missing-updater-1'));
        self::assertNotNull($uuid);

        $spy = $this->strictSpy();
        $service = new WebhookService(
            context: $this->context,
            gateways: $this->gatewayManager(),
            events: $this->events,
            dispatcher: $this->dispatcherToSpy($spy),
            applier: $this->enrichingApplier(),
            // No payloadUpdater bound at all: a replacement with a changed normalized payload
            // must fail closed rather than silently dispatching stale (or fabricated) metadata.
        );

        try {
            $service->processStored($uuid);
            self::fail('expected fail-closed to rethrow when no updater is bound');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(ProviderEventPayloadUpdaterInterface::class, $e->getMessage());
        }

        self::assertSame([], $spy->seen, 'nothing may ever be dispatched once enrichment could not be persisted');

        $stored = $this->events->findByUuid($uuid);
        self::assertNotNull($stored);
        self::assertSame('failed', $stored['status']);
        $persisted = json_decode((string) $stored['normalized_payload'], true);
        self::assertArrayNotHasKey(
            'enriched',
            $persisted,
            'the original stored payload must be untouched when persistence never happened'
        );

        // Retryable: a later attempt (e.g. with the updater now bound) succeeds normally.
        $recovered = new WebhookService(
            context: $this->context,
            gateways: $this->gatewayManager(),
            events: $this->events,
            dispatcher: $this->dispatcherToSpy($spy),
            applier: $this->enrichingApplier(),
            payloadUpdater: $this->events,
        );
        $recovered->processStored($uuid);

        self::assertCount(1, $spy->seen);
        self::assertTrue($spy->seen[0]['enriched'] ?? false);
        $final = $this->events->findByUuid($uuid);
        self::assertNotNull($final);
        self::assertSame('processed', $final['status']);
    }

    public function testPersistenceFailureAlsoFailsClosedAndLeavesTheEventRetryable(): void
    {
        $uuid = $this->events->insertReceived($this->row('d-persist-fail-1', 'R-persist-fail-1'));
        self::assertNotNull($uuid);

        $updater = new class implements ProviderEventPayloadUpdaterInterface {
            public function replaceNormalizedPayload(string $uuid, array $normalized): void
            {
                throw new \RuntimeException('simulated persistence failure');
            }
        };

        $spy = $this->strictSpy();
        $service = new WebhookService(
            context: $this->context,
            gateways: $this->gatewayManager(),
            events: $this->events,
            dispatcher: $this->dispatcherToSpy($spy),
            applier: $this->enrichingApplier(),
            payloadUpdater: $updater,
        );

        try {
            $service->processStored($uuid);
            self::fail('expected the persistence failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated persistence failure', $e->getMessage());
        }

        self::assertSame([], $spy->seen);
        $stored = $this->events->findByUuid($uuid);
        self::assertNotNull($stored);
        self::assertSame('failed', $stored['status']);
    }

    /**
     * Regression guard: a legacy void-returning applier (implicit `null` return) changes
     * NOTHING -- byte-identical to pre-Task-6 behavior. No updater is even invoked.
     */
    public function testVoidApplierLeavesTheStoredEventByteIdenticalRegressionGuard(): void
    {
        $uuid = $this->events->insertReceived($this->row('d-void-1', 'R-void-1'));
        self::assertNotNull($uuid);

        $recorder = new OrderRecordingProviderEvents($this->events);
        $spy = $this->strictSpy();
        $voidApplier = static function (PaymentProviderEventInterface $event): void {
            // Legacy applier: no return value at all (implicit null).
        };

        $service = new WebhookService(
            context: $this->context,
            gateways: $this->gatewayManager(),
            events: $recorder,
            dispatcher: $this->dispatcherToSpy($spy),
            applier: $voidApplier,
            payloadUpdater: $recorder,
        );

        $service->processStored($uuid);

        self::assertSame(
            ['markProcessed'],
            $recorder->order,
            'replaceNormalizedPayload() must never be called for a void/null-returning applier'
        );
        self::assertCount(1, $spy->seen);
        self::assertSame(['reference' => 'R-void-1', 'status' => 'success'], $spy->seen[0]);

        $stored = $this->events->findByUuid($uuid);
        self::assertNotNull($stored);
        $persisted = json_decode((string) $stored['normalized_payload'], true);
        self::assertSame(['reference' => 'R-void-1', 'status' => 'success'], $persisted);
    }
}

/**
 * Delegates every `ProviderEventRepositoryInterface` call to a real repository, recording ONLY
 * `replaceNormalizedPayload()` and `markProcessed()` into a shared ordered log -- proving the
 * PERSIST-THEN-MARK ordering `WebhookService::processStored()` must uphold, rather than merely
 * asserting the end state both calls would leave behind regardless of order.
 */
final class OrderRecordingProviderEvents implements
    ProviderEventRepositoryInterface,
    ProviderEventPayloadUpdaterInterface
{
    /** @var list<string> */
    public array $order = [];

    public function __construct(private ProviderEventRepository $inner)
    {
    }

    public function replaceNormalizedPayload(string $uuid, array $normalized): void
    {
        $this->order[] = 'replaceNormalizedPayload';
        $this->inner->replaceNormalizedPayload($uuid, $normalized);
    }

    public function markProcessed(string $uuid): void
    {
        $this->order[] = 'markProcessed';
        $this->inner->markProcessed($uuid);
    }

    public function findByDeliveryKey(string $gateway, string $deliveryKey): ?array
    {
        return $this->inner->findByDeliveryKey($gateway, $deliveryKey);
    }

    public function insertReceived(array $data): ?string
    {
        return $this->inner->insertReceived($data);
    }

    public function markProcessing(string $uuid): void
    {
        $this->inner->markProcessing($uuid);
    }

    public function markFailed(string $uuid, string $error): void
    {
        $this->inner->markFailed($uuid, $error);
    }

    public function incrementAttempts(string $uuid): void
    {
        $this->inner->incrementAttempts($uuid);
    }

    public function isLogicalDispatched(string $gateway, string $logicalEventKey): bool
    {
        return $this->inner->isLogicalDispatched($gateway, $logicalEventKey);
    }

    public function claimLogicalForDispatch(string $gateway, string $logicalEventKey): int
    {
        return $this->inner->claimLogicalForDispatch($gateway, $logicalEventKey);
    }

    public function reclaimStaleDispatching(string $gateway, string $logicalEventKey, int $staleSeconds): int
    {
        return $this->inner->reclaimStaleDispatching($gateway, $logicalEventKey, $staleSeconds);
    }

    public function markLogicalDispatched(string $gateway, string $logicalEventKey): void
    {
        $this->inner->markLogicalDispatched($gateway, $logicalEventKey);
    }

    public function markDispatched(string $uuid): void
    {
        $this->inner->markDispatched($uuid);
    }

    public function findDispatchable(int $limit = 100, int $staleSeconds = 300): array
    {
        return $this->inner->findDispatchable($limit, $staleSeconds);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->inner->findByUuid($uuid);
    }
}
