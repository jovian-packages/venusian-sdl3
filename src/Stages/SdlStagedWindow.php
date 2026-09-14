<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Stages;

use Jovian\Bindings\Sdl3\Video\SDLVideo;
use Jovian\Venusian\Sdl3\Contracts\LendsToEngine;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Stage\StagedWindow;

/** A stage over an SDL window. What the window lent the engine is released after the executor, before the window. */
final class SdlStagedWindow extends StagedWindow
{
    public function __construct(
        string $name,
        GPUEngine $engine,
        Executor $executor,
        int $width,
        int $height,
        float $scale,
        public readonly int $window,
        private readonly ?LendsToEngine $lent = null,
    ) {
        parent::__construct($name, $engine, $executor, $width, $height, $scale);
    }

    /** SDL's pixel density for the window; pixels over points when SDL cannot say. */
    public static function densityOf(int $window, int $points_wide, float $fallback = 1.0): float
    {
        $density = SDLVideo::SDLGetWindowPixelDensity($window);
        if ($density > 0.0) {
            return $density;
        }

        [$pixelWidth] = SDLVideo::SDLGetWindowSizeInPixels($window);

        return $points_wide > 0 ? $pixelWidth / $points_wide : $fallback;
    }

    public function windowId(): int
    {
        return SDLVideo::SDLGetWindowID($this->window);
    }

    /** From a window resize / pixel-size / scale event: re-read points and pixel density. */
    public function nativeResized(): void
    {
        [$width, $height] = SDLVideo::SDLGetWindowSize($this->window);
        $width = (int) $width;

        $this->resized($width, (int) $height, self::densityOf($this->window, $width, $this->scale));
    }

    protected function applyTitle(string $title): void
    {
        SDLVideo::SDLSetWindowTitle($this->window, $title);
    }

    protected function applyShow(): void
    {
        SDLVideo::SDLShowWindow($this->window);
    }

    protected function destroyNative(): void
    {
        try {
            $this->lent?->release();
        } finally {
            SDLVideo::SDLDestroyWindow($this->window);
        }
    }
}
