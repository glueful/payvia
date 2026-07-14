<?php

declare(strict_types=1);

/*
 * Payvia — Extension Configuration
 */

return [
    'default_gateway' => env('PAYVIA_DEFAULT_GATEWAY', 'paystack'),

    'gateways' => [
        'paystack' => [
            'enabled' => (bool) env('PAYVIA_PAYSTACK_ENABLED', true),
            'driver' => 'paystack',
            'secret_key' => env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null)),
            'webhook_secret' => env('PAYVIA_PAYSTACK_WEBHOOK_SECRET', env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null))),
            'base_url' => env('PAYVIA_PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'timeout' => (int) env('PAYVIA_PAYSTACK_TIMEOUT', 15),
        ],

        'stripe' => [
            'enabled' => (bool) env('PAYVIA_STRIPE_ENABLED', false),
            'driver' => 'stripe',
            'secret_key' => env('PAYVIA_STRIPE_SECRET_KEY', null),
            'webhook_secret' => env('PAYVIA_STRIPE_WEBHOOK_SECRET', null),
            'webhook_tolerance' => (int) env('PAYVIA_STRIPE_WEBHOOK_TOLERANCE', 300),
            'base_url' => env('PAYVIA_STRIPE_BASE_URL', 'https://api.stripe.com'),
            'timeout' => (int) env('PAYVIA_STRIPE_TIMEOUT', 15),
        ],
    ],

    'features' => [
        'store_raw_payload' => (bool) env('PAYVIA_STORE_RAW_PAYLOAD', true),
    ],

    'security' => [
        // Three ordered middleware profiles composed onto every /payvia/* route (except
        // the webhook route, which uses none of them and stays signature-authenticated/
        // tenantless). Payvia never names host-specific middleware aliases in these
        // defaults -- a tenancy-enabled host configures profile 2 itself (e.g.
        // `tenant_profile:admin`, `tenant_bootstrap`, `admin_tenant_binding`).
        //
        // Composition:
        //   - authenticated read/confirm routes: profile 1 -> 2
        //   - management routes (billing-plan/invoice writes): profile 1 -> 2 -> 3
        // Each write route still appends its own rate_limit:N,60 after the composed stack.
        //
        // 2.0 config break: v1's `manage_middleware` default was `['auth', 'admin']` -- the
        // `auth` entry moved to `auth_middleware`. A host that overrode `manage_middleware`
        // must move its authentication entries into `auth_middleware` and leave only
        // authorization checks here.
        'auth_middleware' => ['auth'],

        // Empty by default so single-store installs remain byte-identical to v1. A
        // tenancy-enabled host sets this to whatever establishes request-scoped tenant
        // context before Payvia's repositories run.
        'tenant_context_middleware' => [],

        // Authorization-only now (auth moved to profile 1 above). Defaults to admin-only.
        'manage_middleware' => ['admin'],
    ],

    'webhooks' => [
        'queue' => (bool) env('PAYVIA_WEBHOOKS_QUEUE', false),
        'queue_name' => env('PAYVIA_WEBHOOKS_QUEUE_NAME', 'default'),
        'relay_stale_seconds' => (int) env('PAYVIA_WEBHOOKS_RELAY_STALE_SECONDS', 300),
    ],
];
