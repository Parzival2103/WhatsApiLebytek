<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon dashboard access (production)
    |--------------------------------------------------------------------------
    */

    'horizon_allowed_emails' => array_values(array_filter(array_map(
        fn (string $email): string => trim($email),
        explode(',', (string) env('HORIZON_ALLOWED_EMAILS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Production bootstrap admin
    |--------------------------------------------------------------------------
    */

    'admin_initial_email' => env('ADMIN_INITIAL_EMAIL', 'admin@sistema.local'),
    'admin_initial_name' => env('ADMIN_INITIAL_NAME', 'Administrador'),
    'admin_initial_password' => env('ADMIN_INITIAL_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Platform service account (Sanctum) — back-office lebytek.com
    |--------------------------------------------------------------------------
    | PLATFORM_SERVICE_* replaces WAAPI_SERVICE_* (legacy env names still work).
    */

    'platform_service_email' => env('PLATFORM_SERVICE_EMAIL', env('WAAPI_SERVICE_EMAIL', 'platform-service@lebytek.internal')),
    'platform_service_name' => env('PLATFORM_SERVICE_NAME', env('WAAPI_SERVICE_NAME', 'Lebytek Platform Service')),

    // @deprecated Use platform_service_email / platform_service_name
    'waapi_service_email' => env('PLATFORM_SERVICE_EMAIL', env('WAAPI_SERVICE_EMAIL', 'platform-service@lebytek.internal')),
    'waapi_service_name' => env('PLATFORM_SERVICE_NAME', env('WAAPI_SERVICE_NAME', 'Lebytek Platform Service')),

];
