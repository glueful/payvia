<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Extensions\Payvia\Support\PayviaSettingsOverride;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * The PayviaSettings read surface: no binding → pure config passthrough (every existing
 * install unchanged); a bound override wins for the whitelisted keys; a null or MALFORMED
 * override (non-boolean enabled flag, non-slug gateway id, blank secret) falls back to
 * config — a corrupted stored row must never leak into payment routing or signature
 * verification.
 */
final class PayviaSettingsTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The harness context has no config loader; getConfig() resolves via file-based
        // ConfigurationLoader only — point it at the extension's real config/ so the
        // reader's fallbacks are the shipped defaults.
        $root = dirname(__DIR__, 3);
        $this->context->setConfigLoader(new ConfigurationLoader($root, 'testing', $root . '/config'));
    }

    /** @param array<string,?string> $values */
    private function bindOverride(array $values): void
    {
        $this->bindings[PayviaSettingsOverride::class] = new class ($values) implements PayviaSettingsOverride {
            /** @param array<string,?string> $values */
            public function __construct(private array $values)
            {
            }

            public function value(ApplicationContext $context, string $key): ?string
            {
                return $this->values[$key] ?? null;
            }
        };
    }

    public function testNoBindingFallsThroughToConfig(): void
    {
        self::assertSame('paystack', PayviaSettings::defaultGateway($this->context));

        $paystack = PayviaSettings::gatewayConfig($this->context, 'paystack');
        self::assertSame('paystack', $paystack['driver']);
        self::assertArrayHasKey('paystack', PayviaSettings::gateways($this->context));
    }

    public function testBoundOverrideWinsForWhitelistedKeys(): void
    {
        $this->bindOverride([
            'payvia.default_gateway' => 'stripe',
            'payvia.gateways.paystack.enabled' => 'false',
            'payvia.gateways.stripe.enabled' => 'true',
            'payvia.gateways.stripe.secret_key' => 'sk_live_from_settings',
            'payvia.gateways.stripe.webhook_secret' => 'whsec_from_settings',
        ]);

        self::assertSame('stripe', PayviaSettings::defaultGateway($this->context));

        $stripe = PayviaSettings::gatewayConfig($this->context, 'stripe');
        self::assertTrue($stripe['enabled']);
        self::assertSame('sk_live_from_settings', $stripe['secret_key']);
        self::assertSame('whsec_from_settings', $stripe['webhook_secret']);

        $gateways = PayviaSettings::gateways($this->context);
        self::assertFalse($gateways['paystack']['enabled']);
        self::assertTrue($gateways['stripe']['enabled']);
    }

    public function testNonOverridableKeysPassThroughUntouched(): void
    {
        $this->bindOverride([
            'payvia.gateways.stripe.secret_key' => 'sk_live_x',
            // Ops knobs must be ignored even if a row somehow exists for them.
            'payvia.gateways.stripe.base_url' => 'https://evil.example',
            'payvia.gateways.stripe.timeout' => '9999',
        ]);

        $stripe = PayviaSettings::gatewayConfig($this->context, 'stripe');
        self::assertSame('https://api.stripe.com', $stripe['base_url']);
        self::assertSame(15, $stripe['timeout']);
    }

    public function testMalformedOverridesFallBackDefensively(): void
    {
        $this->bindOverride([
            'payvia.default_gateway' => 'not a slug!',
            'payvia.gateways.paystack.enabled' => 'maybe',
            'payvia.gateways.paystack.secret_key' => '   ',
        ]);

        self::assertSame('paystack', PayviaSettings::defaultGateway($this->context));

        $paystack = PayviaSettings::gatewayConfig($this->context, 'paystack');
        // Config default for paystack.enabled is true; 'maybe' must not flip it.
        self::assertTrue($paystack['enabled']);
        // Blank secret override must not blank a config-provided key.
        self::assertSame(
            (array) config($this->context, 'payvia.gateways.paystack', []),
            $paystack,
        );
    }

    public function testOverridesCannotInventNewGateways(): void
    {
        $this->bindOverride(['payvia.gateways.rogue.enabled' => 'true']);

        self::assertArrayNotHasKey('rogue', PayviaSettings::gateways($this->context));
        // gatewayConfig for an unconfigured id stays an empty base (enabled overlay
        // may apply, but there is no driver so GatewayManager still rejects it).
        self::assertArrayNotHasKey('driver', PayviaSettings::gatewayConfig($this->context, 'rogue'));
    }
}
