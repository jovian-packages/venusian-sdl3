<?php

declare(strict_types=1);

use Jovian\Bindings\Sdl3\Enums\SDLGPUTextureFormat;
use Jovian\Venusian\Sdl3\Sdl3GpuContext;
use Jovian\Venusian\Sdl3\Sdl3GpuExecutor;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\Topology;
use Surface\Contracts\Drawing\Transform;
use Surface\Contracts\NativeWindows\Views\Color;

/** An executor over an unbooted context: enough to prove the guards and the recording rules, no GPU. */
function offlineExecutor(): Sdl3GpuExecutor
{
    $context = (new ReflectionClass(Sdl3GpuContext::class))->newInstanceWithoutConstructor();

    return new Sdl3GpuExecutor($context, 0, SDLGPUTextureFormat::B8G8R8A8_UNORM->value, 64, 64);
}

it('throws the contract\'s DrawingException for frame-only calls outside a frame', function () {
    $executor = offlineExecutor();

    expect(fn () => $executor->readPixels())->toThrow(DrawingException::class)
        ->and(fn () => $executor->endFrame())->toThrow(DrawingException::class)
        ->and(fn () => $executor->commandBuffer())->toThrow(DrawingException::class)
        ->and(fn () => $executor->draw(Topology::TRIANGLES, '', 0, Transform::identity()))->toThrow(DrawingException::class);
});

it('records no indexed draw without indices, a one-point strip included', function () {
    $executor = offlineExecutor();
    (new ReflectionProperty(Sdl3GpuExecutor::class, 'frame'))
        ->setValue($executor, ['cb' => 1, 'swapchain' => 1, 'cleared' => false, 'clear' => new Color(0.0, 0.0, 0.0)]);
    $vertex = pack('g9', 0.0, 0.0, 0.0, 1.0, 1.0, 1.0, 1.0, 0.0, 0.0);

    $executor->drawIndexed(Topology::LINE_STRIP, $vertex, 1, pack('v', 0), 1, Transform::identity());
    $executor->drawIndexed(Topology::TRIANGLES, $vertex, 1, '', 0, Transform::identity());

    expect((new ReflectionProperty(Sdl3GpuExecutor::class, 'recorded'))->getValue($executor))->toBe([]);
});
