<?php

declare(strict_types=1);

/*
 * Payvia — Extension Configuration
 */

return [
    'default_gateway' => env('PAYVIA_DEFAULT_GATEWAY', 'paystack'),

    // How long a PROVIDER-CONFIRMED "this hosted session is still live" answer is trusted before
    // ensure-live asks the provider again. Repeat initiations inside the window reuse the stored
    // checkout URL with no provider I/O, so a shopper clicking "pay" repeatedly (or an abusive
    // client) cannot turn one checkout into a stream of provider round trips -- which would invite
    // provider rate limiting, and a rate-limited answer is an UNKNOWN state that fails closed for
    // every shopper at once. Only a confirmed-live probe refreshes the stamp; dead/unknown answers
    // never do, and a brand-new attempt is never suppressed. Set to 0 to always probe.
    'session_liveness_cooldown_seconds' => (int) env('PAYVIA_SESSION_LIVENESS_COOLDOWN_SECONDS', 30),

    'gateways' => [
        'paystack' => [
            'enabled' => (bool) env('PAYVIA_PAYSTACK_ENABLED', true),
            'driver' => 'paystack',
            'secret_key' => env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null)),
            'webhook_secret' => env('PAYVIA_PAYSTACK_WEBHOOK_SECRET', env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null))),
            // The maintainer's own declaration of what is configured, right now, on the
            // Paystack dashboard as this app's webhook URL. Paystack exposes no read API for
            // it, so `payvia:checkout:sandbox-proof` treats this as ground truth and fails
            // closed unless its path is exactly /payvia/webhooks/paystack -- see
            // src/Support/CheckoutSandboxProof/SandboxProofPreflight.php.
            'webhook_url' => env('PAYVIA_PAYSTACK_WEBHOOK_URL', null),
            'base_url' => env('PAYVIA_PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'timeout' => (int) env('PAYVIA_PAYSTACK_TIMEOUT', 15),
            // OPERATOR REQUIREMENT: the Paystack integration setting `payment_session_timeout`
            // (GET/PUT /integration/payment_session_timeout) MUST stay at its default of 0 (never
            // expire). A non-zero value that elapses dead-ends the hosted checkout page while
            // /transaction/verify still reports the transaction as `abandoned` -- indistinguishable
            // from a live one, so payvia would keep serving a URL nobody can pay. See the
            // PaystackGateway class docblock; payvia deliberately does not guess at elapsed time.
            // Hosted-redirect trust boundary: the ONLY hosts a returned `authorization_url` may
            // live on. Matching is case-normalized but otherwise exact -- no subdomains, no
            // ports, no userinfo, HTTPS only (see Support\HostedCheckoutUrl). Narrow this (or
            // point it at a sandbox host) only if you know why; an empty array trusts nothing
            // and refuses every checkout URL.
            'checkout_hosts' => ['checkout.paystack.com'],
        ],

        'stripe' => [
            'enabled' => (bool) env('PAYVIA_STRIPE_ENABLED', false),
            'driver' => 'stripe',
            'secret_key' => env('PAYVIA_STRIPE_SECRET_KEY', null),
            'webhook_secret' => env('PAYVIA_STRIPE_WEBHOOK_SECRET', null),
            'webhook_tolerance' => (int) env('PAYVIA_STRIPE_WEBHOOK_TOLERANCE', 300),
            'base_url' => env('PAYVIA_STRIPE_BASE_URL', 'https://api.stripe.com'),
            'timeout' => (int) env('PAYVIA_STRIPE_TIMEOUT', 15),
            // See the paystack note above -- same trust boundary, applied to the Checkout
            // Session `url` for both one-time and subscription sessions.
            'checkout_hosts' => ['checkout.stripe.com'],
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
