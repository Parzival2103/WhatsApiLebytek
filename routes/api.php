<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'ensure.api.permission'])->group(function (): void {
    Route::get('/health', HealthController::class)
        ->middleware('permission:api.health')
        ->name('api.v1.health');
});

Route::prefix('v1/webhooks')->middleware(['webhook.signature', 'webhook.idempotency'])->group(function (): void {
    Route::post('/incoming', function () {
        return response()->json(['received' => true, 'duplicate' => false]);
    })->name('api.v1.webhooks.incoming');
});
