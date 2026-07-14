<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Observers\CustomerObserver;
use App\Observers\PackageObserver;
use App\Observers\RouterObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Customer::observe(CustomerObserver::class);
        Package::observe(PackageObserver::class);
        Router::observe(RouterObserver::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
