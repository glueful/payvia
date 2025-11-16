<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia;

use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\BillingPlanRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\InvoiceRepositoryInterface;
use Glueful\Extensions\Payvia\Repositories\PaymentRepository;
use Glueful\Extensions\Payvia\Repositories\BillingPlanRepository;
use Glueful\Extensions\Payvia\Repositories\InvoiceRepository;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Extensions\Payvia\Services\BillingPlanService;
use Glueful\Extensions\Payvia\Services\InvoiceService;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;

final class PayviaServiceProvider extends ServiceProvider
{
    public function getName(): string
    {
        return 'Payvia';
    }

    public function getVersion(): string
    {
        return '0.1.0';
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
            PaymentService::class => [
                'class' => PaymentService::class,
                'shared' => true,
            ],
            BillingPlanService::class => [
                'class' => BillingPlanService::class,
                'shared' => true,
            ],
            InvoiceService::class => [
                'class' => InvoiceService::class,
                'shared' => true,
            ],
            GatewayManager::class => [
                'class' => GatewayManager::class,
                'shared' => true,
            ],
            PaystackGateway::class => [
                'class' => PaystackGateway::class,
                'shared' => true,
            ],
        ];
    }

    public function register(): void
    {
        $this->mergeConfig('payvia', require __DIR__ . '/../config/payvia.php');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');

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
    }
}
