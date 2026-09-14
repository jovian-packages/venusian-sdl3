<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3;

use Jovian\Bindings\Sdl3\Enums\SDLGPUPresentMode;
use Jovian\Bindings\Sdl3\Enums\SDLGPUSwapchainComposition;
use Jovian\Bindings\Sdl3\Enums\SDLGPUTextureFormat;
use Jovian\Bindings\Sdl3\Gpu\SDLGPU;
use Jovian\Bindings\Sdl3\SDLError;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3DrawingException;
use Surface\Contracts\Drawing\GPUAttachment;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Contracts\Drawing\GPUEngineDriver;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\SurfaceKind;

/** Surface's sdl3 engine: SDL_GPU on the host's own SDL window. One context per engine; the provider binds it as a singleton behind gpu.sdl3. */
final class Sdl3GpuEngine implements GPUEngineDriver
{
    private ?Sdl3GpuContext $context = null;

    public function engine(): GPUEngine
    {
        return GPUEngine::SDL3;
    }

    public function surfaceKind(): SurfaceKind
    {
        return SurfaceKind::HOST_WINDOW;
    }

    public function context(): Sdl3GpuContext
    {
        return $this->context ??= Sdl3GpuContext::boot();
    }

    public function attach(GPUHost $host): GPUAttachment
    {
        $context = $this->context();
        $window = $host->native_view;

        if (! SDLGPU::SDLClaimWindowForGPUDevice($context->device, $window)) {
            throw Sdl3DrawingException::claimFailed(SDLError::SDLGetError());
        }

        SDLGPU::SDLSetGPUSwapchainParameters($context->device, $window, SDLGPUSwapchainComposition::SDR, SDLGPUPresentMode::VSYNC);
        $format = SDLGPU::SDLGetGPUSwapchainTextureFormat($context->device, $window);

        return new GPUAttachment(new Sdl3GpuExecutor(
            $context,
            $window,
            $format instanceof SDLGPUTextureFormat ? $format->value : $format,
            max(1, (int) round($host->width * $host->scale)),
            max(1, (int) round($host->height * $host->scale)),
        ));
    }
}
