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

];
