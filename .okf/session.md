---
type: Service
title: The sdl3 stage host
description: >-
  SdlStageSession mints whole SDL windows an engine owns, lends each engine
  what its surface kind needs, and pumps SDL's queue on its own.
resource: src/Sessions/SdlStageSession.php
tags: [sdl3, stage, host, gl, metal, vulkan, lender]
status: draft
generated:
  by: claude-opus-5/claude-code
  at: "2026-09-14T12:00:00Z"
sources:
  - id: stage
    resource: ../../venusian/surface/.okf/stage.md
    title: Surface stages — hosts and engines by alias
  - id: events
    resource: ../../php-io-extensions/sdl3/.okf/api/events.md
    title: ext-sdl3 events — PollEvent, ReadEvent, free rule
---

# Overview

Alias `stage.sdl3` → `SdlStageSession` (singleton). Fills `Surface\Stage\StageSession`; stages are `SdlStagedWindow` over `Surface\Stage\StagedWindow`.[^stage]

# Lifecycle

- `SDL_Init(VIDEO)` at first `connect()`, not at construction. Failure → `Sdl3StageException::init`.
- `sharesNativePump()` false: stage resource pumps it. `pump()` drains `SDLPollEvent` without waiting.
- `disconnect()` closes every stage; a throwing close spares none, first failure rethrown.

# Pump

- Stages keyed by SDL window id.
- QUIT → free event, `closeRequested()` on every stage.
- `WINDOW_SHOWN`..`WINDOW_HDR_STATE_CHANGED` → `SDLReadEvent($ptr, 'window')` (frees it), route by `window_id`. CLOSE_REQUESTED → `closeRequested()`. RESIZED / PIXEL_SIZE_CHANGED / DISPLAY_SCALE_CHANGED → `nativeResized()`.
- Anything else → `SDLFreeEvent`. Never free after a read.[^events]
- Events for a stage closed mid-drain skipped (window gone); closed stages dropped after each drain.

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

# Close order

`close()`: executor release → lend `release()` (GL context / Metal view) → `SDL_DestroyWindow`. Window destroy in `finally`.
