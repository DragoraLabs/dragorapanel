<?php

namespace App\Providers;

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
        if (config('app.store_mode') && config('app.url')) {
            // Always generate absolute URLs against the public domain so the
            // panel (and the storefront itself) resolve icons/zips correctly,
            // even when requests arrive via 127.0.0.1:3063 or the tunnel.
            \Illuminate\Support\Facades\URL::forceRootUrl(rtrim(config('app.url'), '/'));
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
