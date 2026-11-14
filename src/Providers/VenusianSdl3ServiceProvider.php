<?php

namespace Jovian\Engines\Sdl3\Providers;

use Jovian\Engines\Sdl3\Framebuffers\SDL3FramebufferDriver;
use ReflectionException;
use Surface\Rendering\Framebuffers;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianSdl3ServiceProvider extends ServiceProvider
{
    /**
     * Registers 'sdl3' on Topside's framebuffer manager: pixels in SDL surfaces, CPU only.
     *
     * @throws ReflectionException
     */
    public function boot(): void
    {
        Framebuffers::manager()->extend('sdl3', fn (): SDL3FramebufferDriver => new SDL3FramebufferDriver());
    }
}
