<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$plain = $argv[1] ?? '';
if ($plain === '') {
    fwrite(STDERR, "Usage: php scripts/debug-token.php <plainToken>\n");
    exit(1);
}

$token = Laravel\Sanctum\PersonalAccessToken::findToken($plain);
if ($token === null) {
    echo json_encode(['error' => 'token not found'], JSON_PRETTY_PRINT)."\n";
    exit(0);
}

$user = App\Models\User::query()->find($token->tokenable_id);
$allPerms = Spatie\Permission\Models\Permission::query()
    ->whereIn('name', ['instancias.ver', 'mensajes.enviar', 'mensajes.ver'])
    ->get(['name', 'guard_name'])
    ->map(fn ($p) => $p->guard_name.':'.$p->name)
    ->values()
    ->all();
echo json_encode([
    'email' => $user?->email,
    'tenant_id' => $user?->tenant_id,
    'is_platform_admin' => $user?->is_platform_admin,
    'isPlatformAdmin' => $user?->isPlatformAdmin(),
    'token_abilities' => $token->abilities,
    'permissions' => $user?->getAllPermissions()->pluck('name')->values()->all(),
    'can_instancias_ver' => $user?->can('instancias.ver'),
    'can_mensajes_enviar' => $user?->can('mensajes.enviar'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
