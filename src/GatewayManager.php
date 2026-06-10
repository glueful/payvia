<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
use Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Psr\Container\ContainerInterface;

final class GatewayManager
{
    private ApplicationContext $context;
    /** @var array<string,string> */
    private array $drivers = [
        'paystack' => PaystackGateway::class,
        'stripe' => StripeGateway::class,
    ];

    /** @var array<string,PaymentGatewayInterface> */
    private array $resolved = [];

    public function __construct(
        private ContainerInterface $container,
        ApplicationContext $context,
    ) {
        $this->context = $context;
    }

    public function gateway(string $name): PaymentGatewayInterface
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $config = (array) config($this->context, 'payvia.gateways', []);
        if (!isset($config[$name]) && !isset($this->drivers[$name])) {
            throw new \RuntimeException("Payvia: gateway '{$name}' is not configured or disabled.");
        }
        if (isset($config[$name]) && (($config[$name]['enabled'] ?? true) === false)) {
            throw new \RuntimeException("Payvia: gateway '{$name}' is disabled.");
        }

        $gatewayConfig = (array) ($config[$name] ?? []);
        $driver = (string) ($gatewayConfig['driver'] ?? $name);

        $class = $this->drivers[$driver] ?? null;
        if ($class === null) {
            throw new \RuntimeException("Payvia: no driver registered for '{$driver}'.");
        }

        $instance = $this->container->get($class);
        if (!$instance instanceof PaymentGatewayInterface) {
            throw new \RuntimeException("Payvia: driver '{$driver}' must implement PaymentGatewayInterface.");
        }

        return $this->resolved[$name] = $instance;
    }

    public function registerDriver(string $name, string $class): void
    {
        $this->drivers[$name] = $class;
        unset($this->resolved[$name]);
    }

    public function supports(string $gateway, string $capability): bool
    {
        try {
            $driver = $this->gateway($gateway);
        } catch (\Throwable) {
            return false;
        }

        return match ($capability) {
            'webhook' => $driver instanceof WebhookCapableGateway,
            'subscription' => $driver instanceof SubscriptionCapableGateway,
            default => false,
        };
    }

    public function webhookGateway(string $gateway): WebhookCapableGateway
    {
        $driver = $this->gateway($gateway);
        if (!$driver instanceof WebhookCapableGateway) {
            throw new \RuntimeException("Payvia: gateway '{$gateway}' does not support webhooks.");
        }

        return $driver;
    }

    public function subscriptionGateway(string $gateway): SubscriptionCapableGateway
    {
        $driver = $this->gateway($gateway);
        if (!$driver instanceof SubscriptionCapableGateway) {
            throw new \RuntimeException("Payvia: gateway '{$gateway}' does not support subscriptions.");
        }

        return $driver;
    }
}
