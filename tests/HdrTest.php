<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Sdl3Device;
use Surface\Contracts\Drawing\ColorSpace;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\TargetFormat;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\HdrImage;
use Surface\NutsAndBolts\Color;

beforeEach(fn () => video());

function sdlHdrEngine(int $width, int $height, ColorSpace $space = ColorSpace::ExtendedLinearSrgb): GpuRenderingEngine
{
    $device = new Sdl3Device;
    if ($device->targetFormats() === []) {
        test()->markTestSkipped('this GPU renders no half-float target');
    }

    return new GpuRenderingEngine($device, $width, $height, Edges::HARD, format: $space === ColorSpace::Hdr10Pq ? TargetFormat::Rgb10A2 : TargetFormat::Rgba16Float, colorspace: $space);
}

it('draws SDR colours as linear light with SDR white at 1.0, and reads them back as they were', function (ColorSpace $space): void {
    $engine = sdlHdrEngine(4, 4, $space);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));
    $white = $engine->hdrSnapshot()->rgba16f();
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(128, 64, 0)));

    expect($white)->toBe(str_repeat(pack('v4', 0x3C00, 0x3C00, 0x3C00, 0x3C00), 16))
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 4, 2, 2))->toBe('804000ff');
})->with([ColorSpace::ExtendedLinearSrgb, ColorSpace::Hdr10Pq]);

it('draws an HdrImage brighter than SDR white', function (): void {
    $engine = sdlHdrEngine(2, 1);
    $hdr = HdrImage::fromFloats([4.0, 2.0, 1.0, 1.0, 0.5, 0.5, 0.5, 1.0], 2, 1);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->image($hdr, 0, 0));

    expect($engine->hdrSnapshot()->rgba16f())->toBe(pack('v8', 0x4400, 0x4000, 0x3C00, 0x3C00, 0x3800, 0x3800, 0x3800, 0x3C00));
});

it('writes RGBA8 into an HDR target as linear light', function (): void {
    $engine = sdlHdrEngine(1, 1);
    $engine->framebuffer()->uploadRgba8("\xFF\xFF\xFF\xFF", new Region(0, 0, 1, 1));

    expect($engine->hdrSnapshot()->rgba16f())->toBe(pack('v4', 0x3C00, 0x3C00, 0x3C00, 0x3C00));
});

it('shows an HDR target through the window\'s composition, and refuses it where the window has no such composition', function (ColorSpace $space, int $composition, int $swapFormat, int $code): void {
    $window = sdlWindow(8, 8);
    $device = new Sdl3Device;
    $device->adopt(windowSurface($window, 8, 8));
    $format = $space === ColorSpace::Hdr10Pq ? TargetFormat::Rgb10A2 : TargetFormat::Rgba16Float;
    $supported = SDL_WindowSupportsGPUSwapchainComposition($device->gpu(), $window, $composition);

    if ($device->targetFormats() === []) {
        expect(fn () => $device->targetAs(8, 8, 1, $format, $space))->toThrow(DrawingException::class, "sdl3 makes no {$format->value} {$space->value} target.");
    } elseif ($supported) {
        $device->targetAs(8, 8, 1, $format, $space);
        expect(SDL_GetGPUSwapchainTextureFormat($device->gpu(), $window))->toBe($swapFormat)
            ->and($device->transform()[0])->toBe($code);
    } else {
        expect(fn () => $device->targetAs(8, 8, 1, $format, $space))
            ->toThrow(DrawingException::class, "sdl3: this window shows no {$space->value} swapchain on {$device->driver()}.");
    }
    $device->release();
    SDL_DestroyWindow($window);
})->with([
    'extended linear' => [ColorSpace::ExtendedLinearSrgb, SDL_GPU_SWAPCHAINCOMPOSITION_HDR_EXTENDED_LINEAR, SDL_GPU_TEXTUREFORMAT_R16G16B16A16_FLOAT, 1],
    'HDR10' => [ColorSpace::Hdr10Pq, SDL_GPU_SWAPCHAINCOMPOSITION_HDR10_ST2084, SDL_GPU_TEXTUREFORMAT_R10G10B10A2_UNORM, 2],
]);
