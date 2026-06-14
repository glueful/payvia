<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Controllers\PaymentController;
use Glueful\Extensions\Payvia\Controllers\BillingPlanController;
use Glueful\Extensions\Payvia\Controllers\InvoiceController;
use Glueful\Extensions\Payvia\Controllers\WebhookController;

/** @var Router $router Router instance injected by RouteManifest::load() */
/** @var ApplicationContext $context Application context injected by RouteManifest::load() */

// Middleware stack guarding billing-plan and invoice WRITE routes. Admin-only by
// default; hosts override via `payvia.security.manage_middleware` in config.
// Each write route appends its own `rate_limit:N,60` to this base stack.
$manageMiddleware = (array) config($context, 'payvia.security.manage_middleware', ['auth', 'admin']);

$router->group(['prefix' => '/payvia'], function (Router $router) use ($manageMiddleware) {
    // Provider webhooks (unauthenticated; signature verified inside Payvia)
    $router->post('/webhooks/{gateway}', [WebhookController::class, 'handle'])
        ->where('gateway', '[a-z0-9_]+');

    // Payments
    $router->post('/payments/confirm', [PaymentController::class, 'confirm'])
        ->middleware(['auth', 'rate_limit:60,60']);

    // Billing plans
    $router->post('/plans', [BillingPlanController::class, 'create'])
        ->middleware([...$manageMiddleware, 'rate_limit:30,60']);

    $router->post('/plans/update', [BillingPlanController::class, 'update'])
        ->middleware([...$manageMiddleware, 'rate_limit:30,60']);

    $router->post('/plans/disable', [BillingPlanController::class, 'disable'])
        ->middleware([...$manageMiddleware, 'rate_limit:30,60']);

    $router->get('/plans', [BillingPlanController::class, 'index'])
        ->middleware(['auth', 'rate_limit:60,60']);

    // Invoices
    $router->post('/invoices', [InvoiceController::class, 'create'])
        ->middleware([...$manageMiddleware, 'rate_limit:60,60']);

    $router->post('/invoices/mark-paid', [InvoiceController::class, 'markPaid'])
        ->middleware([...$manageMiddleware, 'rate_limit:60,60']);

    $router->post('/invoices/cancel', [InvoiceController::class, 'cancel'])
        ->middleware([...$manageMiddleware, 'rate_limit:60,60']);

    $router->get('/invoices', [InvoiceController::class, 'index'])
        ->middleware(['auth', 'rate_limit:60,60']);
});
