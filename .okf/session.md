---
type: Service
title: The sdl3 stage host
description: >-
  SdlStageSession mints whole SDL windows an engine owns, lends each engine
  what its surface kind needs, and pumps SDL's queue on its own.
resource: src/Sessions/SdlStageSession.php
tags: [sdl3, stage, host, gl, metal, vulkan, lender, cpu]
status: draft
generated:
  by: cursor-grok-4.6/cursor
  at: "2026-09-18T04:45:00Z"
sources:
  - id: stage
    resource: ../../venusian/surface/.okf/stage.md
    title: Surface stages — hosts and engines by alias
  - id: events
    resource: ../../php-io-extensions/sdl3/.okf/api/events.md
    title: ext-sdl3 events — PollEvent, ReadEvent, free rule
  - id: cpu
    resource: src/Stages/SdlCPUStagedWindow.php
    title: SdlCPUStagedWindow
  - id: route
    resource: src/Contracts/RoutableStage.php
    title: RoutableStage
---

# Overview

Alias `stage.sdl3` → `SdlStageSession` (singleton). Fills `Surface\Stage\StageSession`. GPU stages are `SdlStagedWindow` over `Surface\Stage\StagedWindow`; CPU stages are `SdlCPUStagedWindow` over `Surface\Stage\CPUStagedWindow`. Both implement package-local `RoutableStage` (`name`, `window()`, `windowId`, `isOpen`, `nativeResized`, `closeRequested`) so `route()` and `windowHandles()` never name a concrete class. The public readonly `$window` stays on the GPU class — GPU feature tests read the property; `windowHandles()` calls `window()`.[^stage]

# Lifecycle

- `SDL_Init(VIDEO)` at first `connect()`, not at construction. Failure → `Sdl3StageException::init`.
- `sharesNativePump()` false: stage resource pumps it. `pump()` drains `SDLPollEvent` without waiting.
- `disconnect()` closes every stage; a throwing close spares none, first failure rethrown.

# Pump

- SDL has one event queue; the session no longer polls it directly. `SdlEventPump` (`src/Events/SdlEventPump.php`) owns `SDLPollEvent`/`SDLReadEvent`/`SDLFreeEvent`. The session builds one (constructor default `new SdlEventPump()`, container gives the shared instance) and gives it `routeWindowsTo($this->route(...))` in `connectToEngine()`. `pumpEngine()` is just `$this->pump->drain()` then the closed-stage sweep.
- Stages keyed by SDL window id.
- The pump hands QUIT and every WINDOW_* event to the router; anything else it decodes for the sdl3 input engine (buffered) if it has called `wantInput(true)`, else frees unread. This package's `route()` never sees non-window types any more.
- QUIT → free event, `closeRequested()` on every stage.
- `WINDOW_SHOWN`..`WINDOW_HDR_STATE_CHANGED` → `SDLReadEvent($ptr, 'window')` (frees it), route by `window_id`. CLOSE_REQUESTED → `closeRequested()`. RESIZED / PIXEL_SIZE_CHANGED / DISPLAY_SCALE_CHANGED → `nativeResized()`.
- Never free after a read.[^events]
- Events for a stage closed mid-drain skipped (window gone); closed stages dropped after each drain.
- `windowName(int $window_id): ?string` and `windowHandles(): array<int, int>` (open stages only, SDL window id → SDL window handle) let the sdl3 input engine ([input-engine.md](/input-engine.md)) name the window under the mouse and start text input per stage window. The engine is the pump's input consumer: `wantInput(true)` at its `connect()`, `false` at `disconnect()`.

# Minting

Window flags: `RESIZABLE | HIGH_PIXEL_DENSITY | HIDDEN` plus one per kind. Host size = SDL's real point size; `scale` = `SDLGetWindowPixelDensity` (pixels/points fallback). Engines size a lent layer from `scale`; wrong scale doubles MoltenVK's extent.

| Kind | Flag | Lends | GPUHost field |
|---|---|---|---|
| HOST_WINDOW | — | nothing | `native_view` |
| GL_CONTEXT | OPENGL | `SdlGLSurface` | `gl` |
| LAYER | METAL | `SdlMetalView` (macOS only) | `layer` |
| VULKAN_SURFACE | VULKAN | `SdlVulkanSurface` (Linux only) | `vk` |

- GL: attributes set before window. macOS 4.1 core; else ES 3.0 (Pi GL 3.1 has no GLSL 1.50). Swap interval 1.
- LAYER off macOS → `StageException::unsupported`. `SDLMetalCreateView` throws; wrapped as `lendFailed`. Layer borrowed, valid until view destroyed.
- VULKAN_SURFACE refused on macOS by kind (`StageException::unsupported`): Vulkan there attaches as LAYER through the Metal view (MoltenVK). Linux only; Pi (XWayland) names xlib.
- Vulkan list returned untouched, SDL's order. Engine calls `destroySurface()` exactly once, after its swapchain; host never destroys the surface. `release()` no-op.
- Attach failure → lend released (its own throw swallowed), window destroyed in `finally`, then rethrown as `Sdl3StageException::attachFailed` (host + engine named, engine exception as previous). A `StageException` passes through unwrapped. Every mint failure is a `StageException`.

## CPU — `mintCPUStage()`

Constructor takes `?string $renderer_name = 'software'`. The provider passes `config('stage.cpu_renderer', 'software')` (`STAGE_CPU_RENDERER`). `null` is `SDLCreateRenderer($window)` with one argument so SDL picks.

Flags: `RESIZABLE | HIGH_PIXEL_DENSITY | HIDDEN` — no GL / Metal / Vulkan.

One streaming texture at **canvas** size, `Pixels::rgba32()` (little-endian `ABGR8888`, else `RGBA8888` — memory order R,G,B,A). Scale mode `NEAREST`. Logical presentation `Fits::of($fit)` at canvas size. Pitch = `$canvas->width * 4`. `[]` from `SDLCreateTexture` is `Sdl3StageException::textureFailed`. Texture / renderer / window roll back on any native failure; a PHP `attach()` exception is not wrapped.

`applyPresent`: update texture → black clear → blit → present. `nativeResized` re-reads points + density — it does not remint the texture. `releaseEngine` destroys texture then renderer (idempotent, zeros the handles) before `destroyNative` kills the window.

| Kind | Flag | What it presents |
|---|---|---|
| GPU (`open` / `mintStage`) | per surface kind above | executor into a lent layer |
| CPU (`openCPU` / `mintCPUStage`) | no GPU flag | canvas `rgba8()` through a software renderer |

# Close order

GPU `close()`: executor release → lend `release()` (GL context / Metal view) → `SDL_DestroyWindow`. Window destroy in `finally`.
CPU `close()`: texture → renderer → window.
