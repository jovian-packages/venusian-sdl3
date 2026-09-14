<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Sessions;

use Jovian\Bindings\Sdl3\Enums\SDLEventType;
use Jovian\Bindings\Sdl3\Enums\SDLInitFlags;
use Jovian\Bindings\Sdl3\Enums\SDLWindowFlags;
use Jovian\Bindings\Sdl3\Events\SDLEvents;
use Jovian\Bindings\Sdl3\SDL;
use Jovian\Bindings\Sdl3\SDLError;
use Jovian\Bindings\Sdl3\Video\SDLVideo;
use Jovian\Venusian\Sdl3\Contracts\LendsToEngine;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3StageException;
use Jovian\Venusian\Sdl3\Stages\SdlStagedWindow;
use Jovian\Venusian\Sdl3\Surfaces\SdlGLSurface;
use Jovian\Venusian\Sdl3\Surfaces\SdlMetalView;
use Jovian\Venusian\Sdl3\Surfaces\SdlVulkanSurface;
use Surface\Contracts\Drawing\GPUEngineDriver;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Stage\StageException;
use Surface\Contracts\Stage\StageHost;
use Surface\Stage\StagedWindow;
use Surface\Stage\StageSession;

/**
 * The sdl3 stage host. SDL video starts at the first connect(). Its pump
 * drains SDL's queue without waiting (the os resource owns the tick's idle
 * wait), routes window events to their stage by window id, and frees every
 * event it does not read — a reader frees the event it decodes. Input events
 * are dropped unread until Surface\HumanInput exists. Every mint failure
 * is a StageException: an engine's own attach() failure is wrapped as
 * Sdl3StageException::attachFailed, the engine's exception as previous.
 */
final class SdlStageSession extends StageSession
{
    /** @var array<int, SdlStagedWindow> keyed by SDL window id */
    private array $stages = [];

    public function host(): StageHost
    {
        return StageHost::SDL3;
    }

    public function sharesNativePump(): bool
    {
        return false;
    }

    protected function initializeEngine(): void
    {
        if (! SDL::SDLInit(SDLInitFlags::VIDEO->value)) {
            throw Sdl3StageException::init(SDLError::SDLGetError());
        }
    }

    protected function connectToEngine(): void {}

    /** Closes every stage; a close that throws does not spare the rest. The first failure is rethrown. */
    protected function disconnectEngine(): void
    {
        $failure = null;
        foreach ($this->stages as $stage) {
            try {
                $stage->close();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        $this->stages = [];

        if (! is_null($failure)) {
            throw $failure;
        }
    }

    protected function pumpEngine(int $budget_ms): int
    {
        $count = 0;
        while (! is_null($event = SDLEvents::SDLPollEvent())) {
            $count++;
            $this->route((int) $event['ptr'], (int) $event['event_type']);
        }

        $this->stages = array_filter($this->stages, fn (SdlStagedWindow $stage) => $stage->isOpen());

        return $count;
    }

    protected function mintStage(string $name, GPUEngineDriver $engine, int $width, int $height): StagedWindow
    {
        $kind = $engine->surfaceKind();
        if ($this->refuses($kind)) {
            throw Sdl3StageException::unsupported(StageHost::SDL3, $engine->engine(), $kind);
        }

        if ($kind === SurfaceKind::GL_CONTEXT) {
            SdlGLSurface::requestAttributes();
        }

        try {
            $window = SDLVideo::SDLCreateWindow($name, $width, $height, $this->flags($kind));
        } catch (\RuntimeException $e) {
            throw Sdl3StageException::windowFailed($name, $e->getMessage());
        }

        $lent = null;
        try {
            [$width, $height] = SDLVideo::SDLGetWindowSize($window);   // what SDL gave, in points
            $width = (int) $width;
            $height = (int) $height;
            $scale = SdlStagedWindow::densityOf($window, $width);      // the engine sizes a lent layer from this
            $lent = $this->lend($kind, $window);
            try {
                $attachment = $engine->attach(new GPUHost(
                    $window,
                    $width,
                    $height,
                    $scale,
                    gl: $lent instanceof SdlGLSurface ? $lent : null,
                    layer: $lent instanceof SdlMetalView ? $lent->layer : 0,
                    vk: $lent instanceof SdlVulkanSurface ? $lent : null,
                ));
            } catch (\Throwable $e) {
                // Rolled back below like any mint failure; a StageException is never re-wrapped.
                throw $e instanceof StageException ? $e : Sdl3StageException::attachFailed(StageHost::SDL3, $engine->engine(), $e);
            }
        } catch (\Throwable $e) {
            try {
                $lent?->release();
            } catch (\Throwable) {
                // The attach failure is the one worth reporting.
            } finally {
                SDLVideo::SDLDestroyWindow($window);
            }

            throw $e;
        }

        $stage = new SdlStagedWindow($name, $engine->engine(), $attachment->executor, $width, $height, $scale, $window, $lent);

        return $this->stages[SDLVideo::SDLGetWindowID($window)] = $stage;
    }

    private function route(int $ptr, int $type): void
    {
        if ($type === SDLEventType::QUIT->value) {
            SDLEvents::SDLFreeEvent($ptr);
            foreach ($this->stages as $stage) {
                $stage->closeRequested();
            }

            return;
        }

        if ($type < SDLEventType::WINDOW_SHOWN->value || $type > SDLEventType::WINDOW_HDR_STATE_CHANGED->value) {
            SDLEvents::SDLFreeEvent($ptr);

            return;
        }

        $window = SDLEvents::SDLReadEvent($ptr, 'window');   // frees the event
        $stage = $this->stages[(int) $window['window_id']] ?? null;
        if (is_null($stage) || ! $stage->isOpen()) {   // queued before close(): its window is gone
            return;
        }

        match ($type) {
            SDLEventType::WINDOW_CLOSE_REQUESTED->value => $stage->closeRequested(),
            SDLEventType::WINDOW_RESIZED->value,
            SDLEventType::WINDOW_PIXEL_SIZE_CHANGED->value,
            SDLEventType::WINDOW_DISPLAY_SCALE_CHANGED->value => $stage->nativeResized(),
            default => null,
        };
    }

    /** Metal views exist only on macOS; there Vulkan attaches as a LAYER through one (MoltenVK), so VULKAN_SURFACE is Linux only. */
    private function refuses(SurfaceKind $kind): bool
    {
        return match ($kind) {
            SurfaceKind::LAYER => PHP_OS_FAMILY !== 'Darwin',
            SurfaceKind::VULKAN_SURFACE => PHP_OS_FAMILY === 'Darwin',
            SurfaceKind::GL_CONTEXT, SurfaceKind::HOST_WINDOW => false,
        };
    }

    private function flags(SurfaceKind $kind): int
    {
        return SDLWindowFlags::RESIZABLE->value
            | SDLWindowFlags::HIGH_PIXEL_DENSITY->value
            | SDLWindowFlags::HIDDEN->value
            | match ($kind) {
                SurfaceKind::GL_CONTEXT => SDLWindowFlags::OPENGL->value,
                SurfaceKind::LAYER => SDLWindowFlags::METAL->value,
                SurfaceKind::VULKAN_SURFACE => SDLWindowFlags::VULKAN->value,
                SurfaceKind::HOST_WINDOW => 0,
            };
    }

    private function lend(SurfaceKind $kind, int $window): ?LendsToEngine
    {
        return match ($kind) {
            SurfaceKind::GL_CONTEXT => SdlGLSurface::create($window),
            SurfaceKind::LAYER => SdlMetalView::create($window),
            SurfaceKind::VULKAN_SURFACE => new SdlVulkanSurface($window),
            SurfaceKind::HOST_WINDOW => null,
        };
    }
}
