---
type: Component
title: The sdl3 GPU engine — SDL_GPU, offscreen target, recorded draws
description: >-
  Sdl3GpuEngine claims the stage's SDL window for one SDL_GPUDevice.
  Sdl3GpuExecutor records Painter draws, replays them into an offscreen
  colour target at endFrame(), blits to the swapchain, and reads back
  mid-frame on a command buffer of its own.
resource: src/Sdl3GpuExecutor.php
tags: [sdl3, sdl-gpu, gpu, engine, executor, readback, offscreen, metal, vulkan]
status: draft
generated:
  by: claude-opus-5/claude-code
  at: "2026-09-14T16:22:16Z"
sources:
  - id: contracts
    resource: ../../venusian/surface/src/Surface/Contracts/Drawing
    title: Surface drawing contracts — Executor, GPUHost, SurfaceKind
  - id: zep
    resource: ../../php-io-extensions/sdl3/sdl3/sdl/gpu/sdlgpu.zep
    title: ext-sdl3 SDLGPU — createinfo arrays mirror the C field names
  - id: enums
    resource: ../sdl3/.okf/enums-and-constants.md
    title: jovian/sdl3 enums — the SDL_GPU createinfo families
---

# Overview

Alias `gpu.sdl3` → `Sdl3GpuEngine` (singleton). `engine()` SDL3, `surfaceKind()` HOST_WINDOW: host lends nothing; engine drives `GPUHost->native_view`, the SDL window.[^contracts] One `Sdl3GpuContext` per engine instance: device, painter shaders, sampler, 1×1 white placeholder, one pipeline per (swapchain format, primitive), built lazily. Device never destroyed; the provider singleton keeps an app to one per process.

# Attach

- `SDLCreateGPUDevice(MSL | SPIRV, debug false)`. Throw → `Sdl3DrawingException::noGpuDriver`.
- `SDLClaimWindowForGPUDevice` false → `claimFailed`. Swapchain SDR + VSYNC. Swapchain format read once; offscreen target and pipelines use it.
- Stage window minted HIDDEN; claim works hidden. No frame until `show()`.

# Why offscreen + blit

A swapchain texture belongs to the command buffer that acquired it. Mid-frame readback needs a submitted, fenced command buffer; submitting the frame's would present. So:

- `beginFrame()` — a live frame → `frameOpen`, never overwritten. Acquire frame cb + swapchain texture (`SDLWaitAndAcquireGPUSwapchainTexture`). Acquire throws or texture 0 → cancel cb (answer false on 0). Offscreen target (swapchain format, COLOR_TARGET | SAMPLER) follows swapchain size here; failure after acquire → submit cb, never cancel.
- `draw()` / `drawIndexed()` only record a `RecordedDraw` (primitive, bytes, packed projection, SDL texture or placeholder, instances, viewport, scissor). **Frame cb records nothing until `endFrame()`.**
- `endFrame()` — flush on frame cb → blit offscreen → swapchain (NEAREST, DONT_CARE) → submit. Submit, frame-state drop and deferred releases run in `finally`, even when the flush throws; a failed submit → `submitFailed` after that.
- `readPixels()` — own cb: flush pending draws, download offscreen, submit + fence, wait, map. Later `endFrame()` flush LOADs on top. BGRA formats swizzled to RGBA; top-left first. Failure before the flush lands → cancel cb (draws stay recorded); after → submit cb (flushed draws must land).

# Flush

- All recorded geometry → one upload transfer buffer (vertices, then uint16 indices padded to 4) → vertex / index GPU buffers, cycled so in-flight frames keep their data. Buffers grow to a power of two ≥ 4096; old ones released (deferred by SDL).
- Pipelines looked up (built on first use, can throw) before any recording; nothing inside the render pass throws.
- One render pass on the offscreen target: CLEAR on the frame's first flush, LOAD after. Per draw: viewport, scissor clamped to target (clamped to nothing → draw skipped), pipeline, projection pushed as vertex uniform slot 0, vertex buffer at its offset, texture + sampler, draw.
- Not recorded: zero vertices, or an indexed draw with zero indices (a one-point strip expands to none).
- `texture()` uploads on a one-shot cb, submitted at once: ahead of the frame by submission order.
- `releaseTexture()` kills the handle now. Outside a frame → `SDLReleaseGPUTexture` now. Mid-frame a recorded draw may still bind it → queued; queue runs after the frame's submit (`endFrame()`, or `release()` for a mid-frame teardown). `deferredReleases()` counts the queue.

# Topology

Pipelines bake the primitive. TRIANGLES → TRIANGLELIST, TRIANGLE_STRIP → TRIANGLESTRIP, POINTS → POINTLIST, LINES and LINE_STRIP → LINELIST (strip expanded to pairs, vertices or indices). No fans.

# Shaders

- Metal backend: MSL source (`src/Shaders/painter.msl.php`), both stages from one source, entrypoints `painter_vertex` / `painter_fragment`. Uniforms `[[buffer(0)]]`; SDL binds vertex buffer 0 at `[[buffer(14)]]`, read via `[[stage_in]]`; fragment `[[texture(0)]]` / `[[sampler(0)]]`; `[[point_size]]` required for POINTLIST.
- Other backends: committed SPIR-V, compiled on the Mac. Vertex uniforms set 1; fragment samplers set 2. See `src/Shaders/README.md`.
- Clip space y-up: Surface's orthographic lands y = 0 on the top row with no flip — proven on Metal and on Vulkan (Pi V3D, Mesa V3DV) through SDL_GPU's Vulkan backend.

# Capabilities

blending true (colour SRC_ALPHA / ONE_MINUS_SRC_ALPHA, alpha ONE / ONE_MINUS_SRC_ALPHA), depth false, instancing true, readback true, max_texture_size 8192.

# Enums

Createinfo values are jovian/sdl3's generated `SDLGPU*` families; bitmasks (`SDLGPUShaderFormat`, `SDLGPUTextureUsageFlags`, `SDLGPUBufferUsageFlags`) pass `->value`, OR'd as ints.[^enums] No local GPU enums. Array keys mirror the C field names.[^zep]

# Errors

ext creators throw `RuntimeException`. Boot, `beginFrame()`, `texture()`, `readPixels()`, `endFrame()` rethrow as `Sdl3DrawingException` (a `DrawingException` passes through). `readPixels()` / `endFrame()` / `commandBuffer()` / draws outside a frame → `outOfFrame` (a `DrawingException`, the contract's); `beginFrame()` on a live frame → `frameOpen`; anything after `release()` → `released`.

# Release

`release()`: open frame submitted (acquired swapchain texture: submit, never cancel) → wait idle → deferred releases → textures, offscreen, buffers, transfer buffers → `SDLReleaseWindowFromGPUDevice`. Then the stage destroys the window.
