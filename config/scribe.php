<?php

// Scribe is a dev dependency; use full config only when the package is installed (CI/local).
if (is_file(__DIR__.'/../vendor/knuckleswtf/scribe/composer.json')) {
    return require __DIR__.'/scribe.full.php';
}

return [
    'type' => 'laravel',
    'laravel' => [
        'add_routes' => false,
    ],
];
