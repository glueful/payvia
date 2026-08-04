<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Gateways;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCancellationModeProvider;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Glueful\Http\Client;

/**
 * The additive `SubscriptionCancellationModeProvider` capability (workspace checkout program,
 * Phase A Task 1). This interface is deliberately separate from -- and does not modify --
 * `SubscriptionCapableGateway`, so existing third-party subscription drivers that only
 * implement the old interface remain fully valid without adopting the new one.
 */
final class CancellationModesTest extends PayviaTestCase
{
    private function context(): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/payvia-cancellation-modes-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'stripe' => ['secret_key' => 'sk_test_123', 'base_url' => 'https://api.stripe.com', 'timeout' => 15],
                'paystack' => [
                    'secret_key' => 'sk_test_456',
                    'base_url' => 'https://api.paystack.co',
                    'timeout' => 15,
                ],
            ],
        ], true) . ";\n");
        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        return $context;
    }

    public function testStripeDeclaresStopRenewalAndImmediate(): void
    {
        $gateway = new StripeGateway($this->createMock(Client::class), $this->context());

        self::assertInstanceOf(SubscriptionCancellationModeProvider::class, $gateway);
        self::assertSame(['stop_renewal', 'immediate'], $gateway->cancellationModes());
    }

    public function testPaystackDeclaresOnlyStopRenewal(): void
    {
        $gateway = new PaystackGateway($this->createMock(Client::class), $this->context());

        self::assertInstanceOf(SubscriptionCancellationModeProvider::class, $gateway);
        self::assertSame(['stop_renewal'], $gateway->cancellationModes());
    }

    /**
     * A third-party gateway implementing ONLY the pre-existing `SubscriptionCapableGateway`
     * (never modified) remains a perfectly valid, instantiable driver -- it simply exposes no
     * self-serve cancellation modes to a caller that probes for the additive capability.
     */
    public function testAThirdPartySubscriptionCapableGatewayRemainsValidWithoutTheAdditiveInterface(): void
    {
        $thirdParty = new FakeWebhookGateway();

        self::assertInstanceOf(SubscriptionCapableGateway::class, $thirdParty);
        self::assertNotInstanceOf(SubscriptionCancellationModeProvider::class, $thirdParty);
    }

    private function managerWithDrivers(): GatewayManager
    {
        $context = $this->context();
        $fake = new FakeWebhookGateway();
        $this->bind(FakeWebhookGateway::class, $fake);
        $this->bind(StripeGateway::class, new StripeGateway($this->createMock(Client::class), $context));
        $this->bind(PaystackGateway::class, new PaystackGateway($this->createMock(Client::class), $context));

        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);
        $manager->registerDriver('stripe', StripeGateway::class);
        $manager->registerDriver('paystack', PaystackGateway::class);

        return $manager;
    }

    public function testGatewayManagerProbesSubscriptionCheckoutCapability(): void
    {
        $manager = $this->managerWithDrivers();

        self::assertTrue($manager->supports('stripe', 'subscription_checkout'));
        self::assertFalse($manager->supports('paystack', 'subscription_checkout'));
        self::assertFalse($manager->supports('fake', 'subscription_checkout'));
    }

    public function testGatewayManagerProbesCancellationModesCapability(): void
    {
        $manager = $this->managerWithDrivers();

        self::assertTrue($manager->supports('stripe', 'cancellation_modes'));
        self::assertTrue($manager->supports('paystack', 'cancellation_modes'));
        self::assertFalse($manager->supports('fake', 'cancellation_modes'));
    }
}
