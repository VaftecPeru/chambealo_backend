<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Rate Limits Configuration
    |--------------------------------------------------------------------------
    */
    
    'default' => [
        'per_minute' => 60,
        'per_hour' => 1000,
        'per_day' => 10000,
    ],
    
    'payment' => [
        'per_minute' => 5,
        'per_hour' => 20,
        'per_day' => 50,
        'per_user_minute' => 3,
        'per_ip_minute' => 5,
    ],
    
    'checkout' => [
        'per_minute' => 10,
        'per_hour' => 50,
        'per_day' => 200,
        'per_user_minute' => 5,
        'per_ip_minute' => 10,
    ],
    
    'order' => [
        'per_minute' => 20,
        'per_hour' => 100,
        'per_day' => 500,
        'per_user_minute' => 10,
        'per_ip_minute' => 20,
    ],
    
    'status' => [
        'per_minute' => 30,
        'per_hour' => 200,
        'per_day' => 1000,
        'per_user_minute' => 15,
        'per_ip_minute' => 30,
    ],
    
    'refund' => [
        'per_minute' => 3,
        'per_hour' => 10,
        'per_day' => 30,
        'per_user_minute' => 2,
        'per_ip_minute' => 3,
    ],
    
    'cancel' => [
        'per_minute' => 3,
        'per_hour' => 10,
        'per_day' => 30,
        'per_user_minute' => 2,
        'per_ip_minute' => 3,
    ],
    
    'connect' => [
        'per_minute' => 60,
        'per_hour' => 500,
        'per_day' => 5000,
    ],
    
    'health' => [
        'per_minute' => 30,
        'per_hour' => 200,
        'per_day' => 1000,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Alertas cuando se acerca al límite
    |--------------------------------------------------------------------------
    */
    'alert_thresholds' => [50, 70, 80, 90, 95],
    
    /*
    |--------------------------------------------------------------------------
    | Headers a incluir en respuesta
    |--------------------------------------------------------------------------
    */
    'headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
    ],
];