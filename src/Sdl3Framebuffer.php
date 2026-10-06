<?php

namespace Jovian\Engines\Sdl3;

use SDL_GPUTexture;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Framebuffers\Region;
use Surface\Framebuffers\GLFramebuffer;

/**
 * The sdl3 engine's framebuffer: the single-sample RGBA8 texture its device
 * resolves each frame into (or draws into with hard edges). Reads wait on the
 * fence of the frame that last drew; writes go into the texture through a
 * transfer buffer, and the device restores its samples from it before the
 * next frame.
 */
final class Sdl3Framebuffer extends GLFramebuffer
{
    public function __construct(
        private readonly Sdl3Device $device,
        private readonly SDL_GPUTexture $texture,
        private readonly int $w,
        private readonly int $h,
    ) {
        parent::__construct();
    }

    /** The resolved texture is this framebuffer's to let go of: a re-made target leaves the old one readable. */
    public function __destruct()
    {
        $this->device->forget($this->texture);
    }

    public function texture(): SDL_GPUTexture
    {
        return $this->texture;
    }

    public function width(): int
    {
        return $this->w;
    }

    public function height(): int
    {
        return $this->h;
    }

    public function readRgba8(Region $region): string
    {
        $this->inside($region);

        return $this->device->read($this->texture, $region);
    }

    public function uploadRgba8(string $rgba8, Region $region): void
    {
        $this->inside($region);
        $size = $region->width * $region->height * 4;
        if (strlen($rgba8) !== $size) {
            throw new DrawingException('sdl3: '.strlen($rgba8)." bytes do not fill a {$region->width} × {$region->height} region ({$size} bytes).");
        }
        $this->device->write($this->texture, $rgba8, $region);
    }

    /** The GPU copies whatever region it is given: anything past the texture is refused here. */
    private function inside(Region $region): void
    {
        if ($region->x < 0 || $region->y < 0 || $region->width < 1 || $region->height < 1
            || $region->x + $region->width > $this->w || $region->y + $region->height > $this->h) {
            throw new DrawingException("sdl3: the region {$region->width} × {$region->height} at ({$region->x}, {$region->y}) lies outside the {$this->w} × {$this->h} target.");
        }
    }
}
