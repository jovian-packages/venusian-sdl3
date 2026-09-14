<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Providers;

use Jovian\Venusian\Sdl3\Sdl3GpuEngine;
use Jovian\Venusian\Sdl3\Sessions\SdlStageSession;
use Voyager\NutsAndBolts\ServiceProvider;

/** Publishes the SDL stage host and the SDL_GPU engine under the aliases Surface looks for. Installing this package is the whole enablement. */
class VenusianSdl3ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SdlStageSession::class);
        $this->app->alias(SdlStageSession::class, 'stage.sdl3');
        $this->app->singleton(Sdl3GpuEngine::class);
        $this->app->alias(Sdl3GpuEngine::class, 'gpu.sdl3');
    }

    public function boot(): void {}
}
