<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Extensions\Payvia\Contracts\InitiationCapableGateway;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
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

    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    public function initialize(PayableReference $payable, array $options = []): array
    {
        $this->initializeCalls++;

        return [
            'reference' => 'ref-' . $this->initializeCalls,
            'checkout_url' => 'https://checkout.test/ref-' . $this->initializeCalls,
        ];
    }
}
