<?php

namespace App\Providers;

use App\Services\Contracts\WireframeGenerator;
use App\Services\OpenAiWireframeGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use YutoSeta\MagicHtmlLayout\LayoutAstValidator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WireframeGenerator::class, OpenAiWireframeGenerator::class);
        // A region's explicit algorithm, template, gap and mandated compact
        // reflow are four bounded geometry declarations. Keep the generic
        // instance cap strict while allowing that complete formal Layout unit.
        $this->app->singleton(
            LayoutAstValidator::class,
            static fn (): LayoutAstValidator => new LayoutAstValidator(maxInstanceProperties: 4),
        );
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
