<?php

return [
    // ============================================
    // CONFIGURACIÓN DE SEGURIDAD GLOBAL
    // ============================================
    'security' => [
        // Requerir HTTPS para webhooks en producción
        'require_https_webhooks' => env('PAYMENT_REQUIRE_HTTPS', true),

        // Ventana de tiempo para validar timestamps (segundos)
        'webhook_timestamp_window' => env('PAYMENT_WEBHOOK_TIMESTAMP_WINDOW', 300), // 5 minutos

        // Rate limiting por IP para webhooks
        'webhook_rate_limit' => [
            'max_requests' => env('PAYMENT_WEBHOOK_RATE_LIMIT_MAX', 100),
            'window_seconds' => env('PAYMENT_WEBHOOK_RATE_LIMIT_WINDOW', 60),
        ],

        // Prevención de replay attacks
        'replay_attack_prevention' => env('PAYMENT_REPLAY_PREVENTION', true),

        // Almacenar logs de webhook en BD
        'log_webhooks_to_db' => env('PAYMENT_LOG_WEBHOOKS_DB', true),
    ],

    // ============================================
    // CONFIGURACIÓN IZIPAY
    // ============================================
    'izipay' => [
        'environment' => env('IZIPAY_ENV', 'sandbox'),
        'client_id' => env('IZIPAY_CLIENT_ID'),
        'secret' => env('IZIPAY_SECRET'),
        'hash_key' => env('IZIPAY_HASH_KEY'),
        'webhook_secret' => env('IZIPAY_WEBHOOK_SECRET'),
        'url' => env('IZIPAY_URL', 'https://api.izipay.pe'),
        'public_key' => env('IZIPAY_PUBLIC_KEY'),

        // Configuración específica de Izipay
        'min_amount' => env('IZIPAY_MIN_AMOUNT', 0.01),
        'max_amount' => env('IZIPAY_MAX_AMOUNT', 999999.99),
        'timeout' => env('IZIPAY_TIMEOUT', 30),
    ],

    // ============================================
    // CONFIGURACIÓN MERCADOPAGO
    // ============================================
    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),

        // Configuración específica de MercadoPago
        'min_amount' => env('MERCADOPAGO_MIN_AMOUNT', 0.01),
        'max_amount' => env('MERCADOPAGO_MAX_AMOUNT', 999999.99),
        'timeout' => env('MERCADOPAGO_TIMEOUT', 30),
    ],

    // ============================================
    // CONFIGURACIÓN PAYPAL
    // ============================================
    'paypal' => [
        'environment' => env('PAYPAL_ENV', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),

        // Configuración específica de PayPal
        'min_amount' => env('PAYPAL_MIN_AMOUNT', 0.01),
        'max_amount' => env('PAYPAL_MAX_AMOUNT', 999999.99),
        'timeout' => env('PAYPAL_TIMEOUT', 30),
    ],

    // ============================================
    // CONFIGURACIÓN DE LOGGING
    // ============================================
    'logging' => [
        // Canal de log para pagos
        'channel' => env('PAYMENT_LOG_CHANNEL', 'payment'),

        // Log detallado de webhooks
        'log_webhook_payloads' => env('PAYMENT_LOG_WEBHOOK_PAYLOADS', false), // NUNCA activar en producción

        // Log detallado de intentos fallidos
        'log_failed_attempts' => env('PAYMENT_LOG_FAILED_ATTEMPTS', true),
    ],

    // ============================================
    // CONFIGURACIÓN DE VALIDACIÓN (VAFTEC)
    // ============================================
    'validation' => [
        // Validar monto desde backend
        'validate_amount_backend' => true,

        // Moneda predeterminada
        'default_currency' => env('PAYMENT_DEFAULT_CURRENCY', 'USD'),

        // Monedas permitidas
        'allowed_currencies' => explode(',', env('PAYMENT_ALLOWED_CURRENCIES', 'USD,PEN,MXN,ARS,CLP,EUR')),

        // Monto mínimo global
        'min_amount' => env('PAYMENT_MIN_AMOUNT', 0.01),

        // Monto máximo global
        'max_amount' => env('PAYMENT_MAX_AMOUNT', 999999.99),
    ],

    // ============================================
    // DIRECCIONES DE REDIRECCIÓN
    // ============================================
    'redirects' => [
        'success' => env('PAYMENT_REDIRECT_SUCCESS', '/payment/success'),
        'cancel' => env('PAYMENT_REDIRECT_CANCEL', '/payment/cancel'),
    ],
];
