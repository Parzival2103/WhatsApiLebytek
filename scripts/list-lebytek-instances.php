<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = \App\Models\Integration\Instancia::query()
    ->withoutGlobalScope('tenant')
    ->withTrashed()
    ->where('external_ref', 'like', 'lebytek_lead_%')
    ->get(['public_id', 'external_ref', 'status', 'tenant_id', 'deleted_at']);

echo $rows->toJson(JSON_PRETTY_PRINT)."\n";
