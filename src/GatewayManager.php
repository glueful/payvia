<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Psr\Container\ContainerInterface;

final class GatewayManager
{
    private ApplicationContext $context;
    /** @var array<string,string> */
    private array $drivers = [
        'paystack' => PaystackGateway::class,
        // additional drivers (stripe, flutterwave, etc.) can be added here
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
        if (!isset($config[$name]) || (($config[$name]['enabled'] ?? true) === false)) {
            throw new \RuntimeException("Payvia: gateway '{$name}' is not configured or disabled.");
        }

        $gatewayConfig = (array) $config[$name];
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
}
