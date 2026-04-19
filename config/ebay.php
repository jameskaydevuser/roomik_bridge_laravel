<?php

return [
    'mode' => env('EBAY_MODE', 'sandbox'), // 'sandbox' or 'production'
    
    'sandbox' => [
        'client_id' => env('EBAY_SANDBOX_CLIENT_ID'),
        'client_secret' => env('EBAY_SANDBOX_CLIENT_SECRET'),
        'dev_id' => env('EBAY_SANDBOX_DEV_ID'),
        'ru_name' => env('EBAY_SANDBOX_RU_NAME'),
        'auth_token' => env('EBAY_SANDBOX_AUTH_TOKEN'), // For Trading API
    ],
    
    'production' => [
        'client_id' => env('EBAY_PROD_CLIENT_ID'),
        'client_secret' => env('EBAY_PROD_CLIENT_SECRET'),
        'dev_id' => env('EBAY_PROD_DEV_ID'),
        'ru_name' => env('EBAY_PROD_RU_NAME'),
        'auth_token' => env('EBAY_PROD_AUTH_TOKEN'),
    ],

    'api_endpoints' => [
        'trading' => [
            'sandbox' => 'https://api.sandbox.ebay.com/ws/api.dll',
            'production' => 'https://api.ebay.com/ws/api.dll',
        ],
        'oauth' => [
            'sandbox' => 'https://api.sandbox.ebay.com/identity/v1/oauth2/token',
            'production' => 'https://api.ebay.com/identity/v1/oauth2/token',
        ],
    ],
];
