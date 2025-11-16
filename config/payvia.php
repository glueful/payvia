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
            'base_url' => env('PAYVIA_PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'timeout' => (int) env('PAYVIA_PAYSTACK_TIMEOUT', 15),
        ],

        'stripe' => [
            'enabled' => (bool) env('PAYVIA_STRIPE_ENABLED', false),
            'driver' => 'stripe',
            'secret_key' => env('PAYVIA_STRIPE_SECRET_KEY', null),
        ],

        'flutterwave' => [
            'enabled' => (bool) env('PAYVIA_FLUTTERWAVE_ENABLED', false),
            'driver' => 'flutterwave',
            'secret_key' => env('PAYVIA_FLUTTERWAVE_SECRET_KEY', null),
        ],
    ],

    'features' => [
        'store_raw_payload' => (bool) env('PAYVIA_STORE_RAW_PAYLOAD', true),
    ],
];
