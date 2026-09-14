<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Surfaces;

use Jovian\Bindings\Sdl3\Enums\SDLGLAttr;
use Jovian\Bindings\Sdl3\Video\SDLGL;
use Jovian\Bindings\Sdl3\Video\SDLVideo;
use Jovian\Venusian\Sdl3\Contracts\LendsToEngine;
use Jovian\Venusian\Sdl3\Enums\GLProfile;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3StageException;
use Surface\Contracts\Drawing\GLSurface;

/**
 * An SDL window's GL context, lent to a GL_CONTEXT engine. macOS asks for
 * 4.1 core; elsewhere ES 3.0 — the Pi's desktop GL 3.1 has no GLSL 1.50,
 * and venusian-ogx picks its dialect from GL_VERSION.
 */
final class SdlGLSurface implements GLSurface, LendsToEngine
{
    private function __construct(
        private readonly int $window,
        private int $context,
    ) {}

    /** Set before the window is created: some platforms bake the profile into the pixel format. */
    public static function requestAttributes(): void
    {
        SDLGL::SDLGLResetAttributes();

        if (PHP_OS_FAMILY === 'Darwin') {
            SDLGL::SDLGLSetAttribute(SDLGLAttr::CONTEXT_PROFILE_MASK, GLProfile::CORE->value);
            SDLGL::SDLGLSetAttribute(SDLGLAttr::CONTEXT_MAJOR_VERSION, 4);
            SDLGL::SDLGLSetAttribute(SDLGLAttr::CONTEXT_MINOR_VERSION, 1);
        } else {
            SDLGL::SDLGLSetAttribute(SDLGLAttr::CONTEXT_PROFILE_MASK, GLProfile::ES->value);
            SDLGL::SDLGLSetAttribute(SDLGLAttr::CONTEXT_MAJOR_VERSION, 3);
            SDLGL::SDLGLSetAttribute(SDLGLAttr::CONTEXT_MINOR_VERSION, 0);
        }

        SDLGL::SDLGLSetAttribute(SDLGLAttr::DOUBLEBUFFER, 1);
    }

    public static function create(int $window): self
    {
        try {
            $context = SDLGL::SDLGLCreateContext($window);   // current on return
        } catch (\RuntimeException $e) {
            throw Sdl3StageException::lendFailed('GL context', $e->getMessage());
        }

        SDLGL::SDLGLSetSwapInterval(1);

        return new self($window, $context);
    }

    public function makeCurrent(): void
    {
        SDLGL::SDLGLMakeCurrent($this->window, $this->context);
    }

    public function present(): void
    {
        SDLGL::SDLGLSwapWindow($this->window);
    }

    public function drawableSize(): array
    {
        [$width, $height] = SDLVideo::SDLGetWindowSizeInPixels($this->window);

        return [max(1, (int) $width), max(1, (int) $height)];
    }

    public function release(): void
    {
        if ($this->context !== 0) {
            SDLGL::SDLGLDestroyContext($this->context);
            $this->context = 0;
        }
    }
}
