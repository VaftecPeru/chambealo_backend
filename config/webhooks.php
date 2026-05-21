<?php

return [
    // Trusted IP addresses for webhooks
    'trusted_ips' => [
        // IziPay
        '52.20.136.89',
        '52.20.136.90',
        '52.55.97.100',
        
        // PayPal
        '66.211.169.3',
        '66.211.169.66',
        '195.101.176.201',
        '195.101.176.202',
        
        // Allow localhost for testing
        '127.0.0.1',
        '::1',
    ],

    // Webhook signature algorithms and keys per provider
    'providers' => [
        'izipay' => [
            'algorithm' => 'sha256',
            'key_env' => 'IZIPAY_HASH_KEY',
            'header' => 'kr-hash',
            'payload_header' => 'kr-answer',
        ],
        'paypal' => [
            'algorithm' => 'sha256',
            'key_env' => 'PAYPAL_SECRET',
            'verify_url' => 'https://api.paypal.com/v1/notifications/verify-webhook-signature',
            'header' => 'paypal-transmission-sig',
        ],
    ],

    // Replay attack prevention
    'replay_protection' => [
        'enabled' => true,
        'ttl_minutes' => 1440, // 24 hours
        'table' => 'webhook_requests',
    ],

    // Request timeout in seconds
    'timeout' => 30,

    // Rate limiting per IP (requests per minute)
    'rate_limit' => 60,
];
