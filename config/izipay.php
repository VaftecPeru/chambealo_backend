<?php

return [
    'client_id' => env('IZIPAY_CLIENT_ID'),
    'secret'    => env('IZIPAY_CLIENT_SECRET'),
    'public_key'=> env('IZIPAY_PUBLIC_KEY'),
    'hash_key'  => env('IZIPAY_HASH_KEY'),
    'url'       => env('IZIPAY_URL', 'https://api.izipay.pe'),
];