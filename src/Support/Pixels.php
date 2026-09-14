<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Support;

use Jovian\Bindings\Sdl3\Enums\SDLGPUTextureFormat;

/** Readback byte order: the offscreen target shares the swapchain's format, and Surface wants RGBA8. */
final class Pixels
{
    public static function isBgra(int $format): bool
    {
        return $format === SDLGPUTextureFormat::B8G8R8A8_UNORM->value
            || $format === SDLGPUTextureFormat::B8G8R8A8_UNORM_SRGB->value;
    }

    public static function swizzleBgraToRgba(string $bgra): string
    {
        $rgba = $bgra;
        for ($i = 0, $n = strlen($bgra); $i < $n; $i += 4) {
            $rgba[$i] = $bgra[$i + 2];
            $rgba[$i + 2] = $bgra[$i];
        }

        return $rgba;
    }
}
