<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Container;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;
use Glueful\Extensions\Payvia\Database\Migrations\AddProviderEventDispatchClaimToken;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\RecordingStrictLaneProvider;
use Glueful\Extensions\Payvia\Tests\Support\RecordingStrictListener;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Fix wave I5 -- the ONLY test that exercises the real `PayviaServiceProvider::
 * makeWebhookService()` factory. Every other lane test (including
 * `DisputeWebhookDispatchTest::testAllThreeLanesRunInExactOrder...`) hand-builds a
 * dispatcher closure that MIRRORS the production one; a mirror cannot fail when
 * the original changes, so none of them guards the composition that actually
 * ships.
 *
 * Here the container is built by the REAL framework pipeline
 * (`ContainerFactory::create()`) from a temp `config/serviceproviders.php` that
 * enables `PayviaServiceProvider` plus {@see RecordingStrictLaneProvider} (which
 * publishes a recording listener under the strict container tag exactly as a real
 * consumer must). `WebhookService` is then RESOLVED from that container -- so the
 * dispatcher under test is the closure `makeWebhookService()` itself built, with
 * the lane it itself composed.
 *
 * The database is a temp FILE, not `:memory:`, deliberately: `BaseRepository`
 * shares one process-static connection, and a file-backed DSN keeps every
 * connection instance pointed at the same database regardless of what any earlier
 * test in the process left in that static.
 */
final class ProductionWebhookServiceCompositionTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_test';

    private ApplicationContext $context;
    private ContainerInterface $container;
    private Connection $connection;
    private string $basePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        RecordingStrictListener::reset();

        $this->basePath = sys_get_temp_dir() . '/payvia-prod-composition-' . uniqid('', true);
        @mkdir($this->basePath . '/config', 0777, true);
        $databaseFile = $this->basePath . '/payvia.sqlite';
        touch($databaseFile);

        $this->writeConfig('serviceproviders', [
            'enabled' => [PayviaServiceProvider::class, RecordingStrictLaneProvider::class],
        ]);
        $this->writeConfig('database', [
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $databaseFile],
            'pooling' => ['enabled' => false],
        ]);
        $this->writeConfig('payvia', [
            'gateways' => [
                'stripe' => [
                    'secret_key' => 'sk_test',
                    'webhook_secret' => self::WEBHOOK_SECRET,
                    'webhook_tolerance' => 300,
                    'base_url' => 'https://api.stripe.com',
                    'timeout' => 15,
                ],
            ],
            // Queue disabled: ingest() runs processStored() inline, so a strict
            // listener failure escapes ingest() itself -- exactly what the real
            // WebhookController lets propagate.
            'webhooks' => ['queue' => false, 'queue_name' => 'default'],
        ]);

        $this->context = new ApplicationContext($this->basePath, 'testing');
        $this->context->setConfigLoader(
            new ConfigurationLoader($this->basePath, 'testing', $this->basePath . '/config')
        );

        $this->container = ContainerFactory::create($this->context, false);
        $this->connection = $this->container->get(Connection::class);

        // Pin BaseRepository's process-static shared connection to the container's
        // own, so a connection left behind by an earlier test in this process can
        // never be the one the resolved repositories talk to.
        new ProviderEventRepository($this->connection);

        $schema = $this->connection->getSchemaBuilder();
        (new CreateProviderEventsTable())->up($schema);
        (new AddProviderEventDispatchClaimToken())->up($schema);
        (new CreatePaymentsTable())->up($schema);
    }

    protected function tearDown(): void
    {
        RecordingStrictListener::reset();
        parent::tearDown();
    }

    /** @param array<string,mixed> $values */
    private function writeConfig(string $name, array $values): void
    {
        file_put_contents(
            $this->basePath . '/config/' . $name . '.php',
            "<?php\nreturn " . var_export($values, true) . ";\n"
        );
    }

    /**
     * Records the ordinary bus lane and the chargeback lane into the SAME array
     * the tagged strict listener writes to, so one array pins all three markers.
     * Both are registered on the container's own shared `EventService` -- the very
     * instance `makeWebhookService()`'s closure resolves and dispatches through.
     */
    private function recordOrdinaryAndChargebackLanes(): void
    {
        $events = $this->container->get(EventService::class);
        $events->addListener(PaymentProviderEvent::class, static function (): void {
            RecordingStrictListener::$order[] = 'ordinary';
        });
        $events->addListener(ProviderChargebackEvent::class, static function (): void {
            RecordingStrictListener::$order[] = 'chargebacks';
        });
    }

    private function seedCorrelatedPayment(): void
    {
        $this->connection->table('payments')->insert([
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
        ]);
    }

    /**
     * A RECOGNIZED dispute type, so the chargeback dispatcher actually fires and
     * the third lane marker can be observed at all.
     */
    private function disputeBody(string $deliveryKey): string
    {
        return json_encode([
            'id' => $deliveryKey,
            'type' => 'charge.dispute.created',
            'created' => 1700000000,
            'data' => [
                'object' => [
                    'id' => 'dp_' . $deliveryKey,
                    'object' => 'dispute',
                    'amount' => 5000,
                    'currency' => 'usd',
                    'reason' => 'fraudulent',
                    'status' => 'needs_response',
                    'payment_intent' => 'pi_123',
                    'charge' => 'ch_' . $deliveryKey,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,string> */
    private function stripeHeaders(string $body): array
    {
        $timestamp = time();

        return [
            'Stripe-Signature' => 't=' . $timestamp
                . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, self::WEBHOOK_SECRET),
        ];
    }

    private function dispatchStatusFor(string $deliveryKey): ?string
    {
        $row = $this->connection->table('provider_events')
            ->where('gateway', '=', 'stripe')
            ->where('delivery_key', '=', $deliveryKey)
            ->first();

        return is_array($row) ? (string) $row['dispatch_status'] : null;
    }

    /**
     * (1) Lane ORDER through the production closure: ordinary bus delivery, then
     * the tagged strict lane, then chargebacks -- always last. Swapping the
     * strict `foreach` and `$chargebacks->handle()` lines inside
     * `makeWebhookService()` fails here and nowhere else.
     */
    public function testProductionFactoryComposesOrdinaryThenStrictThenChargebacks(): void
    {
        $this->seedCorrelatedPayment();
        $this->recordOrdinaryAndChargebackLanes();

        // The production factory, resolved -- not a mirror of it.
        $service = $this->container->get(WebhookService::class);
        self::assertInstanceOf(WebhookService::class, $service);

        $body = $this->disputeBody('evt_prod_order');
        $result = $service->ingest('stripe', $body, $this->stripeHeaders($body));

        self::assertTrue($result->accepted);
        self::assertSame(['ordinary', 'strict', 'chargebacks'], RecordingStrictListener::$order);
        self::assertSame('dispatched', $this->dispatchStatusFor('evt_prod_order'));
    }

    /**
     * (2) A throwing strict listener, through the production closure: the
     * exception ESCAPES `ingest()` (nothing in the composed dispatcher catches
     * it) and the logical-dispatch lease is RELEASED, leaving `dispatch_status`
     * back at `pending` rather than stuck in `dispatching`. Also proves the
     * chargeback lane never ran -- the strict lane throws before it.
     */
    public function testThrowingStrictListenerReleasesTheLeaseAndTheExceptionEscapes(): void
    {
        $this->seedCorrelatedPayment();
        $this->recordOrdinaryAndChargebackLanes();

        $boom = new \RuntimeException('strict listener exploded');
        RecordingStrictListener::$throwOnHandle = $boom;

        $service = $this->container->get(WebhookService::class);

        $body = $this->disputeBody('evt_prod_throw');
        try {
            $service->ingest('stripe', $body, $this->stripeHeaders($body));
            self::fail('expected the strict listener exception to escape ingest()');
        } catch (\RuntimeException $e) {
            self::assertSame($boom, $e); // the ORIGINAL exception, unwrapped
        }

        self::assertSame('pending', $this->dispatchStatusFor('evt_prod_throw'));
        self::assertSame(['ordinary', 'strict'], RecordingStrictListener::$order);
    }
}
