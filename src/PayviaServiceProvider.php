<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Events\EventService;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmationHandler;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Glueful\Extensions\Payvia\Contracts\BillingPlanRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\InvoiceRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Extensions\Payvia\Controllers\BillingPlanController;
use Glueful\Extensions\Payvia\Controllers\InvoiceController;
use Glueful\Extensions\Payvia\Controllers\PaymentController;
use Glueful\Extensions\Payvia\Controllers\WebhookController;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Extensions\Payvia\Jobs\ProcessWebhookJob;
use Glueful\Extensions\Payvia\Repositories\BillingPlanRepository;
use Glueful\Extensions\Payvia\Repositories\InvoiceRepository;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Repositories\PaymentRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\BillingPlanService;
use Glueful\Extensions\Payvia\Services\ConfirmationDispatcher;
use Glueful\Extensions\Payvia\Services\GatewaySubscriptionService;
use Glueful\Extensions\Payvia\Services\InvoiceService;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Extensions\Payvia\Services\PayviaPaymentCollector;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Support\DiagnosticsReport;
use Glueful\Extensions\Payvia\Tenancy\FailClosedTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\TenantAdopter;
use Glueful\Extensions\ServiceProvider;
use Glueful\Queue\QueueManager;
use Psr\Container\ContainerInterface;

final class PayviaServiceProvider extends ServiceProvider
{
    private static ?string $cachedVersion = null;

    /**
     * Read the extension version from composer.json's `extra.glueful.version` field (cached).
     * That field -- not a top-level `version` key, which Composer discourages and this
     * manifest doesn't declare -- is the extension installer's source of truth.
     */
    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $path = __DIR__ . '/../composer.json';
            $raw = @file_get_contents($path);
            if ($raw === false) {
                return self::$cachedVersion = '0.0.0';
            }

            $composer = json_decode($raw, true);
            if (!is_array($composer)) {
                return self::$cachedVersion = '0.0.0';
            }

            $version = $composer['extra']['glueful']['version'] ?? '0.0.0';
            self::$cachedVersion = is_string($version) ? $version : '0.0.0';
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

    /**
     * Payvia binds ONLY the local `PayviaTenantResolver` seam under its own contract id -- it
     * NEVER binds or replaces the shared `CurrentTenantResolver` contract. Interactive
     * repository factories resolve `PayviaTenantResolver` from the container; `WebhookService`/
     * `GatewaySubscriptionService` route their tenantless correlation work through
     * `ProviderCorrelationRepository` instead.
     *
     * @return array<string, mixed>
     */
    public static function services(): array
    {
        return [
            PayviaTenantResolver::class => [
                'factory' => [self::class, 'makePayviaTenantResolver'],
                'shared' => true,
            ],
            TenantAdopter::class => [
                'class' => TenantAdopter::class,
                'shared' => true,
            ],
            PaymentRepositoryInterface::class => [
                'factory' => [self::class, 'makePaymentRepository'],
                'shared' => true,
            ],
            BillingPlanRepositoryInterface::class => [
                'factory' => [self::class, 'makeBillingPlanRepository'],
                'shared' => true,
            ],
            InvoiceRepositoryInterface::class => [
                'factory' => [self::class, 'makeInvoiceRepository'],
                'shared' => true,
            ],
            ProviderEventRepositoryInterface::class => [
                'class' => ProviderEventRepository::class,
                'shared' => true,
            ],
            ProviderCorrelationRepository::class => [
                'factory' => [self::class, 'makeProviderCorrelationRepository'],
                'shared' => true,
            ],
            PaymentIntentRepository::class => [
                'factory' => [self::class, 'makePaymentIntentRepository'],
                'shared' => true,
            ],
            PaymentCollector::class => [
                'class' => PayviaPaymentCollector::class,
                'shared' => true,
                'autowire' => true,
            ],
            ConfirmationDispatcher::class => [
                'factory' => [self::class, 'makeConfirmationDispatcher'],
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

    /**
     * Shared `CurrentTenantResolver` present -> validate it and wrap fail-closed. Absent
     * (single-store) -> the sentinel. Payvia never binds the shared contract itself.
     */
    public static function makePayviaTenantResolver(ContainerInterface $container): PayviaTenantResolver
    {
        if (!$container->has(CurrentTenantResolver::class)) {
            return new SentinelTenantResolver();
        }

        $shared = $container->get(CurrentTenantResolver::class);
        if (!$shared instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return new FailClosedTenantResolver($shared);
    }

    public static function makePaymentRepository(ContainerInterface $container): PaymentRepository
    {
        return new PaymentRepository(
            context: $container->get(ApplicationContext::class),
            resolver: $container->get(PayviaTenantResolver::class),
        );
    }

    public static function makeInvoiceRepository(ContainerInterface $container): InvoiceRepository
    {
        return new InvoiceRepository(
            context: $container->get(ApplicationContext::class),
            resolver: $container->get(PayviaTenantResolver::class),
        );
    }

    public static function makeBillingPlanRepository(ContainerInterface $container): BillingPlanRepository
    {
        return new BillingPlanRepository(
            context: $container->get(ApplicationContext::class),
            resolver: $container->get(PayviaTenantResolver::class),
        );
    }

    public static function makePaymentIntentRepository(ContainerInterface $container): PaymentIntentRepository
    {
        return new PaymentIntentRepository(
            context: $container->get(ApplicationContext::class),
            resolver: $container->get(PayviaTenantResolver::class),
        );
    }

    /**
     * `tenancyResolverPresent` mirrors the exact condition `makePayviaTenantResolver()` uses to
     * decide sentinel-vs-fail-closed: whether the HOST has bound the shared `CurrentTenantResolver`
     * contract. When it has, a `TenantContextRunner` must also be resolvable, or construction
     * fails closed immediately rather than silently running unscoped correlation queries later.
     */
    public static function makeProviderCorrelationRepository(
        ContainerInterface $container
    ): ProviderCorrelationRepository {
        $resolverPresent = $container->has(CurrentTenantResolver::class);

        $runner = null;
        if ($container->has(TenantContextRunner::class)) {
            $candidate = $container->get(TenantContextRunner::class);
            $runner = $candidate instanceof TenantContextRunner ? $candidate : null;
        }

        return new ProviderCorrelationRepository(
            context: $container->get(ApplicationContext::class),
            tenancyResolverPresent: $resolverPresent,
            runner: $runner,
        );
    }

    public static function makeConfirmationDispatcher(ContainerInterface $container): ConfirmationDispatcher
    {
        $handlers = $container->has(PaymentConfirmationHandler::CONTAINER_TAG)
            ? $container->get(PaymentConfirmationHandler::CONTAINER_TAG)
            : [];

        return new ConfirmationDispatcher(
            $container->get(PaymentIntentRepository::class),
            is_iterable($handlers) ? $handlers : []
        );
    }

    public static function makeWebhookService(ContainerInterface $container): WebhookService
    {
        $context = $container->get(ApplicationContext::class);
        $subscriptions = $container->get(GatewaySubscriptionService::class);
        $queueEnabled = (bool) config($context, 'payvia.webhooks.queue', false);
        $queueName = (string) config($context, 'payvia.webhooks.queue_name', 'default');

        $dispatcher = static function (PaymentProviderEvent $event) use ($container): void {
            if ($container->has(EventService::class)) {
                $container->get(EventService::class)->dispatch($event);
            }
        };

        $applier = static function (PaymentProviderEventInterface $event) use ($subscriptions): void {
            $subscriptions->applyProviderEvent($event);
        };

        $enqueue = static function (string $uuid) use ($container, $queueName): void {
            if (!$container->has(QueueManager::class)) {
                return;
            }
            $container->get(QueueManager::class)->push(
                ProcessWebhookJob::class,
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
            $container = container($context);
            if ($container->has(TenantTableRegistry::class)) {
                $registry = $container->get(TenantTableRegistry::class);
                if ($registry instanceof TenantTableRegistry) {
                    $registry->register(DiagnosticsReport::tenantTables());
                }
            }
        } catch (\Throwable $e) {
            error_log('[Payvia] Failed to register tenant tables: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }

        try {
            $this->discoverCommands('Glueful\\Extensions\\Payvia\\Console', __DIR__ . '/Console');
        } catch (\Throwable $e) {
            error_log('[Payvia] Failed to discover commands: ' . $e->getMessage());
        }
    }

    private function bootEnv(): string
    {
        return (string) ($_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production'));
    }
}
