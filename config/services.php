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

    'isp_api' => [
        'token' => env('ISP_API_TOKEN'),
    ],

    'zostream_subscription' => [
        'base_url' => env('ZOSTREAM_EXTERNAL_API_URL', 'https://apis.zostream.in'),
        'api_key' => env('ZOSTREAM_EXTERNAL_API_KEY'),
        'environment' => env('ZOSTREAM_RAZORPAY_ENV', 'SANDBOX'),
        'razorpay_secret' => env('ZOSTREAM_RAZORPAY_KEY_SECRET'),
        'source_name' => env('ZOSTREAM_PAYMENT_SOURCE', 'zostream-isp-panel'),
    ],

];
