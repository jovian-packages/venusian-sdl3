<?php

namespace Jovian\Engines\Sdl3;

use SDL_GPUDevice;
use SDL_GPUTexture;

/**
 * A texture on the sdl3 engine's device, with what SDL does not tell back about it: its size, its format and its
 * samples. Released when the last reference goes; SDL lets it go once the GPU no longer uses it.
 *
 * @internal
 */
final class SDLGPUTexture
{
    public function __construct(
        public readonly SDL_GPUDevice $device,
        public readonly SDL_GPUTexture $texture,
        public readonly int $format,
        public readonly int $width,
        public readonly int $height,
        public readonly int $samples,
    ) {}

    public function __destruct()
    {
        SDL_ReleaseGPUTexture($this->device, $this->texture);
    }
}
