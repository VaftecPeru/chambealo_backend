<?php

return [
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
    ],
];
