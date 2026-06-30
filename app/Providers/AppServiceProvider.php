<?php

namespace App\Providers;

use App\Models\Core\MenuItem;
use App\Observers\MenuItemObserver;
use App\Services\GreenApi\PartnerClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PartnerClient::class, function () {
            return new PartnerClient(
                (string) config('services.green_api.base_url'),
                config('services.green_api.partner_token'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        MenuItem::observe(MenuItemObserver::class);

        Vite::prefetch(concurrency: 3);

        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            $key = $user
                ? ($user->tenant_id ?? 'platform').':'.$user->id
                : $request->ip();

            return Limit::perMinute(60)->by($key);
        });
    }
}
