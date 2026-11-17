<?php

namespace Jovian\Engines\Sdl3;

use Surface\Contracts\Graphics\GraphicsException;
use Throwable;

/** What SDL refused or failed at, in the sdl3 engine's words. */
class SDL3Exception extends GraphicsException
{
    public static function because(string $what, ?Throwable $previous = null): self
    {
        return new self("sdl3: {$what}", 0, $previous);
    }

    /** $what, with SDL_GetError() as the reason when SDL gave one. */
    public static function fromSdl(string $what): self
    {
        $reason = SDL_GetError();

        return new self("sdl3: {$what}".($reason === '' ? '.' : ": {$reason}"));
    }
}
