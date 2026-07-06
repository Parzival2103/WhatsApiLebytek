<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$publicId = $argv[1] ?? '';
if ($publicId === '') {
    fwrite(STDERR, "Usage: php scripts/sync-instance-state.php <instancePublicId>\n");
    exit(1);
}

$instancia = App\Models\Integration\Instancia::query()
    ->withoutGlobalScope('tenant')
    ->where('public_id', $publicId)
    ->first();

if ($instancia === null) {
    echo json_encode(['error' => 'instance not found'], JSON_PRETTY_PRINT)."\n";
    exit(1);
}

$before = [
    'status' => $instancia->status,
    'green_state' => $instancia->green_state,
    'authorized_at' => $instancia->authorized_at?->toIso8601String(),
];

$client = new App\Services\GreenApi\InstanceClient(
    (string) config('services.green_api.base_url'),
    (string) $instancia->id_instance,
    (string) $instancia->api_token_instance,
);

$greenState = $client->getStateInstance();
$status = $greenState === 'authorized' ? 'authorized' : ($instancia->status === 'authorized' ? 'waiting_qr' : $instancia->status);

$instancia->update([
    'green_state' => $greenState,
    'status' => $status,
    'authorized_at' => $status === 'authorized' ? ($instancia->authorized_at ?? now()) : null,
]);

$instancia->refresh();

echo json_encode([
    'publicId' => $instancia->public_id,
    'before' => $before,
    'after' => [
        'status' => $instancia->status,
        'green_state' => $instancia->green_state,
        'authorized_at' => $instancia->authorized_at?->toIso8601String(),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
