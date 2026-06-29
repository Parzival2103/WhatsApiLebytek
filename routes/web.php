<?php

use App\Http\Controllers\ProfileController;
use App\Services\DashboardWidgetRegistry;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::prefix('admin')->middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', function (DashboardWidgetRegistry $widgets) {
        return Inertia::render('Dashboard', [
            'dashboardWidgets' => $widgets->forUser(auth()->user()),
        ]);
    })->middleware('permission:dashboard.ver')->name('admin.dashboard');

    Route::get('/config/layout', function () {
        return Inertia::render('Admin/Config/Layout');
    })->middleware('permission:configuracion.gestionar')->name('admin.config.layout');

    Route::post('/archivos', [\App\Http\Controllers\Admin\ArchivoController::class, 'store'])
        ->middleware('permission:configuracion.gestionar')
        ->name('admin.archivos.store');

    Route::get('/archivos/{archivo:public_id}', [\App\Http\Controllers\Admin\ArchivoController::class, 'show'])
        ->middleware('permission:configuracion.gestionar')
        ->name('admin.archivos.show');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
