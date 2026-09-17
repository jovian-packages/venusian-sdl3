<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Exceptions;

use Surface\Contracts\HumanInput\HumanInputException;

final class Sdl3InputException extends HumanInputException
{
    public static function init(string $sdl_error): static
    {
        return new static("SDL could not start the gamepad subsystem: {$sdl_error}");
    }
}
