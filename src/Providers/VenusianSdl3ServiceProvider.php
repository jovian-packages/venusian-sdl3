<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Providers;

use Jovian\Venusian\Sdl3\Events\SdlEventPump;
use Jovian\Venusian\Sdl3\Input\Sdl3InputEngine;
use Jovian\Venusian\Sdl3\Sdl3GpuEngine;
use Jovian\Venusian\Sdl3\Sessions\SdlStageSession;
use Voyager\NutsAndBolts\ServiceProvider;

/** Publishes the SDL stage host, the SDL_GPU engine and the SDL input engine under the aliases Surface looks for. Installing this package is the whole enablement. */
class VenusianSdl3ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SdlEventPump::class);
        $this->app->singleton(SdlStageSession::class, fn ($app) => new SdlStageSession($app->make(SdlEventPump::class)));
        $this->app->alias(SdlStageSession::class, 'stage.sdl3');
        $this->app->singleton(Sdl3GpuEngine::class);
        $this->app->alias(Sdl3GpuEngine::class, 'gpu.sdl3');
        $this->app->singleton(Sdl3InputEngine::class, fn ($app) => new Sdl3InputEngine($app->make(SdlEventPump::class), $app->make(SdlStageSession::class)));
        $this->app->alias(Sdl3InputEngine::class, 'input.sdl3');
    }

    public function boot(): void {}
}
