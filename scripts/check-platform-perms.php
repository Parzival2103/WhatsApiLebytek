<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = config('nucleo.waapi_service_email');
$user = App\Models\User::query()->where('email', $email)->first();

if ($user === null) {
    echo "USER_NOT_FOUND: {$email}\n";
    exit(1);
}

echo "user: {$user->email}\n";
echo "permissions: ".$user->getAllPermissions()->pluck('name')->sort()->implode(', ')."\n";
echo "roles: ".$user->getRoleNames()->implode(', ')."\n";
