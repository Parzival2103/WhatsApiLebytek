<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IncomingWebhookController;
use App\Http\Controllers\Api\V1\InstanceController;
use App\Http\Controllers\Api\V1\TenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'ensure.api.permission', 'api.idempotency'])->group(function (): void {
    Route::get('/health', HealthController::class)
        ->middleware('permission:api.health')
        ->withoutMiddleware('api.idempotency')
        ->name('api.v1.health');

    Route::get('/tenants', [TenantController::class, 'index'])
        ->middleware('permission:tenants.ver')
        ->withoutMiddleware('api.idempotency')
        ->name('api.v1.tenants.index');

    Route::post('/tenants', [TenantController::class, 'store'])
        ->middleware('permission:tenants.provisionar')
        ->name('api.v1.tenants.store');

    Route::get('/tenants/{tenant:public_id}', [TenantController::class, 'show'])
        ->middleware('permission:tenants.ver')
        ->withoutMiddleware('api.idempotency')
        ->name('api.v1.tenants.show');

    Route::patch('/tenants/{tenant:public_id}', [TenantController::class, 'update'])
        ->middleware('permission:tenants.gestionar')
        ->name('api.v1.tenants.update');

    Route::post('/tenants/{tenant:public_id}/tokens', [TenantController::class, 'issueToken'])
        ->middleware('permission:tenants.gestionar')
        ->name('api.v1.tenants.tokens.store');

    Route::get('/instances', [InstanceController::class, 'index'])
        ->middleware('permission:instancias.ver')
        ->withoutMiddleware('api.idempotency')
        ->name('api.v1.instances.index');

    Route::post('/instances', [InstanceController::class, 'store'])
        ->middleware('permission:instancias.crear')
        ->name('api.v1.instances.store');

    Route::get('/instances/{instancia:public_id}', [InstanceController::class, 'show'])
        ->middleware('permission:instancias.ver')
        ->withoutMiddleware('api.idempotency')
        ->name('api.v1.instances.show');

    Route::get('/instances/{instancia:public_id}/qr', [InstanceController::class, 'qr'])
        ->middleware('permission:instancias.ver')
        ->withoutMiddleware('api.idempotency')
        ->name('api.v1.instances.qr');

    Route::delete('/instances/{instancia:public_id}', [InstanceController::class, 'destroy'])
        ->middleware('permission:instancias.eliminar')
        ->name('api.v1.instances.destroy');
});

Route::prefix('v1/webhooks')->middleware(['webhook.signature', 'webhook.idempotency'])->group(function (): void {
    Route::post('/incoming', IncomingWebhookController::class)->name('api.v1.webhooks.incoming');
});
