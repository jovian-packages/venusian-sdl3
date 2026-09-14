<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Shaders;

use Jovian\Venusian\Sdl3\Exceptions\Sdl3DrawingException;

/** Loads a committed .spv beside this file; refuses anything that is not a SPIR-V module. */
final class Spirv
{
    public static function load(string $name): string
    {
        $bytes = @file_get_contents(__DIR__.DIRECTORY_SEPARATOR.$name);
        if ($bytes === false || strlen($bytes) < 4 || strlen($bytes) % 4 !== 0 || unpack('V', $bytes)[1] !== 0x07230203) {
            throw Sdl3DrawingException::spirv($name);
        }

        return $bytes;
    }
}
