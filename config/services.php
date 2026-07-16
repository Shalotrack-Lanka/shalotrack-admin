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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],



    'shalotrack_api' => [
        // FIX: was reading SHALOTRACK_API_URL, but the actual env var set in
        // .env / admin-user-data.sh is SHALOTRACK_API_BASE_URL. This only
        // "worked" before because the fallback default happened to match.
        'base_url' => env('SHALOTRACK_API_BASE_URL', 'https://api.shalotrack.com'),
        'token' => env('SHALOTRACK_API_TOKEN'),
        'sync_key' => env('SHALOTRACK_SYNC_KEY'),
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

];