<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Webhooks;

use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\Events\ProviderChargebackDispatcher;
use Glueful\Extensions\Payvia\Exceptions\MalformedChargebackEventException;
use Glueful\Extensions\Payvia\Exceptions\UnresolvedPaymentOwnershipException;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Glueful\Http\Client as HttpClient;

/**
 * Task 3: recognize provider dispute/chargeback webhooks and dispatch a
 * `ProviderChargebackEvent` built from the fail-closed payment-owner correlation (Task 2),
 * reusing WebhookService's existing durable `provider_events` + redelivery.
 *
 * Drives the REAL `WebhookService` + `StripeGateway`/`PaystackGateway` +
 * `ProviderCorrelationRepository` + `ProviderChargebackDispatcher` stack -- the same wiring
 * `PayviaServiceProvider::makeWebhookService()`/`makeProviderChargebackDispatcher()` compose in
 * production -- with local `PaymentProviderEvent` delivery and the contracts
 * `ProviderChargebackEvent` dispatch both captured as plain in-memory collectors instead of the
 * real `EventService`.
 */
final class DisputeWebhookDispatchTest extends PayviaTestCase
{
    private ProviderEventRepository $events;
    private ProviderCorrelationRepository $correlation;

    /** @var list<PaymentProviderEvent> */
    private array $delivered = [];

    /** @var list<ProviderChargebackEvent> */
    private array $chargebacks = [];

    /** @var null|callable(ProviderChargebackEvent):void */
    private $chargebackDispatch = null;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = $this->connection->getSchemaBuilder();
        (new CreateProviderEventsTable())->up($schema);
        (new CreatePaymentsTable())->up($schema);

        $this->events = new ProviderEventRepository($this->connection);
        $this->correlation = new ProviderCorrelationRepository($this->connection);
        $this->delivered = [];
        $this->chargebacks = [];

        // The gateways read config via the global config() helper -> ApplicationContext::
        // getConfig(), which requires a real ConfigurationLoader (PayviaTestCase's own
        // setConfig()/container 'config' id is never consulted by production gateway code) --
        // mirrors the exact setup StripeWebhookSignatureTest/PaystackWebhookSignatureTest use.
        $base = sys_get_temp_dir() . '/payvia-dispute-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'stripe' => [
                    'secret_key' => 'sk_test',
                    'webhook_secret' => 'whsec_test',
                    'webhook_tolerance' => 300,
                    'base_url' => 'https://api.stripe.com',
                    'timeout' => 15,
                ],
                'paystack' => [
                    'secret_key' => 'sk_paystack_test',
                    'webhook_secret' => 'paystack_whsec_test',
                    'base_url' => 'https://api.paystack.co',
                    'timeout' => 15,
                ],
            ],
        ], true) . ";\n");
        $this->context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        $this->bind(StripeGateway::class, new StripeGateway($this->createMock(HttpClient::class), $this->context));
        $this->bind(PaystackGateway::class, new PaystackGateway($this->createMock(HttpClient::class), $this->context));
    }

    /** @param array<string,mixed> $overrides */
    private function insertPayment(array $overrides = []): void
    {
        $this->connection->table('payments')->insert(array_merge([
            'uuid' => 'payAAAAAAAA1',
            'tenant_uuid' => 'tenantAAAA01',
            'gateway' => 'stripe',
            'gateway_transaction_id' => 'pi_123',
            'reference' => 'refAAAAAAAA1',
            'payable_type' => 'order',
            'payable_id' => 'order_1',
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'succeeded',
        ], $overrides));
    }

    private function service(): WebhookService
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);

        $dispatch = $this->chargebackDispatch ?? function (ProviderChargebackEvent $event): void {
            $this->chargebacks[] = $event;
        };
        $chargebackDispatcher = new ProviderChargebackDispatcher($this->correlation, $dispatch);

        // Mirrors PayviaServiceProvider::makeWebhookService() exactly: local delivery FIRST,
        // then delegate to the chargeback dispatcher. Nothing here catches either half.
        $dispatcher = function (PaymentProviderEvent $event) use ($chargebackDispatcher): void {
            $this->delivered[] = $event;
            $chargebackDispatcher->handle($event->event);
        };

        return new WebhookService($this->context, $manager, $this->events, $dispatcher);
    }

    /**
     * Wires a `WebhookService` against a REAL `EventService`/`EventDispatcher`/`ListenerProvider`
     * stack -- mirroring `PayviaServiceProvider::makeWebhookService()`/
     * `makeProviderChargebackDispatcher()` exactly: ordinary local `PaymentProviderEvent`
     * delivery through the real fault-isolated `EventService::dispatch()`, then the chargeback
     * event through the real STRICT `EventService::dispatchOrFail()`. Unlike `service()` (which
     * captures dispatched events in plain in-memory arrays, bypassing the framework event bus
     * entirely), this is what proves the strict-dispatch redelivery guarantee end-to-end rather
     * than only at the injected-callable boundary.
     *
     * @return array{0: WebhookService, 1: EventService}
     */
    private function realEventServiceWiredService(): array
    {
        $listenerProvider = new ListenerProvider();
        $eventDispatcher = new EventDispatcher($listenerProvider);
        $eventService = new EventService($eventDispatcher, $listenerProvider);
        $this->bind(EventService::class, $eventService);

        $chargebackDispatcher = new ProviderChargebackDispatcher(
            $this->correlation,
            static function (ProviderChargebackEvent $event) use ($eventService): void {
                $eventService->dispatchOrFail($event);
            }
        );

        $dispatcher = static function (PaymentProviderEvent $event) use ($eventService, $chargebackDispatcher): void {
            $eventService->dispatch($event);
            $chargebackDispatcher->handle($event->event);
        };

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $service = new WebhookService($this->context, $manager, $this->events, $dispatcher);

        return [$service, $eventService];
    }

    private function stripeSignature(string $body): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_test');

        return 't=' . $timestamp . ',v1=' . $signature;
    }

    private function paystackSignature(string $body): string
    {
        return hash_hmac('sha512', $body, 'paystack_whsec_test');
    }

    public function testStripeDisputeCreatedDispatchesChargebackFromCorrelatedPaymentRow(): void
    {
        $this->insertPayment();

        $body = json_encode([
            'id' => 'evt_dispute_1',
            'type' => 'charge.dispute.created',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'dp_1',
                    'object' => 'dispute',
                    'amount' => 5000,
                    'currency' => 'usd',
                    'reason' => 'fraudulent',
                    'status' => 'needs_response',
                    'payment_intent' => 'pi_123',
                    'charge' => 'ch_1',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->service()->ingest('stripe', $body, ['Stripe-Signature' => $this->stripeSignature($body)]);

        self::assertTrue($result->accepted);
        self::assertCount(1, $this->delivered, 'ordinary local delivery must still happen');
        self::assertCount(1, $this->chargebacks);

        $chargeback = $this->chargebacks[0];
        self::assertSame(ProviderChargebackEvent::KIND_CHARGEBACK, $chargeback->kind);
        self::assertSame('tenantAAAA01', $chargeback->tenantUuid);
        self::assertSame('stripe', $chargeback->provider);
        self::assertSame('dp_1', $chargeback->providerEventId);
        self::assertSame('refAAAAAAAA1', $chargeback->paymentReference);
        self::assertSame('order', $chargeback->payable->type);
        self::assertSame('order_1', $chargeback->payable->id);
        // Payable amount/currency come from the correlated payments row, never the webhook.
        self::assertSame(5000, $chargeback->payable->amount);
        self::assertSame('USD', $chargeback->payable->currency);
        self::assertSame('USD', $chargeback->currency);
        self::assertSame(5000, $chargeback->amount);
        self::assertSame('fraudulent', $chargeback->reasonCode);
        self::assertNull($chargeback->relatedEventId);
    }

    public function testStripeDisputeClosedWonDispatchesReversalLinkedToOriginalDispute(): void
    {
        $this->insertPayment();

        $body = json_encode([
            'id' => 'evt_dispute_2',
            'type' => 'charge.dispute.closed',
            'created' => 1700000100,
            'data' => [
                'object' => [
                    'id' => 'dp_1',
                    'object' => 'dispute',
                    'amount' => 5000,
                    'currency' => 'usd',
                    'reason' => 'fraudulent',
                    'status' => 'won',
                    'payment_intent' => 'pi_123',
                    'charge' => 'ch_1',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->service()->ingest('stripe', $body, ['Stripe-Signature' => $this->stripeSignature($body)]);

        self::assertTrue($result->accepted);
        self::assertCount(1, $this->chargebacks);

        $reversal = $this->chargebacks[0];
        self::assertSame(ProviderChargebackEvent::KIND_REVERSAL, $reversal->kind);
        self::assertSame('dp_1', $reversal->relatedEventId);
        self::assertNotSame('dp_1', $reversal->providerEventId, 'the reversal must carry its own distinct identity');
        self::assertSame('tenantAAAA01', $reversal->tenantUuid);
    }

    public function testStripeDisputeClosedLostIsNotRecognizedAsAReversal(): void
    {
        $this->insertPayment();

        $body = json_encode([
            'id' => 'evt_dispute_lost',
            'type' => 'charge.dispute.closed',
            'created' => 1700000200,
            'data' => [
                'object' => [
                    'id' => 'dp_2',
                    'object' => 'dispute',
                    'amount' => 5000,
                    'currency' => 'usd',
                    'reason' => 'fraudulent',
                    'status' => 'lost',
                    'payment_intent' => 'pi_123',
                    'charge' => 'ch_1',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->service()->ingest('stripe', $body, ['Stripe-Signature' => $this->stripeSignature($body)]);

        self::assertTrue($result->accepted);
        self::assertSame([], $this->chargebacks);
        self::assertSame([], $this->delivered, 'an UNKNOWN-typed event never reaches the dispatcher at all');
    }

    public function testPaystackDisputeCreateDispatchesChargebackFromCorrelatedPaymentRow(): void
    {
        $this->insertPayment([
            'uuid' => 'payBBBBBBBB1',
            'tenant_uuid' => 'tenantBBBB02',
            'gateway' => 'paystack',
            'gateway_transaction_id' => '987',
            'reference' => 'refBBBBBBBB1',
            'payable_type' => 'order',
            'payable_id' => 'order_2',
            'amount' => 3000,
            'currency' => 'GHS',
        ]);

        $body = json_encode([
            'event' => 'charge.dispute.create',
            'data' => [
                'id' => 555,
                'status' => 'awaiting-merchant-feedback',
                'category' => 'general',
                'currency' => 'GHS',
                'refund_amount' => 3000,
                'transaction' => [
                    'id' => 987,
                    'reference' => 'refBBBBBBBB1',
                    'amount' => 3000,
                    'currency' => 'GHS',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->service()->ingest(
            'paystack',
            $body,
            ['x-paystack-signature' => $this->paystackSignature($body)]
        );

        self::assertTrue($result->accepted);
        self::assertCount(1, $this->chargebacks);

        $chargeback = $this->chargebacks[0];
        self::assertSame(ProviderChargebackEvent::KIND_CHARGEBACK, $chargeback->kind);
        self::assertSame('tenantBBBB02', $chargeback->tenantUuid);
        self::assertSame('paystack', $chargeback->provider);
        self::assertSame('555', $chargeback->providerEventId);
        self::assertSame('refBBBBBBBB1', $chargeback->paymentReference);
        self::assertSame(3000, $chargeback->payable->amount);
        self::assertSame('GHS', $chargeback->payable->currency);
        self::assertSame('general', $chargeback->reasonCode);
        self::assertNull($chargeback->relatedEventId);
    }

    public function testPaystackDisputeResolveDeclinedDispatchesReversal(): void
    {
        $this->insertPayment([
            'uuid' => 'payBBBBBBBB1',
            'tenant_uuid' => 'tenantBBBB02',
            'gateway' => 'paystack',
            'gateway_transaction_id' => '987',
            'reference' => 'refBBBBBBBB1',
            'payable_type' => 'order',
            'payable_id' => 'order_2',
            'amount' => 3000,
            'currency' => 'GHS',
        ]);

        $body = json_encode([
            'event' => 'charge.dispute.resolve',
            'data' => [
                'id' => 555,
                'status' => 'resolved',
                'resolution' => 'declined',
                'category' => 'general',
                'currency' => 'GHS',
                'refund_amount' => 3000,
                'transaction' => [
                    'id' => 987,
                    'reference' => 'refBBBBBBBB1',
                    'amount' => 3000,
                    'currency' => 'GHS',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->service()->ingest(
            'paystack',
            $body,
            ['x-paystack-signature' => $this->paystackSignature($body)]
        );

        self::assertTrue($result->accepted);
        self::assertCount(1, $this->chargebacks);
        self::assertSame(ProviderChargebackEvent::KIND_REVERSAL, $this->chargebacks[0]->kind);
        self::assertSame('555', $this->chargebacks[0]->relatedEventId);
    }

    public function testPaystackDisputeResolveMerchantAcceptedIsNotRecognizedAsAReversal(): void
    {
        $this->insertPayment([
            'gateway' => 'paystack',
            'gateway_transaction_id' => '987',
        ]);

        $body = json_encode([
            'event' => 'charge.dispute.resolve',
            'data' => [
                'id' => 555,
                'status' => 'resolved',
                'resolution' => 'merchant-accepted',
                'transaction' => ['id' => 987],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->service()->ingest(
            'paystack',
            $body,
            ['x-paystack-signature' => $this->paystackSignature($body)]
        );

        self::assertTrue($result->accepted);
        self::assertSame([], $this->chargebacks);
    }

    public function testPaystackDisputeRemindReminderIsUnrecognizedAndDispatchesNothing(): void
    {
        $body = json_encode([
            'event' => 'charge.dispute.remind',
            'data' => ['id' => 555, 'transaction' => ['id' => 987]],
        ], JSON_THROW_ON_ERROR);

        $result = $this->service()->ingest(
            'paystack',
            $body,
            ['x-paystack-signature' => $this->paystackSignature($body)]
        );

        self::assertTrue($result->accepted);
        self::assertSame([], $this->chargebacks);
        self::assertSame([], $this->delivered);
    }

    public function testInvalidSignatureIsNeitherPersistedNorDispatched(): void
    {
        $body = json_encode([
            'id' => 'evt_dispute_bad',
            'type' => 'charge.dispute.created',
            'created' => 1700000000,
            'data' => ['object' => ['id' => 'dp_bad', 'payment_intent' => 'pi_123']],
        ], JSON_THROW_ON_ERROR);

        // No Stripe-Signature header at all -- verifyWebhookSignature() fails closed.
        $result = $this->service()->ingest('stripe', $body, []);

        self::assertFalse($result->accepted);
        self::assertSame(401, $result->httpStatus);
        self::assertSame([], $this->chargebacks);
        self::assertSame([], $this->delivered);
    }

    public function testZeroOwnershipMatchesThrowsPersistsAndStaysRedispatchable(): void
    {
        // No matching payments row for pi_missing at all.
        $body = json_encode([
            'id' => 'evt_dispute_3',
            'type' => 'charge.dispute.created',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'dp_3',
                    'object' => 'dispute',
                    'amount' => 5000,
                    'currency' => 'usd',
                    'reason' => 'fraudulent',
                    'payment_intent' => 'pi_missing',
                    'charge' => 'ch_3',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $service = $this->service();

        try {
            $service->ingest('stripe', $body, ['Stripe-Signature' => $this->stripeSignature($body)]);
            self::fail('Expected UnresolvedPaymentOwnershipException to propagate');
        } catch (UnresolvedPaymentOwnershipException $e) {
            self::assertStringContainsString(UnresolvedPaymentOwnershipException::MARKER, $e->getMessage());
        }

        // Ordering held: local delivery ran BEFORE the ownership failure.
        self::assertCount(1, $this->delivered);
        self::assertSame([], $this->chargebacks, 'no fabricated event may ever be dispatched');

        $stored = $this->events->findByDeliveryKey('stripe', 'evt_dispute_3');
        self::assertNotNull($stored);
        self::assertNotSame('dispatched', $stored['dispatch_status']);
        self::assertSame(
            'processed',
            $stored['status'],
            'processing already succeeded before the ownership resolution failure (a dispatch-phase failure), so'
                . ' status stays processed -- not failed -- keeping the row discoverable by relayPending()'
        );

        // Redispatchable: once ownership becomes resolvable, replaying the SAME stored row
        // succeeds and dispatches exactly one chargeback. The first attempt's logical claim
        // (dispatch_status='dispatching') is still within its own default 300s staleness
        // window, so it is backdated here -- exactly like the other redelivery test -- rather
        // than depending on 300 real seconds elapsing.
        $this->connection->table('provider_events')
            ->where(['uuid' => $stored['uuid']])
            ->update([
                'dispatch_claimed_at' => $this->connection->getDriver()
                    ->formatDateTime((new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s')),
            ]);
        $this->insertPayment(['gateway_transaction_id' => 'pi_missing', 'reference' => 'refLateOwner1']);
        $service->processStored((string) $stored['uuid']);

        self::assertCount(1, $this->chargebacks);
        self::assertSame('refLateOwner1', $this->chargebacks[0]->paymentReference);
    }

    public function testMultipleOwnershipMatchesThrowsAndDispatchesNoChargeback(): void
    {
        $this->insertPayment([
            'uuid' => 'payAAAAAAAA1',
            'tenant_uuid' => 'tenantAAAA01',
            'gateway_transaction_id' => 'pi_dupe',
            'reference' => 'refDupe1',
        ]);
        $this->insertPayment([
            'uuid' => 'payAAAAAAAA2',
            'tenant_uuid' => 'tenantBBBB02',
            'gateway_transaction_id' => 'pi_dupe',
            'reference' => 'refDupe2',
        ]);

        $body = json_encode([
            'id' => 'evt_dispute_4',
            'type' => 'charge.dispute.created',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'dp_4',
                    'object' => 'dispute',
                    'amount' => 5000,
                    'currency' => 'usd',
                    'payment_intent' => 'pi_dupe',
                    'charge' => 'ch_4',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->service()->ingest('stripe', $body, ['Stripe-Signature' => $this->stripeSignature($body)]);
            self::fail('Expected UnresolvedPaymentOwnershipException to propagate');
        } catch (UnresolvedPaymentOwnershipException) {
            // expected
        }

        self::assertSame([], $this->chargebacks);
    }

    /**
     * THE key redelivery test: drives the REAL `EventService::dispatchOrFail()` (never an
     * injected callable), reusing `PayviaServiceProvider`'s exact production wiring via
     * `realEventServiceWiredService()`. A real listener throws on its first invocation and
     * succeeds on its second. `ingest()` (not a directly-seeded row) must propagate that first
     * failure -- proving the strict rethrow travels all the way from the framework
     * `EventDispatcher` through `ProviderChargebackDispatcher` and `WebhookService::dispatch()`
     * -- leaving the durable row undispatched, still `status='processed'` (only DISPATCH failed,
     * not processing), so `relayPending()` can discover and redeliver it. The retry succeeds and
     * the row is marked dispatched exactly once; the listener is retried but only appends once.
     */
    public function testRealEventServiceStrictDispatchRedeliversAfterAListenerFailureThenSucceeds(): void
    {
        $this->insertPayment();
        [$service, $eventService] = $this->realEventServiceWiredService();

        $attempts = 0;
        /** @var list<ProviderChargebackEvent> $delivered */
        $delivered = [];
        $eventService->addListener(
            ProviderChargebackEvent::class,
            function (ProviderChargebackEvent $event) use (&$attempts, &$delivered): void {
                $attempts++;
                if ($attempts === 1) {
                    throw new \RuntimeException('simulated real EventService listener failure');
                }
                $delivered[] = $event;
            }
        );

        $body = json_encode([
            'id' => 'evt_dispute_real',
            'type' => 'charge.dispute.created',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'dp_real',
                    'object' => 'dispute',
                    'amount' => 5000,
                    'currency' => 'usd',
                    'reason' => 'fraudulent',
                    'status' => 'needs_response',
                    'payment_intent' => 'pi_123',
                    'charge' => 'ch_real',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $service->ingest('stripe', $body, ['Stripe-Signature' => $this->stripeSignature($body)]);
            self::fail('Expected the real EventService::dispatchOrFail() listener failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated real EventService listener failure', $e->getMessage());
        }

        self::assertSame(1, $attempts);
        self::assertSame([], $delivered);

        $stored = $this->events->findByDeliveryKey('stripe', 'evt_dispute_real');
        self::assertNotNull($stored);
        self::assertNotSame('dispatched', $stored['dispatch_status']);
        self::assertSame(
            'processed',
            $stored['status'],
            'processing succeeded; only strict-dispatch delivery failed, so the row must stay'
                . ' discoverable by relayPending()'
        );

        // Backdate the claim so a staleSeconds:0 retry deterministically reclaims it, rather
        // than depending on real wall-clock elapsing between these two calls.
        $this->connection->table('provider_events')
            ->where(['uuid' => $stored['uuid']])
            ->update([
                'dispatch_claimed_at' => $this->connection->getDriver()
                    ->formatDateTime((new \DateTimeImmutable('-2 seconds'))->format('Y-m-d H:i:s')),
            ]);

        $count = $service->relayPending(staleSeconds: 0);

        self::assertSame(1, $count);
        self::assertSame(2, $attempts, 'the listener is retried on redelivery');
        self::assertCount(1, $delivered, 'the chargeback is delivered exactly once, on the successful retry');

        $afterSuccess = $this->events->findByUuid((string) $stored['uuid']);
        self::assertNotNull($afterSuccess);
        self::assertSame('dispatched', $afterSuccess['dispatch_status']);

        // A further relay finds nothing left to do.
        self::assertSame(0, $service->relayPending(staleSeconds: 0));
        self::assertCount(1, $delivered);
    }

    /**
     * I-1: a permanently-unresolvable poison row must not starve every other due row behind it
     * in the same `relayPending()` sweep. The poison row (received first, so the sweep visits it
     * first) throws `UnresolvedPaymentOwnershipException` on every attempt; the healthy row
     * (received second) must still dispatch in the SAME sweep call.
     */
    public function testRelayPendingIsolatesAPoisonRowFromAHealthyRowInTheSameSweep(): void
    {
        $this->insertPayment();
        $driver = $this->connection->getDriver();
        $now = new \DateTimeImmutable();

        $poisonUuid = $this->events->insertReceived([
            'gateway' => 'stripe',
            'source' => 'webhook',
            'provider_event_id' => 'evt_poison',
            'delivery_key' => 'evt_poison',
            'logical_event_key' => 'chargeback.created:dp_poison',
            'type' => EventType::CHARGEBACK_CREATED,
            'signature_valid' => true,
            'normalized_payload' => [
                // No payments row will ever match this gateway_transaction_id in this test.
                'gateway_transaction_id' => 'pi_never_exists',
                'dispute_provider_event_id' => 'dp_poison',
                'amount' => 4000,
                'amount_unit' => 'minor',
                'currency' => 'USD',
                'reason_code' => 'fraudulent',
            ],
            'raw_payload' => [],
            'received_at' => $driver->formatDateTime($now->modify('-10 seconds')->format('Y-m-d H:i:s')),
        ]);
        self::assertNotNull($poisonUuid);
        $this->events->markProcessed($poisonUuid);

        $healthyUuid = $this->events->insertReceived([
            'gateway' => 'stripe',
            'source' => 'webhook',
            'provider_event_id' => 'evt_healthy',
            'delivery_key' => 'evt_healthy',
            'logical_event_key' => 'chargeback.created:dp_healthy',
            'type' => EventType::CHARGEBACK_CREATED,
            'signature_valid' => true,
            'normalized_payload' => [
                'gateway_transaction_id' => 'pi_123',
                'dispute_provider_event_id' => 'dp_healthy',
                'amount' => 5000,
                'amount_unit' => 'minor',
                'currency' => 'USD',
                'reason_code' => 'fraudulent',
            ],
            'raw_payload' => [],
            'received_at' => $driver->formatDateTime($now->format('Y-m-d H:i:s')),
        ]);
        self::assertNotNull($healthyUuid);
        $this->events->markProcessed($healthyUuid);

        $count = $this->service()->relayPending();

        self::assertSame(1, $count, 'only the healthy row dispatches; the poison row must not abort the sweep');
        self::assertCount(1, $this->chargebacks);
        self::assertSame('dp_healthy', $this->chargebacks[0]->providerEventId);

        $poisonRow = $this->events->findByUuid($poisonUuid);
        self::assertNotNull($poisonRow);
        self::assertNotSame('dispatched', $poisonRow['dispatch_status'], 'the poison row stays retryable');

        $healthyRow = $this->events->findByUuid($healthyUuid);
        self::assertNotNull($healthyRow);
        self::assertSame('dispatched', $healthyRow['dispatch_status']);
    }

    /**
     * M-1: a literal `0` disputed amount bypasses the `?? $payable->amount` fallback (`0` is not
     * `null`). It must fail closed as a classified `MalformedChargebackEventException` BEFORE
     * ever constructing the contracts event -- never dispatching a fabricated event, and never
     * surfacing as an unhandled contract `\InvalidArgumentException`.
     */
    public function testZeroDisputedAmountFailsClosedWithoutDispatchingAFabricatedEvent(): void
    {
        $this->insertPayment();

        $body = json_encode([
            'id' => 'evt_dispute_zero',
            'type' => 'charge.dispute.created',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'dp_zero',
                    'object' => 'dispute',
                    'amount' => 0,
                    'currency' => 'usd',
                    'reason' => 'fraudulent',
                    'status' => 'needs_response',
                    'payment_intent' => 'pi_123',
                    'charge' => 'ch_zero',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->service()->ingest('stripe', $body, ['Stripe-Signature' => $this->stripeSignature($body)]);
            self::fail('Expected MalformedChargebackEventException to propagate');
        } catch (MalformedChargebackEventException $e) {
            self::assertStringContainsString(MalformedChargebackEventException::MARKER, $e->getMessage());
        }

        self::assertCount(1, $this->delivered, 'ordinary local delivery must still happen before the malformed check');
        self::assertSame([], $this->chargebacks, 'no fabricated zero-amount event may ever be dispatched');

        $stored = $this->events->findByDeliveryKey('stripe', 'evt_dispute_zero');
        self::assertNotNull($stored);
        self::assertNotSame('dispatched', $stored['dispatch_status']);
        self::assertSame(
            'processed',
            $stored['status'],
            'processing succeeded; only the malformed disputed amount blocked dispatch'
        );
    }

    /**
     * M-2: a normalized_payload missing ONLY `dispute_provider_event_id` (with a perfectly
     * resolvable `gateway_transaction_id`) must not be misreported as a failed ownership lookup
     * against that gateway_transaction_id -- the correlation query is never even attempted.
     */
    public function testMissingDisputeIdReportsDistinctlyFromAnUnresolvedGatewayTransaction(): void
    {
        $this->insertPayment();

        $uuid = $this->events->insertReceived([
            'gateway' => 'stripe',
            'source' => 'webhook',
            'provider_event_id' => 'evt_missing_dispute_id',
            'delivery_key' => 'evt_missing_dispute_id',
            'logical_event_key' => 'chargeback.created:missing',
            'type' => EventType::CHARGEBACK_CREATED,
            'signature_valid' => true,
            'normalized_payload' => [
                'gateway_transaction_id' => 'pi_123',
                'amount' => 5000,
                'amount_unit' => 'minor',
                'currency' => 'USD',
            ],
            'raw_payload' => [],
        ]);
        self::assertNotNull($uuid);
        $this->events->markProcessed($uuid);

        try {
            $this->service()->processStored($uuid);
            self::fail('Expected UnresolvedPaymentOwnershipException to propagate');
        } catch (UnresolvedPaymentOwnershipException $e) {
            self::assertStringContainsString(UnresolvedPaymentOwnershipException::MARKER, $e->getMessage());
            self::assertStringContainsString('dispute_provider_event_id', $e->getMessage());
            self::assertStringContainsString(
                'pi_123',
                $e->getMessage(),
                'must still name the present gateway_transaction_id'
            );
            self::assertStringNotContainsString(
                'could not resolve exactly one payments owner',
                $e->getMessage(),
                'a missing dispute id must not be misreported as a failed ownership lookup'
            );
        }

        self::assertSame([], $this->chargebacks);
    }

    /**
     * Regression guard: ordinary local `PaymentProviderEvent` delivery MUST stay on the
     * fault-isolated `EventService::dispatch()` path -- only the chargeback event goes strict. A
     * throwing non-chargeback listener must never abort ingestion, and the chargeback branch
     * must still run afterward.
     */
    public function testOrdinaryLocalDeliveryStaysFaultIsolatedWhenANonChargebackListenerThrows(): void
    {
        $this->insertPayment();
        [$service, $eventService] = $this->realEventServiceWiredService();

        $localAttempts = 0;
        $eventService->addListener(
            PaymentProviderEvent::class,
            function (PaymentProviderEvent $event) use (&$localAttempts): void {
                $localAttempts++;
                throw new \RuntimeException('a broken ordinary local listener must never abort dispatch');
            }
        );

        $chargebackAttempts = 0;
        /** @var list<ProviderChargebackEvent> $delivered */
        $delivered = [];
        $eventService->addListener(
            ProviderChargebackEvent::class,
            function (ProviderChargebackEvent $event) use (&$chargebackAttempts, &$delivered): void {
                $chargebackAttempts++;
                $delivered[] = $event;
            }
        );

        $body = json_encode([
            'id' => 'evt_dispute_fault_isolated',
            'type' => 'charge.dispute.created',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'dp_fault_isolated',
                    'object' => 'dispute',
                    'amount' => 5000,
                    'currency' => 'usd',
                    'reason' => 'fraudulent',
                    'status' => 'needs_response',
                    'payment_intent' => 'pi_123',
                    'charge' => 'ch_fault_isolated',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $service->ingest('stripe', $body, ['Stripe-Signature' => $this->stripeSignature($body)]);

        self::assertTrue(
            $result->accepted,
            'a fault-isolated local listener failure must never surface as an ingest failure'
        );
        self::assertSame(1, $localAttempts);
        self::assertSame(
            1,
            $chargebackAttempts,
            'the chargeback dispatch must still run after the fault-isolated local delivery'
        );
        self::assertCount(1, $delivered);

        $stored = $this->events->findByDeliveryKey('stripe', 'evt_dispute_fault_isolated');
        self::assertNotNull($stored);
        self::assertSame('dispatched', $stored['dispatch_status']);
    }

    public function testNoCommerceClassIsReferencedAnywhereInTheDisputeDispatchPath(): void
    {
        foreach (
            [
                __DIR__ . '/../../../src/Events/EventType.php',
                __DIR__ . '/../../../src/Events/ProviderChargebackDispatcher.php',
                __DIR__ . '/../../../src/Exceptions/UnresolvedPaymentOwnershipException.php',
                __DIR__ . '/../../../src/Exceptions/MalformedChargebackEventException.php',
                __DIR__ . '/../../../src/Gateways/StripeGateway.php',
                __DIR__ . '/../../../src/Gateways/PaystackGateway.php',
                __DIR__ . '/../../../src/Services/WebhookService.php',
                __DIR__ . '/../../../src/PayviaServiceProvider.php',
            ] as $file
        ) {
            self::assertFileExists($file);
            self::assertStringNotContainsStringIgnoringCase(
                'commerce',
                (string) file_get_contents($file),
                "{$file} must never reference commerce"
            );
        }
    }
}
