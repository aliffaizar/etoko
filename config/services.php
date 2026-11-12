<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    |--------------------------------------------------------------------------
    | Midtrans (P2-E1-T5)
    |--------------------------------------------------------------------------
    |
    | Credentials for the Snap and Core APIs. Nothing here has a default worth
    | shipping: a missing server key makes `MidtransGateway` refuse the charge
    | with a sentence rather than sending an unauthenticated request, and the
    | Midtrans payment methods are seeded **inactive** so a shop without keys
    | never offers a method that cannot work.
    |
    | Two hosts, because Midtrans has two: Snap issues the transaction (the
    | `app.` host) and the Core API answers everything afterwards, refunds
    | included (the `api.` host). `production` picks the pair; either can still
    | be overridden on its own for a proxy or a recorded test environment.
    |
    | `MIDTRANS_WEBHOOK_TOLERATE_UNSIGNED` exists for one narrow case — a local
    | machine replaying a captured payload — and is read only when the
    | application is not in production, so setting it on a live server does
    | nothing. Signature verification is the only thing standing between the
    | webhook and anybody marking their own order paid.
    |
    */

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'production' => (bool) env('MIDTRANS_PRODUCTION', false),
        'timeout' => (int) env('MIDTRANS_TIMEOUT', 15),
        'qris_acquirer' => env('MIDTRANS_QRIS_ACQUIRER', 'gopay'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Biteship (P2-E2-T6)
    |--------------------------------------------------------------------------
    |
    | Credentials for the shipping aggregator. Nothing here has a default worth
    | shipping: a missing key makes `BiteshipProvider` refuse the quote with a
    | sentence rather than sending an unauthenticated request, and the Biteship
    | courier is seeded **inactive** so a shop without a key never offers a
    | courier that cannot answer.
    |
    | One host, unlike Midtrans: Biteship's test and live environments differ
    | by the key, not by the URL. `BITESHIP_BASE_URL` is therefore only for a
    | proxy or a recorded test environment.
    |
    */

    'biteship' => [
        'api_key' => env('BITESHIP_API_KEY'),
        'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com'),
        'timeout' => (int) env('BITESHIP_TIMEOUT', 15),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
