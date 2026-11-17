<?php

namespace Jovian\Engines\Sdl3;

use Surface\Contracts\Graphics\ActorStage;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Graphics\PainterSurface;

/** An sdl3 surface: a framebuffer on the GPU and the painter that draws into it. Shown as pixels, through the stage. */
class SDLGPUSurface extends PainterSurface
{
    public function __construct(
        SDLGPUDevice $engine,
        protected readonly SDLGPUFramebuffer $framebuffer,
        SDLGPUPainter $painter,
        ?ActorStage $stage = null,
    ) {
        parent::__construct($engine, $painter, $stage);
    }

    public function width(): int
    {
        return $this->framebuffer->width();
    }

    public function height(): int
    {
        return $this->framebuffer->height();
    }

    public function format(): FormatSpec
    {
        return $this->framebuffer->format();
    }

    /** @throws SDL3Exception */
    public function read(): string
    {
        return $this->framebuffer->pixels(Region::wholeSurface($this->width(), $this->height()));
    }

    /** The framebuffer on the GPU. */
    public function paintTarget(): SDLGPUFramebuffer
    {
        return $this->framebuffer;
    }
}
