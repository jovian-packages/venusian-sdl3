<?php

namespace Jovian\Engines\Sdl3\Framebuffers;

use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelStore;
use Surface\VelvetGE\Drivers\Framebuffers\CLandFramebufferDriver;
use Surface\VelvetGE\Drivers\Framebuffers\StoreFramebufferDriver;
use Surface\VelvetGE\Framebuffers\PHPLand\PHPLandPixelStore;

/**
 * The 'sdl3' framebuffer driver: pixels in SDL surfaces through ext-sdl3. CPU only: no SDL
 * subsystem is started, so it runs headless.
 *
 * A format SDL can hold byte for byte (SDL3Formats) gets an SDL3PixelStore; any other (mono,
 * palettes, planar, 444, 666, big-endian 565, bottom-up rows) gets the store VelvetGE's 'auto'
 * would give it, ext-fb's when that is loaded and PHP's otherwise, so this driver holds every
 * format VelvetGE does and is safe first in 'auto'.
 */
class SDL3FramebufferDriver extends StoreFramebufferDriver
{
    /** The ext-sdl3 release this driver is written against. */
    public const string MINIMUM = '0.11.2';

    /** The bindings the store needs that arrived after MINIMUM's first build. */
    private const array NEEDS = ['SDL_SetSurfaceBlendMode'];

    public static function available(): bool
    {
        return extension_loaded('sdl3')
            && version_compare((string) phpversion('sdl3'), self::MINIMUM, '>=')
            && array_all(self::NEEDS, fn (string $function): bool => function_exists($function));
    }

    /** @throws FramebufferException When this PHP has no ext-sdl3 of at least MINIMUM with the surface bindings. */
    public function __construct()
    {
        if (! extension_loaded('sdl3') || version_compare((string) phpversion('sdl3'), self::MINIMUM, '<')) {
            throw FramebufferException::extensionMissing('sdl3', self::MINIMUM);
        }
        $missing = array_values(array_filter(self::NEEDS, fn (string $function): bool => ! function_exists($function)));
        if ($missing !== []) {
            throw FramebufferException::extensionStale('sdl3', self::MINIMUM, $missing);
        }
    }

    public function driver(): string
    {
        return 'sdl3';
    }

    public function mint(FormatSpec $format, int $width, int $height): PixelStore
    {
        if (SDL3Formats::code($format) !== null) {
            return new SDL3PixelStore($format, $width, $height);
        }

        return CLandFramebufferDriver::available()
            ? (new CLandFramebufferDriver())->mint($format, $width, $height)
            : new PHPLandPixelStore($format, $width, $height);
    }
}
