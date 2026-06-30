<?php

$scribeInstalled = is_file(__DIR__.'/../vendor/knuckleswtf/scribe/src/Config/Defaults.php');

if (! $scribeInstalled) {
    return [
        'type' => 'laravel',
        'laravel' => [
            'add_routes' => false,
        ],
    ];
}

return require __DIR__.'/../bootstrap/scribe.config.php';
