<?php

declare(strict_types=1);

use Jovian\Bindings\Sdl3\Enums\SDLInitFlags;
use Jovian\Bindings\Sdl3\Events\SDLEvents;
use Jovian\Bindings\Sdl3\SDL;
use Jovian\Bindings\Sdl3\SDLError;
use Jovian\Bindings\Sdl3\Video\SDLVideo;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3DrawingException;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3StageException;
use Jovian\Venusian\Sdl3\Sdl3GpuEngine;
use Jovian\Venusian\Sdl3\Sdl3GpuExecutor;
use Jovian\Venusian\Sdl3\Sessions\SdlStageSession;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\Frame;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\TextureHandle;
use Surface\Contracts\Drawing\Topology;
use Surface\Contracts\Drawing\Transform;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Drawing\Painter;

beforeEach(function () {
    if (! sdl3ExtensionLoaded()) {
        test()->markTestSkipped('ext-sdl3 is not loaded');
    }
    if (! SDL::SDLInit(SDLInitFlags::VIDEO->value)) {
        test()->markTestSkipped('no video device: '.SDLError::SDLGetError());
    }
});

it('draws top-left-first, blends, reads back RGBA, and presents', function () {
    $window = SDLVideo::SDLCreateWindow('venusian-sdl3 draw test', 64, 64, 0);   // shown: a hidden window may never vend a swapchain texture
    try {
        $attachment = (new Sdl3GpuEngine)->attach(new GPUHost($window, 64, 64, 1.0));
    } catch (Sdl3DrawingException $e) {
        SDLVideo::SDLDestroyWindow($window);
        test()->markTestSkipped($e->getMessage());
    }

    $executor = $attachment->executor;
    $painter = new Painter($executor);
    $begun = false;
    for ($i = 0; $i < 30 && ! $begun; $i++) {
        SDLEvents::SDLPumpEvents();
        $begun = $executor->beginFrame(new Color(0.0, 0.0, 1.0, 1.0));
    }
    expect($begun)->toBeTrue();

    [$width, $height] = $executor->drawableSize();
    $painter->begin($width, $height, 1.0);
    $painter->fillRect(0.0, 0.0, 8.0, 8.0, new Color(1.0, 0.0, 0.0, 1.0));
    $painter->fillRect(16.0, 16.0, 8.0, 8.0, new Color(1.0, 1.0, 1.0, 0.5));
    $painter->flush();
    $pixels = $executor->readPixels();
    $painter->reset();
    $executor->endFrame();

    $at = fn (int $x, int $y) => array_values(unpack('C4', substr($pixels, ($y * $width + $x) * 4, 4)));

    expect($at(1, 1))->toBe([255, 0, 0, 255])                        // top-left is where y = 0 drew
        ->and($at($width - 2, $height - 2))->toBe([0, 0, 255, 255]);  // the clear
    $blend = $at(20, 20);
    expect(abs($blend[0] - 128))->toBeLessThanOrEqual(2)
        ->and(abs($blend[2] - 255))->toBeLessThanOrEqual(2);          // white at 0.5 over blue

    $executor->release();
    SDLVideo::SDLDestroyWindow($window);
});

it('draws through a shown sdl3 stage, reads back mid-frame, and closes', function () {
    $session = new SdlStageSession();
    try {
        $session->connect();
        $stage = $session->open('draw', new Sdl3GpuEngine, 64, 64);   // minted hidden
    } catch (Sdl3StageException|Sdl3DrawingException $e) {
        test()->markTestSkipped($e->getMessage());
    }

    $pixels = '';
    $stage->setClearColor(new Color(0.0, 0.0, 1.0, 1.0))
        ->onDraw(function (Painter $painter, Frame $frame) use (&$pixels, $stage) {
            $painter->fillRect(0.0, 0.0, 8.0, 8.0, new Color(1.0, 0.0, 0.0, 1.0));
            $painter->flush();
            $pixels = $stage->executor()->readPixels();
        })
        ->show();   // no frame runs until shown

    $drawn = false;
    for ($i = 0; $i < 30 && ! $drawn; $i++) {
        $session->pump();
        $drawn = $stage->renderFrame();
    }
    expect($drawn)->toBeTrue();

    [$width, $height] = $stage->drawableSize();
    $at = fn (int $x, int $y) => array_values(unpack('C4', substr($pixels, ($y * $width + $x) * 4, 4)));

    expect(strlen($pixels))->toBe($width * $height * 4)
        ->and($at(1, 1))->toBe([255, 0, 0, 255])
        ->and($at($width - 2, $height - 2))->toBe([0, 0, 255, 255]);

    $stage->close();
    expect($stage->isOpen())->toBeFalse()
        ->and(fn () => $stage->executor()->beginFrame(new Color(0.0, 0.0, 0.0)))->toThrow(Sdl3DrawingException::class);

    $session->disconnect();
});

it('draws indexed, textured, line-strip and point primitives', function () {
    $window = SDLVideo::SDLCreateWindow('venusian-sdl3 primitives test', 64, 64, 0);
    try {
        $attachment = (new Sdl3GpuEngine)->attach(new GPUHost($window, 64, 64, 1.0));
    } catch (Sdl3DrawingException $e) {
        SDLVideo::SDLDestroyWindow($window);
        test()->markTestSkipped($e->getMessage());
    }

    $executor = $attachment->executor;
    $green = $executor->texture(str_repeat("\x00\xFF\x00\xFF", 4), 2, 2);
    $begun = false;
    for ($i = 0; $i < 30 && ! $begun; $i++) {
        SDLEvents::SDLPumpEvents();
        $begun = $executor->beginFrame(new Color(0.0, 0.0, 1.0, 1.0));
    }
    expect($begun)->toBeTrue();

    [$width, $height] = $executor->drawableSize();
    $projection = Transform::orthographic($width, $height);
    $red = fn (float $x, float $y) => pack('g9', $x, $y, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
    $white = fn (float $x, float $y, float $u, float $v) => pack('g9', $x, $y, 0.0, 1.0, 1.0, 1.0, 1.0, $u, $v);

    $executor->drawIndexed(Topology::TRIANGLES, $red(0, 32).$red(8, 32).$red(8, 40).$red(0, 40), 4, pack('v*', 0, 1, 2, 0, 2, 3), 6, $projection);
    $executor->draw(Topology::TRIANGLES, $white(32, 0, 0, 0).$white(40, 0, 1, 0).$white(40, 8, 1, 1).$white(32, 0, 0, 0).$white(40, 8, 1, 1).$white(32, 8, 0, 1), 6, $projection, $green);
    $executor->draw(Topology::LINE_STRIP, $red(0.0, 50.5).$red(10.0, 50.5).$red(20.0, 50.5), 3, $projection);
    $executor->draw(Topology::POINTS, $red(60.5, 60.5), 1, $projection);
    $pixels = $executor->readPixels();
    $executor->endFrame();

    $at = fn (int $x, int $y) => array_values(unpack('C4', substr($pixels, ($y * $width + $x) * 4, 4)));

    expect($at(3, 35))->toBe([255, 0, 0, 255])        // indexed quad
        ->and($at(35, 3))->toBe([0, 255, 0, 255])     // white × green texel
        ->and($at(5, 50))->toBe([255, 0, 0, 255])     // line strip, first segment
        ->and($at(15, 50))->toBe([255, 0, 0, 255])    // line strip, second segment
        ->and($at(60, 60))->toBe([255, 0, 0, 255])    // point
        ->and($at(30, 30))->toBe([0, 0, 255, 255]);   // the clear

    $executor->releaseTexture($green);
    $executor->release();
    SDLVideo::SDLDestroyWindow($window);
});

/** @return array{int, Sdl3GpuExecutor} a shown 64×64 window and its executor */
function sdlGpuWindow(string $title): array
{
    $window = SDLVideo::SDLCreateWindow($title, 64, 64, 0);
    try {
        $attachment = (new Sdl3GpuEngine)->attach(new GPUHost($window, 64, 64, 1.0));
    } catch (Sdl3DrawingException $e) {
        SDLVideo::SDLDestroyWindow($window);
        test()->markTestSkipped($e->getMessage());
    }

    return [$window, $attachment->executor];
}

function beginSdlGpuFrame(Executor $executor, Color $clear): bool
{
    for ($i = 0; $i < 30; $i++) {
        SDLEvents::SDLPumpEvents();
        if ($executor->beginFrame($clear)) {
            return true;
        }
    }

    return false;
}

it('defers a mid-frame texture release until the frame is submitted', function () {
    [$window, $executor] = sdlGpuWindow('venusian-sdl3 deferred release test');
    $blue = new Color(0.0, 0.0, 1.0, 1.0);
    $green = str_repeat("\x00\xFF\x00\xFF", 4);
    $quad = function (Sdl3GpuExecutor $executor, TextureHandle $texture): void {
        [$width, $height] = $executor->drawableSize();
        $v = fn (float $x, float $y, float $u, float $w) => pack('g9', $x, $y, 0.0, 1.0, 1.0, 1.0, 1.0, $u, $w);
        $executor->draw(Topology::TRIANGLES, $v(0, 0, 0, 0).$v(8, 0, 1, 0).$v(8, 8, 1, 1).$v(0, 0, 0, 0).$v(8, 8, 1, 1).$v(0, 8, 0, 1), 6, Transform::orthographic($width, $height), $texture);
    };

    // Flushed at endFrame(): the release waits for the frame's submit.
    $texture = $executor->texture($green, 2, 2);
    expect(beginSdlGpuFrame($executor, $blue))->toBeTrue()
        ->and(fn () => $executor->beginFrame($blue))->toThrow(Sdl3DrawingException::class);   // a live frame is refused
    $quad($executor, $texture);
    $executor->releaseTexture($texture);
    expect($executor->deferredReleases())->toBe(1);
    $executor->endFrame();
    expect($executor->deferredReleases())->toBe(0)
        ->and(fn () => $executor->releaseTexture($texture))->toThrow(Sdl3DrawingException::class);

    // Flushed mid-frame by readPixels(): the released texture still samples.
    $texture = $executor->texture($green, 2, 2);
    expect(beginSdlGpuFrame($executor, $blue))->toBeTrue();
    $quad($executor, $texture);
    $executor->releaseTexture($texture);
    $pixels = $executor->readPixels();
    [$width] = $executor->drawableSize();
    expect(array_values(unpack('C4', substr($pixels, (3 * $width + 3) * 4, 4))))->toBe([0, 255, 0, 255]);
    $executor->endFrame();
    expect($executor->deferredReleases())->toBe(0);

    // Torn down mid-frame: release() submits, then runs the queue.
    $texture = $executor->texture($green, 2, 2);
    expect(beginSdlGpuFrame($executor, $blue))->toBeTrue();
    $quad($executor, $texture);
    $executor->releaseTexture($texture);
    $executor->release();
    expect($executor->deferredReleases())->toBe(0);

    SDLVideo::SDLDestroyWindow($window);
});

it('skips a draw whose scissor clamps to nothing', function () {
    [$window, $executor] = sdlGpuWindow('venusian-sdl3 scissor test');
    expect(beginSdlGpuFrame($executor, new Color(0.0, 0.0, 1.0, 1.0)))->toBeTrue();

    [$width, $height] = $executor->drawableSize();
    $red = fn (float $x, float $y) => pack('g9', $x, $y, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
    $executor->scissor($width + 10, $height + 10, 8, 8);
    $executor->draw(Topology::TRIANGLES, $red(0, 0).$red($width, 0).$red($width, $height).$red(0, 0).$red($width, $height).$red(0, $height), 6, Transform::orthographic($width, $height));
    $executor->unscissor();
    $pixels = $executor->readPixels();
    $executor->endFrame();

    $at = fn (int $x, int $y) => array_values(unpack('C4', substr($pixels, ($y * $width + $x) * 4, 4)));
    expect($at(0, 0))->toBe([0, 0, 255, 255])
        ->and($at($width - 1, $height - 1))->toBe([0, 0, 255, 255]);

    $executor->release();
    SDLVideo::SDLDestroyWindow($window);
});
