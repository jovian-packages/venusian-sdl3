<?php

namespace Jovian\Engines\Sdl3\Framebuffers;

use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Rendering\Framebuffers\FramebufferDriver;

/** SDL3 as a Velvet framebuffer module: every make() is one SDL3Surface. */
class SDL3FramebufferDriver extends FramebufferDriver
{
    /** The ext-sdl3 release whose surface and renderer bindings SDL3Surface needs. */
    public const string MINIMUM = '0.11.2';

    /** @throws FramebufferException When ext-sdl3 is not loaded, or lacks the surface and renderer bindings SDL3Surface needs. */
    public function __construct()
    {
        if (! extension_loaded('sdl3')) {
            throw FramebufferException::extensionMissing('sdl3', self::MINIMUM);
        }
        $missing = array_values(array_filter(SDL3Surface::NEEDS, fn (string $function): bool => ! function_exists($function)));
        if ($missing !== []) {
            throw FramebufferException::extensionStale('sdl3', self::MINIMUM, $missing);
        }
    }

    public function driver(): string
    {
        return 'sdl3';
    }

    /** @throws FramebufferException When SDL has no format with $format's byte layout. */
    public function make(FormatSpec $format, int $width, int $height): SDL3Surface
    {
        return new SDL3Surface($format, $width, $height);
    }
}
