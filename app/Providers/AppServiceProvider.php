<?php

namespace App\Providers;

use App\Services\Contracts\WireframeGenerator;
use App\Services\OpenAiWireframeGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WireframeGenerator::class, OpenAiWireframeGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();
        RateLimiter::for('wireframe-requests', fn (Request $request): Limit => Limit::perMinute((int) config('wireframe.requests_per_minute'))
            ->by((string) $request->bearerToken()));
    }
}
