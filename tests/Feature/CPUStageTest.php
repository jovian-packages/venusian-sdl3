<?php

declare(strict_types=1);

use Jovian\Venusian\Sdl3\Sessions\SdlStageSession;
use Jovian\Venusian\Sdl3\Stages\SdlCPUStagedWindow;
use Surface\Contracts\Drawing\CPUHost;
use Surface\Contracts\Drawing\Drawing2D;
use Surface\Contracts\Framebuffers\BitDepth;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\PixelFormat;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\Stage\StageFit;
use Surface\Drawing\Engines\DirtyEngine;
use Surface\Drawing\Engines\NFramesEngine;
use Surface\Framebuffers\Php\PhpFramebufferDriver;

/** Engines take a driver directly since Task 5 step 0 — no container in this package. */
function cpuEngineFor(string $kind): object
{
    $driver = new PhpFramebufferDriver();

    return $kind === 'nframes' ? new NFramesEngine($driver) : new DirtyEngine($driver);
}

it('opens a 128x64 panel emulator at 4x and presents frames', function () {
    $session = connectedSdlSession();
    $panel = new CPUHost(128, 64, new FormatSpec(PixelFormat::MONO_VERTICAL_PAGE, BitDepth::B1));

    $stage = $session->openCPU('oled', cpuEngineFor('dirty'), $panel, 512, 256, StageFit::INTEGER_SCALE);

    expect($stage)->toBeInstanceOf(SdlCPUStagedWindow::class)
        ->and($stage->canvasSize())->toBe([128, 64])
        ->and($stage->renderer())->toBeGreaterThan(0)
        ->and($stage->texture())->toBeGreaterThan(0)
        ->and($stage->windowId())->toBeGreaterThan(0);

    $stage->setTitle('venusian CPU stage')->show();
    $stage->onDraw(fn (Drawing2D $g, $f) => $g->fillCircle(64.0, 32.0, 20.0, Color::hex('#fff')));

    for ($i = 0; $i < 3; $i++) {
        expect($stage->renderFrame())->toBeTrue();
        $session->pump();
    }

    expect(bin2hex($stage->flush()))->not->toBe(str_repeat('00', 128 * 8));

    $stage->close();
    expect($stage->isOpen())->toBeFalse()
        ->and($stage->texture())->toBe(0)
        ->and($stage->renderer())->toBe(0);
})->skip(fn () => ! sdl3ExtensionLoaded(), 'ext-sdl3 is not loaded');

it('an RGBA8888 nframes canvas presents into a bigger window and survives a resize', function () {
    $session = connectedSdlSession();
    $canvas = new CPUHost(320, 200, new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32), frames: 2);

    $stage = $session->openCPU('game', cpuEngineFor('nframes'), $canvas, 960, 600, StageFit::LETTERBOX);
    $stage->onDraw(fn (Drawing2D $g) => $g->fillRect(10.0, 10.0, 100.0, 60.0, Color::hex('#f80')))->show();
    $stage->renderFrame();

    $stage->resized(640, 400, 1.0);
    expect($stage->size())->toBe([640, 400])
        ->and($stage->canvasSize())->toBe([320, 200])
        ->and($stage->isOpen())->toBeTrue();

    $stage->close();
})->skip(fn () => ! sdl3ExtensionLoaded(), 'ext-sdl3 is not loaded');
