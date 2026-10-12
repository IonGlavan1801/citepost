<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('lab-api', fn ($request) => Limit::perMinute(90)->by('api:'.$request->ip()));
        RateLimiter::for('lab-query', fn ($request) => [Limit::perMinute(12)->by('query:'.$request->ip()), Limit::perDay(1000)->by('query-global')]);
        RateLimiter::for('lab-upload', fn ($request) => Limit::perMinute(6)->by('upload:'.$request->ip()));
        RateLimiter::for('lab-reindex', fn ($request) => Limit::perMinute(3)->by('reindex:'.$request->ip()));
    }
}
