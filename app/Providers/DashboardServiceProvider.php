<?php

namespace App\Providers;

use App\Services\DashboardWidgetRegistry;
use Illuminate\Support\ServiceProvider;

class DashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DashboardWidgetRegistry::class, function () {
            $registry = new DashboardWidgetRegistry;
            $registry->register(config('dashboard.widgets', []));

            return $registry;
        });
    }
}
