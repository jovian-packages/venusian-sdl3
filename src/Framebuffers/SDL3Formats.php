<?php

namespace Jovian\Engines\Sdl3\Framebuffers;

use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\Endianness;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;
use Surface\VelvetGE\Framebuffers\Layout;

/**
 * The SDL pixel format whose memory is VelvetGE's bytes for a FormatSpec, byte for byte, on a
 * little-endian host.
 *
 * VelvetGE stores 32-bit pixels in channel order and 24-bit pixels R G B (or B G R); 565 as a
 * 16-bit word in the spec's endianness. SDL's byte-order names (RGBA32, RGB24) say the bytes in
 * memory; SDL's packed 16-bit formats keep a host-order word, so only the little-endian 565 maps:
 *
 *     bytes R G B A -> RGBA32    bytes B G R A -> BGRA32
 *     bytes A R G B -> ARGB32    bytes A B G R -> ABGR32
 *     bytes R G B   -> RGB24     bytes B G R   -> BGR24
 *     565 LSB, RGB  -> RGB565    565 LSB, BGR  -> BGR565
 *
 * Anything else (mono, palettes, planar, 444, 666, big-endian 565, bottom-up rows, a
 * big-endian host) has no SDL layout here: null.
 */
final class SDL3Formats
{
    public static function code(FormatSpec $spec): ?int
    {
        if (pack('S', 1) !== "\x01\x00" || $spec->pixel_format !== PixelFormat::ROW_MAJOR || $spec->scan_direction !== ScanDirection::TOP_TO_BOTTOM) {
            return null;
        }
        try {
            $layout = Layout::of($spec);
        } catch (FramebufferException) {
            return null;
        }
        $order = Layout::channelOrder($spec);

        return match ($layout) {
            Layout::RGBA8888 => match ($order) {
                ChannelOrder::RGBA => SDL_PIXELFORMAT_RGBA32,
                ChannelOrder::BGRA => SDL_PIXELFORMAT_BGRA32,
                ChannelOrder::ARGB => SDL_PIXELFORMAT_ARGB32,
                ChannelOrder::ABGR => SDL_PIXELFORMAT_ABGR32,
                default => null,
            },
            Layout::RGB888 => $order === ChannelOrder::BGR ? SDL_PIXELFORMAT_BGR24 : SDL_PIXELFORMAT_RGB24,
            Layout::RGB565 => ($spec->endianness ?? Endianness::MSB) === Endianness::LSB
                ? ($order === ChannelOrder::BGR ? SDL_PIXELFORMAT_BGR565 : SDL_PIXELFORMAT_RGB565)
                : null,
            default => null,
        };
    }

    public static function bytesPerPixel(int $code): int
    {
        return match ($code) {
            SDL_PIXELFORMAT_RGB24, SDL_PIXELFORMAT_BGR24 => 3,
            SDL_PIXELFORMAT_RGB565, SDL_PIXELFORMAT_BGR565 => 2,
            default => 4,
        };
    }
}
