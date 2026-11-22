<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gowa' => [
        'url' => env('GOWA_API_URL'),
        'basic_auth_user' => env('GOWA_BASIC_AUTH_USER'),
        'basic_auth_password' => env('GOWA_BASIC_AUTH_PASSWORD'),
        'device_id' => env('GOWA_DEVICE_ID'),
        'auto_sync' => (bool) env('GOWA_AUTO_SYNC', true),
        'force_sync' => (bool) env('GOWA_FORCE_SYNC', false),
    ],

    'mikrotik' => [
        'verify_tls' => (bool) env('MIKROTIK_VERIFY_TLS', true),
        'ca_bundle' => env('MIKROTIK_CA_BUNDLE'),
        'allow_ssh_fallback' => (bool) env('MIKROTIK_ALLOW_SSH_FALLBACK', false),
    ],

];
