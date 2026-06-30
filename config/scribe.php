<?php

// Scribe is a dev dependency — skip full config in production or when package is absent.
if (env('APP_ENV') === 'production' || ! class_exists(\Knuckles\Scribe\Config\Defaults::class, false)) {
    return [
        'type' => 'laravel',
        'laravel' => [
            'add_routes' => false,
        ],
    ];
}

return require __DIR__.'/scribe.full.php';
