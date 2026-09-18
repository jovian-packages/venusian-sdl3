<?php

declare(strict_types=1);

use Jovian\Bindings\Sdl3\Enums\SDLGPUTextureFormat;
use Jovian\Bindings\Sdl3\Enums\SDLPixelFormat;
use Jovian\Venusian\Sdl3\Support\Pixels;

it('swizzles BGRA to RGBA in place', function () {
    expect(Pixels::swizzleBgraToRgba("\x01\x02\x03\x04\x05\x06\x07\x08"))->toBe("\x03\x02\x01\x04\x07\x06\x05\x08");
});

it('knows which swapchain formats are BGRA', function () {
    expect(Pixels::isBgra(SDLGPUTextureFormat::B8G8R8A8_UNORM->value))->toBeTrue()
        ->and(Pixels::isBgra(SDLGPUTextureFormat::B8G8R8A8_UNORM_SRGB->value))->toBeTrue()
        ->and(Pixels::isBgra(SDLGPUTextureFormat::R8G8B8A8_UNORM->value))->toBeFalse();
});

it('rgba32 is the format whose bytes are R, G, B, A on this host', function () {
    $little = unpack('S', "\x01\x00")[1] === 1;

    expect(Pixels::rgba32())->toBe($little ? SDLPixelFormat::ABGR8888 : SDLPixelFormat::RGBA8888);
});
