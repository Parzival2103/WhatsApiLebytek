<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IncomingWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'ensure.api.permission', 'api.idempotency'])->group(function (): void {
    Route::get('/health', HealthController::class)
        ->middleware('permission:api.health')
        ->withoutMiddleware('api.idempotency')
        ->name('api.v1.health');
});

Route::prefix('v1/webhooks')->middleware(['webhook.signature', 'webhook.idempotency'])->group(function (): void {
    Route::post('/incoming', IncomingWebhookController::class)->name('api.v1.webhooks.incoming');
});
