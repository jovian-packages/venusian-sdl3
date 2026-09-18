<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Support;

use Jovian\Bindings\Sdl3\Enums\SDLGPUTextureFormat;
use Jovian\Bindings\Sdl3\Enums\SDLPixelFormat;

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

    /**
     * The SDL format whose bytes in memory are R, G, B, A — what
     * CPUDrawTarget::rgba8() hands us. SDL names 32-bit formats by packed
     * value, so the byte order flips with the host: ABGR8888 is RGBA32 on a
     * little-endian box, RGBA8888 on a big-endian one.
     */
    public static function rgba32(): SDLPixelFormat
    {
        return unpack('S', "\x01\x00")[1] === 1 ? SDLPixelFormat::ABGR8888 : SDLPixelFormat::RGBA8888;
    }
}
