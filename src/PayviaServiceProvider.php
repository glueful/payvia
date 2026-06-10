<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\BillingPlanRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\InvoiceRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\GatewaySubscriptionRepositoryInterface;
use Glueful\Extensions\Payvia\Repositories\PaymentRepository;
use Glueful\Extensions\Payvia\Repositories\BillingPlanRepository;
use Glueful\Extensions\Payvia\Repositories\InvoiceRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Repositories\GatewaySubscriptionRepository;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Extensions\Payvia\Services\BillingPlanService;
use Glueful\Extensions\Payvia\Services\InvoiceService;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Services\GatewaySubscriptionService;
use Glueful\Extensions\Payvia\Controllers\PaymentController;
use Glueful\Extensions\Payvia\Controllers\BillingPlanController;
use Glueful\Extensions\Payvia\Controllers\InvoiceController;
use Glueful\Extensions\Payvia\Controllers\WebhookController;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Events\EventService;
use Glueful\Queue\QueueManager;
use Psr\Container\ContainerInterface;

final class PayviaServiceProvider extends ServiceProvider
{
    private static ?string $cachedVersion = null;

    /**
     * Read the extension version from composer.json (cached)
     */
    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $path = __DIR__ . '/../composer.json';
            $composer = json_decode(file_get_contents($path), true);
            self::$cachedVersion = $composer['version'] ?? '0.0.0';
        }

        return self::$cachedVersion;
    }

    public function getName(): string
    {
        return 'Payvia';
    }

    public function getVersion(): string
    {
        return self::composerVersion();
    }

    public function getDescription(): string
    {
        return 'Unified payment gateway bridge for Glueful.';
    }

    public static function services(): array
    {
        return [
            PaymentRepositoryInterface::class => [
                'class' => PaymentRepository::class,
                'shared' => true,
            ],
            BillingPlanRepositoryInterface::class => [
                'class' => BillingPlanRepository::class,
                'shared' => true,
            ],
            InvoiceRepositoryInterface::class => [
                'class' => InvoiceRepository::class,
                'shared' => true,
            ],
            ProviderEventRepositoryInterface::class => [
                'class' => ProviderEventRepository::class,
                'shared' => true,
            ],
            GatewaySubscriptionRepositoryInterface::class => [
                'class' => GatewaySubscriptionRepository::class,
                'shared' => true,
            ],
            PaymentService::class => [
                'class' => PaymentService::class,
                'shared' => true,
                'autowire' => true,
            ],
            BillingPlanService::class => [
                'class' => BillingPlanService::class,
                'shared' => true,
                'autowire' => true,
            ],
            InvoiceService::class => [
                'class' => InvoiceService::class,
                'shared' => true,
                'autowire' => true,
            ],
            GatewaySubscriptionService::class => [
                'class' => GatewaySubscriptionService::class,
                'shared' => true,
                'autowire' => true,
            ],
            WebhookService::class => [
                'factory' => [self::class, 'makeWebhookService'],
                'shared' => true,
            ],
            GatewayManager::class => [
                'class' => GatewayManager::class,
                'shared' => true,
                'autowire' => true,
            ],
            PaystackGateway::class => [
                'class' => PaystackGateway::class,
                'shared' => true,
                'autowire' => true,
            ],
            StripeGateway::class => [
                'class' => StripeGateway::class,
                'shared' => true,
                'autowire' => true,
            ],
            PaymentController::class => [
                'class' => PaymentController::class,
                'shared' => true,
                'autowire' => true,
            ],
            BillingPlanController::class => [
                'class' => BillingPlanController::class,
                'shared' => true,
                'autowire' => true,
            ],
            InvoiceController::class => [
                'class' => InvoiceController::class,
                'shared' => true,
                'autowire' => true,
            ],
            WebhookController::class => [
                'class' => WebhookController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makeWebhookService(ContainerInterface $container): WebhookService
    {
        $context = $container->get(ApplicationContext::class);
        $subscriptions = $container->get(GatewaySubscriptionService::class);
        $queueEnabled = (bool) config($context, 'payvia.webhooks.queue', false);
        $queueName = (string) config($context, 'payvia.webhooks.queue_name', 'default');

        $dispatcher = static function (\Glueful\Extensions\Payvia\Events\PaymentProviderEvent $event) use ($container): void {
            if ($container->has(EventService::class)) {
                $container->get(EventService::class)->dispatch($event);
            }
        };

        $applier = static function (\Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface $event) use ($subscriptions): void {
            $subscriptions->applyProviderEvent($event);
        };

        $enqueue = static function (string $uuid) use ($container, $queueName): void {
            if (!$container->has(QueueManager::class)) {
                return;
            }
            $container->get(QueueManager::class)->push(
                \Glueful\Extensions\Payvia\Jobs\ProcessWebhookJob::class,
                ['provider_event_uuid' => $uuid],
                $queueName
            );
        };

        return new WebhookService(
            $context,
            $container->get(GatewayManager::class),
            $container->get(ProviderEventRepositoryInterface::class),
            $dispatcher,
            $applier,
            $queueEnabled,
            $enqueue
        );
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('payvia', require __DIR__ . '/../config/payvia.php');
    }

    public function boot(ApplicationContext $context): void
    {

        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'payvia',
                'name' => 'Payvia',
                'version' => $this->getVersion(),
                'description' => $this->getDescription(),
            ]);
        } catch (\Throwable $e) {
            error_log('[Payvia] Failed to register extension metadata: ' . $e->getMessage());
        }

         try {
            $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        } catch (\Throwable $e) {
            error_log('[Payvia] Failed to load routes: ' . $e->getMessage());
            $env = (string)($_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production'));
            if ($env !== 'production') {
                throw $e; // fail fast in non-production
            }
        }

         // 3) Register migrations directory. payments/invoices hold (FK-less) logical references to
         //    users.uuid — owned by glueful/users at IDENTITY — so payvia migrates at DEPENDENT
         //    (after identity + app) and records its source as glueful/payvia.
        try {
            $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::DEPENDENT, 'glueful/payvia');
        } catch (\Throwable $e) {
            error_log('[Payvia] Failed to register migrations: ' . $e->getMessage());
        }

        try {
            $this->discoverCommands('Glueful\\Extensions\\Payvia\\Console', __DIR__ . '/Console');
        } catch (\Throwable $e) {
            error_log('[Payvia] Failed to discover commands: ' . $e->getMessage());
        }
    }
}
