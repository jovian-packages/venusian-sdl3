<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Surfaces;

use Jovian\Bindings\Sdl3\Video\SDLVideo;
use Jovian\Bindings\Sdl3\Video\SDLVulkan;
use Jovian\Venusian\Sdl3\Contracts\LendsToEngine;
use Surface\Contracts\Drawing\VulkanSurfaceLender;

/**
 * An SDL window as a Vulkan surface lender — Linux only; on macOS Vulkan
 * attaches as a LAYER through SdlMetalView. The window was created with
 * SDL_WINDOW_VULKAN, which loads SDL's Vulkan library — on Linux the same
 * libvulkan.so.1 ext-vulkan opened, so instance and surface share one loader.
 * The engine's executor calls destroySurface() once, after its swapchain;
 * that is the sole destroy, so release() owes nothing.
 */
final class SdlVulkanSurface implements VulkanSurfaceLender, LendsToEngine
{
    public function __construct(private readonly int $window) {}

    /** SDL's list, untouched and in SDL's order. */
    public function instanceExtensions(): array
    {
        return SDLVulkan::SDLVulkanGetInstanceExtensions();
    }

    public function createSurface(int $instance): int
    {
        return SDLVulkan::SDLVulkanCreateSurface($this->window, $instance, 0);
    }

    public function destroySurface(int $instance, int $surface): void
    {
        SDLVulkan::SDLVulkanDestroySurface($instance, $surface, 0);
    }

    public function drawableSize(): array
    {
        [$width, $height] = SDLVideo::SDLGetWindowSizeInPixels($this->window);

        return [max(1, (int) $width), max(1, (int) $height)];
    }

    public function release(): void {}
}
