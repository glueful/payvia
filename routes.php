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

// Three ordered middleware profiles (spec: "Tenant context on HTTP routes"). Payvia never
// names host-specific middleware aliases in its defaults -- a tenancy-enabled host configures
// profile 2 itself. 2.0 config break: v1's manage_middleware default was ['auth', 'admin'];
// the auth entry now lives in auth_middleware, and manage_middleware is authorization-only.
$authMiddleware = (array) config($context, 'payvia.security.auth_middleware', ['auth']);
$tenantContextMiddleware = (array) config($context, 'payvia.security.tenant_context_middleware', []);
$manageMiddleware = (array) config($context, 'payvia.security.manage_middleware', ['admin']);

// Authenticated read/confirm routes: profile 1 -> 2.
$readMiddleware = [...$authMiddleware, ...$tenantContextMiddleware];
// Management (billing-plan/invoice write) routes: profile 1 -> 2 -> 3. Each write route
// still appends its own rate_limit:N,60 to this composed stack.
$writeMiddleware = [...$authMiddleware, ...$tenantContextMiddleware, ...$manageMiddleware];

$router->group(['prefix' => '/payvia'], function (Router $router) use ($readMiddleware, $writeMiddleware) {
    // Provider webhooks (unauthenticated; signature verified inside Payvia). Uses none of
    // the three profiles -- stays signature-authenticated/tenantless.
    $router->post('/webhooks/{gateway}', [WebhookController::class, 'handle'])
        ->where('gateway', '[a-z0-9_]+');

    // Payments
    $router->post('/payments/confirm', [PaymentController::class, 'confirm'])
        ->middleware([...$readMiddleware, 'rate_limit:60,60']);

    // Billing plans
    $router->post('/plans', [BillingPlanController::class, 'create'])
        ->middleware([...$writeMiddleware, 'rate_limit:30,60']);

    $router->post('/plans/update', [BillingPlanController::class, 'update'])
        ->middleware([...$writeMiddleware, 'rate_limit:30,60']);

    $router->post('/plans/disable', [BillingPlanController::class, 'disable'])
        ->middleware([...$writeMiddleware, 'rate_limit:30,60']);

    $router->get('/plans', [BillingPlanController::class, 'index'])
        ->middleware([...$readMiddleware, 'rate_limit:60,60']);

    // Invoices
    $router->post('/invoices', [InvoiceController::class, 'create'])
        ->middleware([...$writeMiddleware, 'rate_limit:60,60']);

    $router->post('/invoices/mark-paid', [InvoiceController::class, 'markPaid'])
        ->middleware([...$writeMiddleware, 'rate_limit:60,60']);

    $router->post('/invoices/cancel', [InvoiceController::class, 'cancel'])
        ->middleware([...$writeMiddleware, 'rate_limit:60,60']);

    $router->get('/invoices', [InvoiceController::class, 'index'])
        ->middleware([...$readMiddleware, 'rate_limit:60,60']);
});
