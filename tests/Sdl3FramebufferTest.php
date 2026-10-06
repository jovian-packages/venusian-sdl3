<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Sdl3Device;
use Jovian\Engines\Sdl3\Sdl3Framebuffer;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\Region;

beforeEach(fn () => video());

it('is a GpuDevice named sdl3 that presents into an SDL window and needs no handles to lend against', function (): void {
    $device = new Sdl3Device;

    expect($device)->toBeInstanceOf(Surface\Drawing\Gpu\GpuDevice::class)
        ->and($device->name())->toBe('sdl3')
        ->and($device->surfaces())->toBe([SurfaceKind::SDL_WINDOW])
        ->and($device->handles())->toBe([])
        ->and($device->driver())->toBeIn(['metal', 'vulkan']);
});

it('makes a stencil in a format this device supports', function (): void {
    $device = new Sdl3Device;

    expect($device->stencilFormat())->toBeIn([SDL_GPU_TEXTUREFORMAT_D24_UNORM_S8_UINT, SDL_GPU_TEXTUREFORMAT_D32_FLOAT_S8_UINT])
        ->and(SDL_GPUTextureSupportsFormat($device->gpu(), $device->stencilFormat(), SDL_GPU_TEXTURETYPE_2D, SDL_GPU_TEXTUREUSAGE_DEPTH_STENCIL_TARGET))->toBeTrue()
        ->and(SDL_GPUTextureSupportsSampleCount($device->gpu(), $device->stencilFormat(), SDL_GPU_SAMPLECOUNT_4))->toBeTrue();
});

it('makes a transparent black RGBA8 target of the asked size', function (int $samples): void {
    $target = (new Sdl3Device)->target(24, 8, $samples);

    expect($target)->toBeInstanceOf(Sdl3Framebuffer::class)
        ->and($target)->toBeInstanceOf(GLFramebuffer::class)
        ->and([$target->viewportWidth(), $target->viewportHeight()])->toBe([24, 8])
        ->and($target->hostFormat())->toEqual(FormatSpec::rgba8())
        ->and($target->toRgba8())->toBe(str_repeat("\x00\x00\x00\x00", 24 * 8))
        ->and($target->texture())->toBeInstanceOf(SDL_GPUTexture::class);
})->with([1, 4]);

it('draws with one sample or four', function (): void {
    (new Sdl3Device)->target(8, 8, 2);
})->throws(DrawingException::class, 'sdl3 draws with 1 or 4 samples, got 2.');

it('refuses an empty target', function (): void {
    (new Sdl3Device)->target(0, 8, 4);
})->throws(DrawingException::class, 'sdl3 needs a target of at least 1 × 1, got 0 × 8.');

it('uploads a region and reads it back, tightly packed', function (int $samples): void {
    $target = (new Sdl3Device)->target(16, 8, $samples);
    $block = str_repeat("\x10\x20\x30\xff", 6);

    $target->uploadRgba8($block, new Region(4, 2, 3, 2));

    expect($target->readRgba8(new Region(4, 2, 3, 2)))->toBe($block)
        ->and(strlen($target->readRgba8(new Region(0, 0, 16, 8))))->toBe(16 * 8 * 4)
        ->and(pixelOf($target->toRgba8(), 16, 3, 2))->toBe('00000000')
        ->and(pixelOf($target->toRgba8(), 16, 6, 3))->toBe('102030ff');
})->with([1, 4]);

it('serves the pixel calls of a Framebuffer through the GPU', function (): void {
    $target = (new Sdl3Device)->target(16, 8, 4);

    $target->setPixel(2, 2, 0xFF0000FF)->fill(0x0000FFFF)->setSegment(0, 0, 4, 1, 0x00FF00FF);

    expect(pixelOf($target->toRgba8(), 16, 2, 2))->toBe('0000ffff')
        ->and(pixelOf($target->toRgba8(), 16, 1, 0))->toBe('00ff00ff')
        ->and($target->getPixel(1, 0))->toBe(0x00FF00FF)
        ->and($target->damage())->toEqual([new Region(0, 0, 16, 8)]);
});

it('keeps an old framebuffer readable after the target is re-made', function (): void {
    $device = new Sdl3Device;
    $old = $device->target(8, 8, 4);
    $old->fill(0xFF0000FF);

    $new = $device->target(12, 6, 1);

    expect($new)->not->toBe($old)
        ->and([$new->viewportWidth(), $new->viewportHeight()])->toBe([12, 6])
        ->and(pixelOf($old->toRgba8(), 8, 3, 3))->toBe('ff0000ff')
        ->and(pixelOf($new->toRgba8(), 12, 3, 3))->toBe('00000000');
});

it('reads frame after frame back without holding on to GPU memory', function (): void {
    // 1 MB a readback at 512 × 512: a command buffer SDL never cleans keeps its transfer buffer.
    $engine = sdl3Engine(512, 512);
    $frame = fn (int $i) => $engine->frame(fn (Surface\Contracts\Drawing\RenderingEngine $g) => $g->clear(Surface\NutsAndBolts\Color::rgb($i % 256, 0, 0)));
    foreach (range(1, 20) as $i) {
        $frame($i);
        $engine->framebuffer()->toRgba8();
    }
    $before = residentMb();

    foreach (range(1, 150) as $i) {
        $frame($i);
        $engine->framebuffer()->toRgba8();
    }

    expect(residentMb() - $before)->toBeLessThan(48.0);
});

it('refuses an upload whose bytes do not fill the region', function (): void {
    (new Sdl3Device)->target(16, 8, 1)->uploadRgba8("\xff\x00\x00\xff", new Region(0, 0, 16, 8));
})->throws(DrawingException::class, 'sdl3: 4 bytes do not fill a 16 × 8 region (512 bytes).');

it('refuses a region outside the target', function (string $call): void {
    $target = (new Sdl3Device)->target(16, 8, 1);
    $region = new Region(10, 4, 16, 8);

    $call === 'read' ? $target->readRgba8($region) : $target->uploadRgba8(str_repeat("\x00", 16 * 8 * 4), $region);
})->with(['read', 'upload'])->throws(DrawingException::class, 'sdl3: the region 16 × 8 at (10, 4) lies outside the 16 × 8 target.');

it('refuses a target past the largest texture', function (): void {
    (new Sdl3Device)->target(Sdl3Device::LARGEST + 1, 8, 1);
})->throws(DrawingException::class, 'sdl3: 16385 × 8 is past the largest texture SDL_GPU makes, 16384 × 16384.');
