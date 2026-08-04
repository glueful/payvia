<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Events\EventService;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmationHandler;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutService;
use Glueful\Extensions\Payvia\Contracts\BillingPlanRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\InvoiceRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\LogicalDispatchLeaseRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Payvia\Controllers\BillingPlanController;
use Glueful\Extensions\Payvia\Controllers\InvoiceController;
use Glueful\Extensions\Payvia\Controllers\PaymentController;
use Glueful\Extensions\Payvia\Controllers\WebhookController;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\Events\ProviderChargebackDispatcher;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Extensions\Payvia\Jobs\ProcessWebhookJob;
use Glueful\Extensions\Payvia\Repositories\BillingPlanRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
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
use Glueful\Extensions\Payvia\Services\PayviaPayoutCollector;
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
            CheckoutOriginationRepository::class => [
                'factory' => [self::class, 'makeCheckoutOriginationRepository'],
                'shared' => true,
            ],
            CheckoutSubjectGuardRepository::class => [
                'factory' => [self::class, 'makeCheckoutSubjectGuardRepository'],
                'shared' => true,
            ],
            SubscriptionCheckoutService::class => [
                'factory' => [self::class, 'makeSubscriptionCheckoutService'],
                'shared' => true,
            ],
            PaymentCollector::class => [
                'class' => PayviaPaymentCollector::class,
                'shared' => true,
                'autowire' => true,
            ],
            PayoutTransferRepository::class => [
                'factory' => [self::class, 'makePayoutTransferRepository'],
                'shared' => true,
            ],
            PayoutCollector::class => [
                'class' => PayviaPayoutCollector::class,
                'shared' => true,
                'autowire' => true,
            ],
            ConfirmationDispatcher::class => [
                'factory' => [self::class, 'makeConfirmationDispatcher'],
                'shared' => true,
            ],
            ProviderChargebackDispatcher::class => [
                'factory' => [self::class, 'makeProviderChargebackDispatcher'],
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

    public static function makePayoutTransferRepository(ContainerInterface $container): PayoutTransferRepository
    {
        return new PayoutTransferRepository(
            context: $container->get(ApplicationContext::class),
            resolver: $container->get(PayviaTenantResolver::class),
        );
    }

    /**
     * `connection:` is passed EXPLICITLY (not left to `BaseRepository::getSharedConnection()`'s
     * implicit static-cache fallback) so this repository is PROVABLY bound to the exact same
     * `Connection` instance the container resolves for `Connection::class` everywhere else --
     * the same one {@see makeCheckoutSubjectGuardRepository()} binds and
     * {@see SubscriptionCheckoutService::__construct()} asserts both repositories share.
     * Relying on the implicit static cache instead would make that guarantee depend on
     * construction ORDER and on nothing else in the process ever seeding the cache from a
     * different connection first -- exactly the kind of silent cross-connection drift that
     * would make `prepare()`'s one-transaction guarantee a no-op under pooling or a
     * differently-ordered boot.
     */
    public static function makeCheckoutOriginationRepository(
        ContainerInterface $container
    ): CheckoutOriginationRepository {
        return new CheckoutOriginationRepository(
            connection: $container->get(Connection::class),
            context: $container->get(ApplicationContext::class),
            resolver: $container->get(PayviaTenantResolver::class),
        );
    }

    /** @see makeCheckoutOriginationRepository() for why `connection:` is passed explicitly. */
    public static function makeCheckoutSubjectGuardRepository(
        ContainerInterface $container
    ): CheckoutSubjectGuardRepository {
        return new CheckoutSubjectGuardRepository(
            connection: $container->get(Connection::class),
            context: $container->get(ApplicationContext::class),
        );
    }

    /**
     * Shares the SAME `PayviaTenantResolver` instance the origination repository resolves
     * `tenant_uuid` from internally, so the tenant this service resolves for the subject guard
     * (which takes `tenantUuid` as an explicit parameter, never via its own resolver) always
     * agrees with the row the origination repository itself just wrote or read. No `Connection`
     * is passed here: the service derives its transaction manager from the origination
     * repository's OWN connection (see its constructor), which is provably the same connection
     * the guard repository uses too (both bound explicitly above).
     */
    public static function makeSubscriptionCheckoutService(ContainerInterface $container): SubscriptionCheckoutService
    {
        return new SubscriptionCheckoutService(
            originations: $container->get(CheckoutOriginationRepository::class),
            guards: $container->get(CheckoutSubjectGuardRepository::class),
            gateways: $container->get(GatewayManager::class),
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

    /**
     * The chargeback dispatcher's own `$dispatch` callable is a THIN wrapper around
     * `EventService::dispatchOrFail()` -- same optional-container-presence guard
     * `makeWebhookService`'s local `PaymentProviderEvent` dispatcher already uses, but STRICT
     * dispatch rather than the fault-isolated `dispatch()` ordinary events use. Framework
     * `EventDispatcher::dispatchOrFail()` logs then RETHROWS the original exception from the
     * first listener that fails, stopping delivery -- so a downstream contracts-listener
     * exception (e.g. from a subscribed chargeback-ingestion consumer) DOES propagate straight
     * back out of this callable, through `ProviderChargebackDispatcher::handle()`, out of
     * `makeWebhookService()`'s composed callback, and out of `WebhookService::dispatch()`,
     * leaving the triggering `provider_events` row's logical dispatch unmarked. Because listener
     * delivery is therefore at-least-once, every listener wired to `ProviderChargebackEvent` MUST
     * be idempotent.
     *
     * This chargeback lane is now the SECOND of two strict lanes `makeWebhookService()` composes:
     * first the tagged {@see StrictPaymentEventListener} lane (also uncaught, also at-least-once,
     * see that interface's docblock), then this chargeback dispatcher, always last. Both share
     * the exact same release-on-failure semantics -- a throw from either one releases (or, absent
     * the lease capability, leaves stuck for a later stale-claim sweep) the SAME in-flight
     * logical-dispatch lease/claim `WebhookService::dispatch()` holds for the whole composed
     * callable, not a lane-local one. Ordinary local `PaymentProviderEvent` delivery in
     * `makeWebhookService()` deliberately stays on the fault-isolated `dispatch()` path -- only
     * the tagged strict lane and the chargeback event go strict.
     */
    public static function makeProviderChargebackDispatcher(
        ContainerInterface $container
    ): ProviderChargebackDispatcher {
        return new ProviderChargebackDispatcher(
            $container->get(ProviderCorrelationRepository::class),
            static function (ProviderChargebackEvent $event) use ($container): void {
                if ($container->has(EventService::class)) {
                    $container->get(EventService::class)->dispatchOrFail($event);
                }
            }
        );
    }

    /**
     * Validates and normalizes the raw iterable a container tag resolves into
     * {@see StrictPaymentEventListener::CONTAINER_TAG} into the deterministic list
     * `makeWebhookService()`'s composed dispatcher iterates. Every item MUST implement the
     * contract -- a non-implementing tagged value is a wiring mistake, not something to silently
     * skip, so it throws naming the offending value's type. Two tagged instances of the SAME
     * concrete class are rejected the same way, naming the class, since that would either double-
     * deliver or mask which instance actually ran. The FQCN sort makes lane order deterministic
     * across requests/processes regardless of container resolution/tagging order.
     *
     * @param iterable<mixed> $tagged
     * @return list<StrictPaymentEventListener>
     */
    public static function composeStrictLane(iterable $tagged): array
    {
        $byClass = [];
        foreach ($tagged as $item) {
            if (!$item instanceof StrictPaymentEventListener) {
                throw new \LogicException(sprintf(
                    'Tagged strict payment-event listener %s does not implement %s.',
                    get_debug_type($item),
                    StrictPaymentEventListener::class
                ));
            }
            $class = get_class($item);
            if (isset($byClass[$class])) {
                throw new \LogicException("Duplicate strict payment-event listener class {$class}.");
            }
            $byClass[$class] = $item;
        }
        ksort($byClass, SORT_STRING);

        return array_values($byClass);
    }

    public static function makeWebhookService(ContainerInterface $container): WebhookService
    {
        $context = $container->get(ApplicationContext::class);
        $subscriptions = $container->get(GatewaySubscriptionService::class);
        $chargebacks = $container->get(ProviderChargebackDispatcher::class);
        $queueEnabled = (bool) config($context, 'payvia.webhooks.queue', false);
        $queueName = (string) config($context, 'payvia.webhooks.queue_name', 'default');

        // Resolved ONCE and reused both as the durable `ProviderEventRepositoryInterface` and,
        // when it also implements the additive lease capability, as WebhookService's optional
        // final constructor argument. A custom legacy implementation that doesn't implement
        // LogicalDispatchLeaseRepositoryInterface simply passes null here and WebhookService
        // keeps its byte-identical claim/reclaim/mark fallback -- construction never fails.
        $events = $container->get(ProviderEventRepositoryInterface::class);
        $logicalDispatchLeases = $events instanceof LogicalDispatchLeaseRepositoryInterface ? $events : null;

        // Composed ONCE per service construction (not per dispatch) from whatever is currently
        // tagged under StrictPaymentEventListener::CONTAINER_TAG -- an absent tag, or a container
        // that plainly can't resolve an iterable from it, behaves exactly like an empty lane.
        $strict = self::composeStrictLane(
            $container->has(StrictPaymentEventListener::CONTAINER_TAG)
                && is_iterable($tagged = $container->get(StrictPaymentEventListener::CONTAINER_TAG))
                ? $tagged
                : []
        );

        // FIRST preserve ordinary local delivery (unconditional, unaffected by dispute
        // recognition, fault-isolated -- unchanged), THEN the opt-in tagged strict lane, THEN
        // delegate recognized dispute/chargeback types to the named dispatcher, always last.
        // Nothing here catches a strict listener's or ProviderChargebackDispatcher::handle()'s
        // exceptions (UnresolvedPaymentOwnershipException, or any failure from its injected
        // $dispatch callable) -- they propagate straight out of this callback, so
        // WebhookService::dispatch() never reaches markLogicalDispatched() and the durable
        // provider_events row stays redispatchable via relayPending() (or, on the lease path,
        // immediately via processStored()). An empty $strict lane makes this callback
        // behaviorally identical to the pre-lane dispatcher.
        $dispatcher = static function (PaymentProviderEvent $event) use ($container, $chargebacks, $strict): void {
            if ($container->has(EventService::class)) {
                $container->get(EventService::class)->dispatch($event);
            }

            foreach ($strict as $listener) {
                if ($listener->supports($event->event)) {
                    $listener->handle($event->event);
                }
            }

            $chargebacks->handle($event->event);
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
            $events,
            $dispatcher,
            $applier,
            $queueEnabled,
            $enqueue,
            $logicalDispatchLeases
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
