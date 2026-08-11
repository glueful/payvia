<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Extensions\Payvia\Contracts\InitiationCapableGateway;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Database\Migrations\AddPaymentIntentAttemptLifecycle;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
use Glueful\Extensions\Payvia\Repositories\DuplicateReferenceException;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Services\PayviaPaymentCollector;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class PayviaPaymentCollectorTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePaymentIntentsTable());
        $this->runMigration(new AddPaymentIntentAttemptLifecycle());
    }

    public function testTwoInitiatesYieldOneGatewayReference(): void
    {
        $gateway = new FakeInitiationGateway();
        $this->bind(FakeInitiationGateway::class, $gateway);

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeInitiationGateway::class);
        $this->useGateway('fake');

        $collector = new PayviaPaymentCollector($manager, new PaymentIntentRepository($this->connection));
        $payable = new PayableReference('commerce_order', 'ord1', 4999, 'GHS');

        $first = $collector->initiate($this->context, $payable);
        $second = $collector->initiate($this->context, $payable);

        self::assertInstanceOf(PaymentInitiation::class, $first);
        self::assertSame('ok', $first->status);
        self::assertSame('ref-1', $first->payload['reference']);
        self::assertSame('ref-1', $second->payload['reference']);
        self::assertSame(1, $gateway->initializeCalls);
    }

    public function testMetadataConventionKeysReachTheGatewayOptions(): void
    {
        // The payable-type-agnostic initiation seam: whoever BUILDS a payable supplies the
        // well-known metadata keys; the collector lifts them into gateway options ONCE — no
        // per-consumer parameters, no payable_type special-casing.
        $gateway = new FakeInitiationGateway();
        $this->bind(FakeInitiationGateway::class, $gateway);

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeInitiationGateway::class);
        $this->useGateway('fake');

        $collector = new PayviaPaymentCollector($manager, new PaymentIntentRepository($this->connection));
        $collector->initiate($this->context, new PayableReference(
            'commerce_order',
            'ordmeta1',
            4999,
            'GHS',
            'Order THL-1',
            [
                'email' => 'buyer@example.test',
                'callback_url' => 'https://shop.test/checkout/return/THL-1',
                'cancel_url' => 'https://shop.test/checkout/cancel/THL-1',
                'unrelated' => 'never-lifted',
            ],
        ));

        self::assertSame([
            'email' => 'buyer@example.test',
            'callback_url' => 'https://shop.test/checkout/return/THL-1',
            'cancel_url' => 'https://shop.test/checkout/cancel/THL-1',
        ], $gateway->lastOptions);
    }

    public function testEmptyMetadataPassesNoOptions(): void
    {
        $gateway = new FakeInitiationGateway();
        $this->bind(FakeInitiationGateway::class, $gateway);

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeInitiationGateway::class);
        $this->useGateway('fake');

        $collector = new PayviaPaymentCollector($manager, new PaymentIntentRepository($this->connection));
        $collector->initiate($this->context, new PayableReference('commerce_order', 'ordmeta2', 4999, 'GHS'));

        self::assertSame([], $gateway->lastOptions);
    }

    public function testGatewayInitializationExceptionsPropagate(): void
    {
        // The collector deliberately has NO catch: initiation failures are the CALLER's to map
        // (commerce catches and records init_failed). Pinned so a future "helpful" catch here
        // cannot silently change that ownership.
        $gateway = new ThrowingInitiationGateway();
        $this->bind(ThrowingInitiationGateway::class, $gateway);

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', ThrowingInitiationGateway::class);
        $this->useGateway('fake');

        $collector = new PayviaPaymentCollector($manager, new PaymentIntentRepository($this->connection));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gateway exploded');
        $collector->initiate($this->context, new PayableReference('commerce_order', 'ordmeta3', 4999, 'GHS'));
    }

    public function testNonCapableGatewayReturnsManualInitiation(): void
    {
        $gateway = new FakeWebhookGateway();
        $this->bind(FakeWebhookGateway::class, $gateway);

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);
        $this->useGateway('fake');

        $collector = new PayviaPaymentCollector($manager, new PaymentIntentRepository($this->connection));

        $result = $collector->initiate(
            $this->context,
            new PayableReference('commerce_order', 'ord2', 4999, 'GHS')
        );

        self::assertSame('manual', $result->status);
        self::assertStringContainsString('does not support hosted initiation', $result->payload['instructions']);
    }

    public function testProviderBindsSharedPaymentCollectorContract(): void
    {
        $services = PayviaServiceProvider::services();

        self::assertSame(PayviaPaymentCollector::class, $services[PaymentCollector::class]['class'] ?? null);
    }

    public function testKeylessDeclaredGatewayDegradesToManualCollection(): void
    {
        // The shipped paystack config declares a secret_key slot (env default null). Installed-
        // but-unconfigured must mean MANUAL collection, never an initiation with an empty secret.
        $collector = new PayviaPaymentCollector(
            new GatewayManager($this->context->getContainer(), $this->context),
            new PaymentIntentRepository($this->connection),
        );

        $config = require __DIR__ . '/../../config/payvia.php';
        $config['default_gateway'] = 'paystack';
        $this->context->mergeConfigDefaults('payvia', $config);

        $result = $collector->initiate(
            $this->context,
            new PayableReference('commerce_order', 'ord3', 4999, 'GHS')
        );

        self::assertSame('manual', $result->status);
        self::assertStringContainsString('Payment is collected manually', $result->payload['instructions']);
        self::assertStringContainsString("'paystack' is not configured", $result->payload['instructions']);
    }

    public function testDisabledDefaultGatewayDegradesToManualCollection(): void
    {
        $gateway = new FakeInitiationGateway();
        $this->bind(FakeInitiationGateway::class, $gateway);

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeInitiationGateway::class);
        $config = require __DIR__ . '/../../config/payvia.php';
        $config['default_gateway'] = 'fake';
        $config['gateways']['fake'] = ['enabled' => false, 'driver' => 'fake'];
        $this->context->mergeConfigDefaults('payvia', $config);

        $collector = new PayviaPaymentCollector($manager, new PaymentIntentRepository($this->connection));
        $result = $collector->initiate(
            $this->context,
            new PayableReference('commerce_order', 'ord4', 4999, 'GHS')
        );

        self::assertSame('manual', $result->status);
        self::assertSame(0, $gateway->initializeCalls);
    }

    /**
     * IMPORTANT 3 (payment-links Task 1 fix review): a gateway with a fixed, time-boxed
     * idempotency key -- Stripe replays the identical checkout session id/reference for ~24h for
     * a retried request -- can hand back the SAME reference for a retried `initiate()` on a
     * payable whose earlier attempt already closed under it. Before the fix, `createOpen()`
     * returning `false` for ANY unique violation made the collector assume the ordinary
     * "already open" race, find nothing via `findOpen()` (the earlier intent is CLOSED, not
     * open), and fall through to fabricate a "success" `PaymentInitiation` with nothing
     * persisted -- silently losing the payment record. `createOpen()` now throws
     * `DuplicateReferenceException` for exactly this case, and the collector has -- by design,
     * see `testGatewayInitializationExceptionsPropagate()` above -- no catch of its own, so it
     * must propagate all the way out rather than ever reach that fabricated-success line.
     */
    public function testReplayedGatewayReferenceAfterCloseSurfacesATypedFailureInsteadOfAFakeOk(): void
    {
        $gateway = new FixedReferenceInitiationGateway('cs_test_fixed');
        $this->bind(FixedReferenceInitiationGateway::class, $gateway);

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FixedReferenceInitiationGateway::class);
        $this->useGateway('fake');

        $intents = new PaymentIntentRepository($this->connection);
        $collector = new PayviaPaymentCollector($manager, $intents);
        $payable = new PayableReference('commerce_order', 'ord-replay', 4999, 'GHS');

        $first = $collector->initiate($this->context, $payable);
        self::assertSame('cs_test_fixed', $first->payload['reference']);

        // The order's payment settles and the intent is closed -- exactly what
        // ConfirmationDispatcher does in production.
        $open = $intents->findOpen($this->context, 'commerce_order', 'ord-replay');
        self::assertIsArray($open);
        $intents->close($this->context, (string) $open['uuid'], 'cs_test_fixed');

        // A retried checkout for the SAME payable: findOpen() sees nothing (the intent is
        // closed), so the collector calls the gateway again -- which hands back the IDENTICAL
        // reference, colliding with the now-retired row.
        $this->expectException(DuplicateReferenceException::class);
        $collector->initiate($this->context, $payable);
    }

    private function useGateway(string $gateway): void
    {
        $config = require __DIR__ . '/../../config/payvia.php';
        $config['default_gateway'] = $gateway;
        $config['gateways'][$gateway] = [
            'enabled' => true,
            'driver' => $gateway,
        ];

        $this->context->mergeConfigDefaults('payvia', $config);
    }
}

final class FakeInitiationGateway implements PaymentGatewayInterface, InitiationCapableGateway
{
    public int $initializeCalls = 0;
    /** @var array<string,mixed> */
    public array $lastOptions = [];

    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    public function initialize(PayableReference $payable, array $options = []): array
    {
        $this->initializeCalls++;
        $this->lastOptions = $options;

        return [
            'reference' => 'ref-' . $this->initializeCalls,
            'checkout_url' => 'https://checkout.test/ref-' . $this->initializeCalls,
        ];
    }
}

final class ThrowingInitiationGateway implements PaymentGatewayInterface, InitiationCapableGateway
{
    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    public function initialize(PayableReference $payable, array $options = []): array
    {
        throw new \RuntimeException('gateway exploded');
    }
}

/**
 * Mirrors a gateway with a fixed, time-boxed idempotency key -- e.g. Stripe, which replays the
 * identical checkout session id/reference for ~24h for an equivalent retried request -- by always
 * handing back the SAME reference regardless of how many times `initialize()` is called.
 */
final class FixedReferenceInitiationGateway implements PaymentGatewayInterface, InitiationCapableGateway
{
    public function __construct(private string $reference)
    {
    }

    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    public function initialize(PayableReference $payable, array $options = []): array
    {
        return [
            'reference' => $this->reference,
            'checkout_url' => 'https://checkout.test/' . $this->reference,
        ];
    }
}
