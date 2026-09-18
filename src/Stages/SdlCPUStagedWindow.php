<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Stages;

use Jovian\Bindings\Sdl3\Render\SDLRender;
use Jovian\Bindings\Sdl3\Video\SDLVideo;
use Jovian\Venusian\Sdl3\Contracts\RoutableStage;
use Surface\Contracts\Drawing\CPUDrawTarget;
use Surface\Contracts\Stage\StageFit;
use Surface\Stage\CPUStagedWindow;

/**
 * A CPU stage on an SDL window: one streaming texture the size of the canvas,
 * updated from its RGBA8 and stretched to the window by SDL's logical
 * presentation. No GPU engine anywhere — the renderer is whatever
 * config('stage.cpu_renderer') named, 'software' by default.
 */
final class SdlCPUStagedWindow extends CPUStagedWindow implements RoutableStage
{
    public function __construct(
        string $name,
        CPUDrawTarget $canvas,
        int $width,
        int $height,
        float $scale,
        StageFit $fit,
        public readonly int $window,
        private int $renderer,
        private int $texture,
        private readonly int $pitch,
    ) {
        parent::__construct($name, $canvas, $width, $height, $scale, $fit);
    }

    public function window(): int
    {
        return $this->window;
    }

    public function windowId(): int
    {
        return SDLVideo::SDLGetWindowID($this->window);
    }

    public function renderer(): int
    {
        return $this->renderer;
    }

    public function texture(): int
    {
        return $this->texture;
    }

    /** From a window resize / pixel-size / scale event: re-read points and pixel density. */
    public function nativeResized(): void
    {
        [$width, $height] = SDLVideo::SDLGetWindowSize($this->window);
        $width = (int) $width;

        $this->resized($width, (int) $height, SdlStagedWindow::densityOf($this->window, $width, $this->scale));
    }

    /** Upload, clear, blit, present. The logical presentation does the scaling. */
    protected function applyPresent(string $rgba8): void
    {
        if ($this->texture === 0) {
            return;
        }

        SDLRender::SDLUpdateTexture($this->texture, $rgba8, $this->pitch);
        SDLRender::SDLSetRenderDrawColor($this->renderer, 0, 0, 0, 255);
        SDLRender::SDLRenderClear($this->renderer);
        SDLRender::SDLRenderTexture($this->renderer, $this->texture);
        SDLRender::SDLRenderPresent($this->renderer);
    }

    protected function applyTitle(string $title): void
    {
        SDLVideo::SDLSetWindowTitle($this->window, $title);
    }

    protected function applyShow(): void
    {
        SDLVideo::SDLShowWindow($this->window);
    }

    /** Texture then renderer; both idempotent, both before the window goes. */
    protected function releaseEngine(): void
    {
        if ($this->texture !== 0) {
            SDLRender::SDLDestroyTexture($this->texture);
            $this->texture = 0;
        }

        if ($this->renderer !== 0) {
            SDLRender::SDLDestroyRenderer($this->renderer);
            $this->renderer = 0;
        }
    }

    protected function destroyNative(): void
    {
        SDLVideo::SDLDestroyWindow($this->window);
    }
}
