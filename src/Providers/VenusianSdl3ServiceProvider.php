<?php

namespace Jovian\Engines\Sdl3\Providers;

use Jovian\Engines\Sdl3\Framebuffers\SDL3FramebufferDriver;
use Jovian\Engines\Sdl3\SDLGPUDevice;
use ReflectionException;
use Surface\Contracts\Graphics\Backend;
use Surface\Graphics\Gfx;
use Surface\Rendering\Recorders;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianSdl3ServiceProvider extends ServiceProvider
{
    /**
     * Registers 'sdl3' on Velvet's framebuffers (the SDL3Surface module): pixels in SDL surfaces, CPU only. And 'sdl3'
     * as a graphics engine on the GPU: SDL_GPU.
     *
     * @throws ReflectionException
     */
    public function boot(): void
    {
        $this->registerVelvetFramebufferDriver();
        $this->registerGraphicsEngine();
    }

    /** 'sdl3' on Velvet's framebuffers. */
    protected function registerVelvetFramebufferDriver(): void
    {
        app('velvet.framebuffers')->extend('sdl3', fn () => new SDL3FramebufferDriver());
    }

    /**
     * 'sdl3' on Graphics: SDL_GPU, on the GPU backend.
     *
     * @throws ReflectionException
     */
    protected function registerGraphicsEngine(): void
    {
        if (extension_loaded('sdl3')) {
            Gfx::extend('sdl3', fn (Backend $backend) => new SDLGPUDevice($backend, recorder: fn () => Recorders::driver()->recorder()), Backend::GPU);
        }
    }
}
