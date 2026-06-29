<?php

use App\Http\Controllers\Admin\Config\BrandingConfigController;
use App\Http\Controllers\Admin\Config\LayoutConfigController;
use App\Http\Controllers\Admin\Config\ThemeConfigController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\FaviconController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\ProfileController;
use App\Services\DashboardWidgetRegistry;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Public/Index');
})->name('public.index');

Route::get('/manifest.webmanifest', ManifestController::class)->name('pwa.manifest');
Route::get('/favicon.ico', FaviconController::class)->name('pwa.favicon');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::prefix('admin')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::redirect('/', '/admin/login');
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('admin.login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('admin.login.store');
    });

    Route::middleware(['auth', 'verified'])->group(function (): void {
        Route::get('/dashboard', function (DashboardWidgetRegistry $widgets) {
            return Inertia::render('Dashboard', [
                'dashboardWidgets' => $widgets->forUser(auth()->user()),
            ]);
        })->middleware('permission:dashboard.ver')->name('admin.dashboard');

        Route::get('/config/layout', [LayoutConfigController::class, 'edit'])
            ->middleware('permission:configuracion.gestionar')->name('admin.config.layout');
        Route::put('/config/layout', [LayoutConfigController::class, 'update'])
            ->middleware('permission:configuracion.gestionar')->name('admin.config.layout.update');

        Route::get('/config/theme', [ThemeConfigController::class, 'edit'])
            ->middleware('permission:configuracion.gestionar')->name('admin.config.theme');
        Route::put('/config/theme', [ThemeConfigController::class, 'update'])
            ->middleware('permission:configuracion.gestionar')->name('admin.config.theme.update');

        Route::get('/config/branding', [BrandingConfigController::class, 'edit'])
            ->middleware('permission:configuracion.gestionar')->name('admin.config.branding');
        Route::post('/config/branding', [BrandingConfigController::class, 'update'])
            ->middleware('permission:configuracion.gestionar')->name('admin.config.branding.update');

        Route::post('/archivos', [\App\Http\Controllers\Admin\ArchivoController::class, 'store'])
            ->middleware('permission:configuracion.gestionar')
            ->name('admin.archivos.store');

        Route::get('/archivos/{archivo:public_id}', [\App\Http\Controllers\Admin\ArchivoController::class, 'show'])
            ->middleware('permission:configuracion.gestionar')
            ->name('admin.archivos.show');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
