<?php

return [
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),
    'default_currency' => env('PAYMENT_DEFAULT_CURRENCY', 'PEN'),
    'paypal_fx' => [
    'pen_per_usd' => env('PAYPAL_FX_PEN_PER_USD'),
    'source' => env('PAYPAL_FX_SOURCE'),
    'quoted_at' => env('PAYPAL_FX_QUOTED_AT'),
    'max_age_minutes' => 1440,
],
    'izipay' => [
        'environment' => env('IZIPAY_ENV', 'sandbox'),
        'client_id' => env('IZIPAY_CLIENT_ID'),
        'secret' => env('IZIPAY_SECRET'),
        'hash_key' => env('IZIPAY_HASH_KEY'),
        'webhook_secret' => env('IZIPAY_WEBHOOK_SECRET'),
        'url' => env('IZIPAY_URL', 'https://api.izipay.pe'),
        'public_key' => env('IZIPAY_PUBLIC_KEY'),
    ],

    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
    ],

    'paypal' => [
        'environment' => env('PAYPAL_ENV', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],
];
