<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Exceptions;

use Surface\Contracts\Stage\StageException;

final class Sdl3StageException extends StageException
{
    public static function init(string $sdl): self
    {
        return new self("SDL_Init(VIDEO) failed: {$sdl}");
    }

    public static function windowFailed(string $name, string $reason): self
    {
        return new self("SDL could not create the '{$name}' stage window: {$reason}");
    }

    public static function lendFailed(string $what, string $reason): self
    {
        return new self("SDL could not lend a {$what}: {$reason}");
    }

    public static function rendererFailed(string $name, string $reason): self
    {
        return new self("SDL could not create a renderer for the '{$name}' stage: {$reason}");
    }

    public static function textureFailed(string $name, int $width, int $height, string $reason): self
    {
        return new self("SDL could not create a {$width}x{$height} streaming texture for the '{$name}' stage: {$reason}");
    }
}
