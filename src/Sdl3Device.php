<?php

namespace Jovian\Engines\Sdl3;

use Surface\Graphics\GfxDevice;

/** The sdl3 engine: what its backends share. */
abstract class SDL3Device extends GfxDevice
{
    public function name(): string
    {
        return 'sdl3';
    }
}
