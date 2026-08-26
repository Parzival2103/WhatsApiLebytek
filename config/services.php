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

    'webhooks' => [
        'secret' => env('WEBHOOK_SECRET'),
    ],

    'green_api' => [
        'base_url' => env('GREEN_API_BASE_URL', 'https://api.green-api.com'),
        'partner_token' => env('GREEN_API_PARTNER_TOKEN'),
        'webhook_url' => env('GREEN_API_WEBHOOK_URL', env('APP_URL').'/api/v1/webhooks/incoming'),
        'webhook_secret' => env('WEBHOOK_SECRET'),
        // ProvisionGreenInstanceJob backoff (seconds). Zero in unit tests.
        'provision_set_settings_initial_delay' => (int) env('GREEN_API_PROVISION_SET_SETTINGS_INITIAL_DELAY', 5),
        'provision_set_settings_retry_delay' => (int) env('GREEN_API_PROVISION_SET_SETTINGS_RETRY_DELAY', 10),
        'provision_set_settings_max_attempts' => (int) env('GREEN_API_PROVISION_SET_SETTINGS_MAX_ATTEMPTS', 6),
        'provision_get_state_retry_delay' => (int) env('GREEN_API_PROVISION_GET_STATE_RETRY_DELAY', 5),
        'provision_get_state_max_attempts' => (int) env('GREEN_API_PROVISION_GET_STATE_MAX_ATTEMPTS', 5),
    ],

    'docs' => [
        'site_url' => env('DOCS_SITE_URL', 'https://docs.lebytek.com'),
    ],

];
