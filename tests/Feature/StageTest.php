<?php

declare(strict_types=1);

use Jovian\Venusian\Sdl3\Exceptions\Sdl3StageException;
use Jovian\Venusian\Sdl3\Sessions\SdlStageSession;
use Jovian\Venusian\Sdl3\Stages\SdlStagedWindow;
use Jovian\Venusian\Sdl3\Surfaces\SdlGLSurface;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Stage\StageException;
use Venusian\Tests\Support\StubEngine;

function connectedSdlSession(): SdlStageSession
{
    if (! sdl3ExtensionLoaded()) {
        test()->markTestSkipped('ext-sdl3 is not loaded');
    }

    $session = new SdlStageSession();
    try {
        $session->connect();
    } catch (Sdl3StageException $e) {
        test()->markTestSkipped($e->getMessage());
    }

    return $session;
}

it('opens a hidden HOST_WINDOW stage, pumps, and closes it terminally', function () {
    $session = connectedSdlSession();
    $engine = new StubEngine();

    $stage = $session->open('test', $engine, 160, 120);

    expect($stage)->toBeInstanceOf(SdlStagedWindow::class)
        ->and($engine->hosts[0]->native_view)->toBe($stage->window)
        ->and($stage->windowId())->toBeGreaterThan(0);

    $stage->setTitle('venusian-sdl3 test')->show();
    expect($session->pump())->toBeGreaterThanOrEqual(0);

    $stage->close();
    expect($stage->isOpen())->toBeFalse()
        ->and($stage->executor()->released)->toBeTrue();

    $session->disconnect();
});

it('lends a GL surface for a GL_CONTEXT engine', function () {
    $session = connectedSdlSession();
    $engine = new StubEngine(SurfaceKind::GL_CONTEXT, GPUEngine::OPENGL);
    $stage = $session->open('gl', $engine, 160, 120);
    $gl = $engine->hosts[0]->gl;

    expect($gl)->toBeInstanceOf(SdlGLSurface::class);
    $gl->makeCurrent();
    expect($gl->drawableSize()[0])->toBeGreaterThan(0);

    $stage->close();
    $session->disconnect();
});

it('lends its Metal view layer for a LAYER engine on macOS', function () {
    if (PHP_OS_FAMILY !== 'Darwin') {
        test()->markTestSkipped('macOS only');
    }

    $session = connectedSdlSession();
    $engine = new StubEngine(SurfaceKind::LAYER, GPUEngine::METAL);
    $stage = $session->open('metal', $engine, 160, 120);

    expect($engine->hosts[0]->layer)->toBeGreaterThan(0);

    $stage->close();
    $session->disconnect();
});

it('refuses a LAYER engine off macOS', function () {
    if (PHP_OS_FAMILY === 'Darwin') {
        test()->markTestSkipped('Linux only');
    }

    $session = connectedSdlSession();

    expect(fn () => $session->open('metal', new StubEngine(SurfaceKind::LAYER, GPUEngine::METAL), 160, 120))
        ->toThrow(StageException::class);

    $session->disconnect();
});

it('lends a Vulkan surface lender that names the WSI', function () {
    if (PHP_OS_FAMILY === 'Darwin') {
        test()->markTestSkipped('Linux only');
    }

    $session = connectedSdlSession();
    $engine = new StubEngine(SurfaceKind::VULKAN_SURFACE, GPUEngine::VULKAN);
    $stage = $session->open('vk', $engine, 160, 120);

    expect($engine->hosts[0]->vk->instanceExtensions())->toContain('VK_KHR_surface');

    $stage->close();
    $session->disconnect();
});

it('refuses a VULKAN_SURFACE engine on macOS, where Vulkan attaches as a LAYER', function () {
    if (PHP_OS_FAMILY !== 'Darwin') {
        test()->markTestSkipped('macOS only');
    }

    $session = connectedSdlSession();

    expect(fn () => $session->open('vk', new StubEngine(SurfaceKind::VULKAN_SURFACE, GPUEngine::VULKAN), 160, 120))
        ->toThrow(StageException::class);

    $session->disconnect();
});

it('closes every stage on disconnect even when one close throws, then rethrows', function () {
    $session = connectedSdlSession();
    $first = $session->open('first', new StubEngine(), 160, 120);
    $second = $session->open('second', new StubEngine(), 160, 120);
    $first->executor()->release_throws = new RuntimeException('release failed');

    expect(fn () => $session->disconnect())->toThrow(RuntimeException::class, 'release failed')
        ->and($first->isOpen())->toBeFalse()
        ->and($second->isOpen())->toBeFalse()
        ->and($second->executor()->released)->toBeTrue();
});

it('wraps an engine attach failure as a StageException naming host and engine, and rolls the window back', function () {
    $session = connectedSdlSession();
    $cause = new RuntimeException('no device');
    $engine = new StubEngine(SurfaceKind::GL_CONTEXT, GPUEngine::OPENGL, $cause);

    try {
        $session->open('broken', $engine, 160, 120);
        expect(false)->toBeTrue();
    } catch (Sdl3StageException $e) {
        expect($e)->toBeInstanceOf(StageException::class)
            ->and($e->getMessage())->toBe("The 'sdl3' stage host could not attach a 'opengl' engine: no device")
            ->and($e->getPrevious())->toBe($cause);
    }

    // The rolled-back mint left nothing behind: the next open on the same session works.
    $stage = $session->open('after', new StubEngine(), 160, 120);
    expect($stage->isOpen())->toBeTrue();

    $stage->close();
    $session->disconnect();
});

it('passes a StageException from the engine through unwrapped', function () {
    $session = connectedSdlSession();
    $cause = StageException::closed('elsewhere');

    expect(fn () => $session->open('broken', new StubEngine(attach_failure: $cause), 160, 120))
        ->toThrow(fn (StageException $e) => expect($e)->toBe($cause));

    $session->disconnect();
});
