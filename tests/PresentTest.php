<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Sdl3Device;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\Region;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * Presenting: a hidden SDL window of this process stands in for a window a
 * toolkit canvas wraps (slice 9b).
 */

beforeEach(fn () => video());

function sdlWindow(int $width = 64, int $height = 32): SDL_Window
{
    video();

    return SDL_CreateWindow('venusian-sdl3 test', $width, $height, SDL_WINDOW_HIDDEN) ?? throw new RuntimeException('SDL_CreateWindow: '.SDL_GetError());
}

function windowSurface(SDL_Window $window, int $width = 64, int $height = 32): LentSurface
{
    return new LentSurface(SurfaceKind::SDL_WINDOW, ['window' => $window->pointer()], fn (): array => [$width, $height]);
}

it('claims the window it adopts', function (): void {
    $window = sdlWindow();
    $device = new Sdl3Device;

    $device->adopt(windowSurface($window));

    expect(SDL_GetGPUSwapchainTextureFormat($device->gpu(), $window))->not->toBe(SDL_GPU_TEXTUREFORMAT_INVALID);
    $device->release();
    SDL_DestroyWindow($window);
});

it('refuses to present before adopting a surface', function (): void {
    $device = new Sdl3Device;
    $device->target(8, 8, 1);

    $device->present(windowSurface(sdlWindow()));
})->throws(DrawingException::class, 'sdl3: adopt() a surface before present().');

it('copies the target into the swapchain and presents it, or answers false when no texture is ready', function (): void {
    $window = sdlWindow();
    $device = new Sdl3Device;
    $engine = new GpuRenderingEngine($device, 64, 32);
    $device->adopt(windowSurface($window));
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));

    $results = [];
    for ($i = 0; $i < 10; $i++) {
        $results[] = $device->present(windowSurface($window));
        SDL_PumpEvents();
        usleep(20000);
    }
    $device->finish();

    expect($results)->toContain(true)
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 64, 1, 1))->toBe('0080ffff');
    $engine->release();
    SDL_DestroyWindow($window);
})->skip(fn () => PHP_OS_FAMILY === 'Linux' && getenv('WAYLAND_DISPLAY') === false, 'a window needs the Wayland session');

it('never waits long for a swapchain texture, flooded with presents', function (): void {
    $window = sdlWindow(256, 256);
    $device = new Sdl3Device;
    $engine = new GpuRenderingEngine($device, 256, 256);
    $device->adopt(windowSurface($window, 256, 256));
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 0, 0)));

    // More presents than swapchain images, no pumping between them. A backend
    // either hands out no texture (false) or paces to the display (SDL over
    // Metal waits on nextDrawable for at most a refresh); neither may stall.
    $results = [];
    $longest = 0;
    for ($i = 0; $i < 8; $i++) {
        $started = hrtime(true);
        $results[] = $device->present(windowSurface($window, 256, 256));
        $longest = max($longest, hrtime(true) - $started);
    }
    $device->finish();

    expect($results[0])->toBeTrue()
        ->and($longest / 1e6)->toBeLessThan(50.0);
    $engine->release();
    SDL_DestroyWindow($window);
})->skip(fn () => PHP_OS_FAMILY === 'Linux' && getenv('WAYLAND_DISPLAY') === false, 'a window needs the Wayland session');

it('answers false and cancels when no swapchain texture is ready', function (): void {
    $window = sdlWindow(16, 16);
    $device = new Sdl3Device;
    $engine = new GpuRenderingEngine($device, 16, 16);
    $device->adopt(windowSurface($window, 16, 16));
    // Taken back from the device behind its back: the acquire fails, as it does with no texture to give.
    SDL_ReleaseWindowFromGPUDevice($device->gpu(), $window);

    $presented = $device->present(windowSurface($window, 16, 16));
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));

    expect($presented)->toBeFalse()
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 16, 1, 1))->toBe('0080ffff');
    $engine->release();
    SDL_DestroyWindow($window);
});

it('answers false for a released surface', function (): void {
    $window = sdlWindow(8, 8);
    $device = new Sdl3Device;
    $device->target(8, 8, 1);
    $surface = windowSurface($window, 8, 8);
    $device->adopt($surface);
    $surface->release();

    expect($device->present($surface))->toBeFalse();
    $device->release();
    SDL_DestroyWindow($window);
});

it('lets go of everything on release()', function (): void {
    $device = new Sdl3Device;
    $engine = new GpuRenderingEngine($device, 8, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));

    $engine->release();

    expect(fn () => $device->draw(Surface\Drawing\Gpu\Lowering::lower([['clear', 0xFF0000FF]], 8, 8)))
        ->toThrow(DrawingException::class, 'sdl3: target() comes before draw().');
});

it('lets go of the target\'s own texture on release()', function (): void {
    // 16 MB a round at 2048 × 2048 if the resolved texture outlives the device.
    $round = function (): void {
        $engine = new GpuRenderingEngine(new Sdl3Device, 2048, 2048);
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(1, 2, 3)));
        $engine->release();
    };
    $round();
    $before = residentMb();

    foreach (range(1, 10) as $i) {
        $round();
    }

    expect(residentMb() - $before)->toBeLessThan(64.0);
});

it('releases once: a second release() does nothing', function (): void {
    $device = new Sdl3Device;
    $device->target(8, 8, 1);

    $device->release();
    $device->release();

    expect(fn () => $device->target(8, 8, 1))->toThrow(DrawingException::class, 'sdl3: this device was released.');
});

it('refuses readback and upload after release()', function (string $call): void {
    $device = new Sdl3Device;
    $target = $device->target(8, 8, 1);
    $device->release();

    $call === 'read' ? $target->readRgba8(new Region(0, 0, 8, 8)) : $target->uploadRgba8(str_repeat("\x00", 256), new Region(0, 0, 8, 8));
})->with(['read', 'upload'])->throws(DrawingException::class, 'sdl3: this device was released.');
