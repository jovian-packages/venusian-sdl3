<?php

namespace Jovian\Engines\Sdl3;

use SDL_GPUTexture;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Framebuffers\HdrReadback;
use Surface\Contracts\Framebuffers\Region;
use Surface\Framebuffers\GLFramebuffer;
use Surface\Framebuffers\HdrImage;

/**
 * The sdl3 engine's framebuffer: the single-sample RGBA8 texture its device
 * resolves each frame into (or draws into with hard edges). Reads wait on the
 * fence of the frame that last drew; writes go into the texture through a
 * transfer buffer, and the device restores its samples from it before the
 * next frame. An HDR target holds linear half floats, SDR white at 1.0: read
 * as RGBA8 it is sRGB, clamped at SDR white; read as half floats it is as
 * stored.
 */
final class Sdl3Framebuffer extends GLFramebuffer implements HdrReadback
{
    public function __construct(
        private readonly Sdl3Device $device,
        private readonly SDL_GPUTexture $texture,
        private readonly int $w,
        private readonly int $h,
        private readonly bool $hdr = false,
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
        if (! $this->hdr) {
            return $this->device->read($this->texture, $region);
        }

        return HdrImage::fromRgba16f($this->readRgba16f($region), $region->width, $region->height)->toRgba8();
    }

    public function uploadRgba8(string $rgba8, Region $region): void
    {
        $this->inside($region);
        $size = $region->width * $region->height * 4;
        if (strlen($rgba8) !== $size) {
            throw new DrawingException('sdl3: '.strlen($rgba8)." bytes do not fill a {$region->width} × {$region->height} region ({$size} bytes).");
        }
        $this->device->write($this->texture, $this->hdr ? LinearHalves::fromRgba8($rgba8, $region->width, $region->height) : $rgba8, $region);
    }

    public function readRgba16f(Region $region): string
    {
        $this->inside($region);
        if (! $this->hdr) {
            return LinearHalves::fromRgba8($this->device->read($this->texture, $region), $region->width, $region->height);
        }

        return $this->device->read($this->texture, $region, 8);
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
