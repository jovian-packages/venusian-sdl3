---
type: Module
title: Engine
description: Sdl3Device - SDL_GPU device, persistent target, one pass per DrawList, five pipelines, fixed-function source-over with Velvet's alpha, fences released once signalled, restore after upload, present into a claimed window.
resource: src/
tags: [sdl3, sdl-gpu, gpu, metal, vulkan, drawing]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-05T12:00:00Z }
sources:
  - id: device
    resource: src/Sdl3Device.php
    title: Sdl3Device
  - id: frag
    resource: resources/shaders/venusian.frag
    title: venusian.frag
  - id: metal
    resource: resources/shaders/venusian.metal
    title: venusian.metal
---

# Overview

`Sdl3Device implements Surface\Drawing\Gpu\GpuDevice` over ext-sdl3's SDL_GPU. `GpuRenderingEngine` lowers drawing calls to a `DrawList`; `draw()` encodes it. `Sdl3Framebuffer extends GLFramebuffer` over the resolved texture. Backend: Metal on macOS, Vulkan on Linux (`driver()`).[^device]

# Device

`__construct(?SDL_GPUDevice)` makes `SDL_CreateGPUDevice(SPIRV | MSL, false, null)` unless handed one; video must be up. `shader()` loads `venusian.{vert,frag}.spv` (entry `main`) where the device takes SPIR-V, `venusian.metal` (entries `place`, `paint`) otherwise; one uniform buffer per stage, one sampler in the fragment stage.

# Textures

| Texture | Format | Samples | Role |
|---|---|---|---|
| resolved | R8G8B8A8_UNORM | 1 | read back, uploaded to, resolved into, blitted to the swapchain |
| multisampled | R8G8B8A8_UNORM | 4 | drawn into; absent with 1 sample |
| stencil | D24_UNORM_S8_UINT, else D32_FLOAT_S8_UINT | 1 or 4 | cleared every pass, left at zero by cover |
| blank | R8G8B8A8_UNORM, 1 × 1 | 1 | bound for every draw that samples nothing |

`target()` accepts 1 or 4 samples, from 1 × 1 to 16384 × 16384 (`LARGEST`, SDL_GPU's 2D bound; image sources too), and clears to transparent black. The resolved texture belongs to its `Sdl3Framebuffer` and is released when that object goes; the device tracks every one still held in `owned`, and `release()` lets go of those. `Sdl3Framebuffer` refuses regions outside the target and uploads whose bytes do not fill their region.

# Pass

Colour: load, store (1 sample) or resolve-and-store into `resolved` (4). Stencil: clear, don't care. A copy pass first uploads the list's vertices plus one whole-target quad (CLEAR, restore) and every image source; then one render pass. Each pass sets the viewport, binds the vertex buffer, pushes `Frame`, binds `blank`.

# Pipelines

One shader pair, mode in the uniforms. RGBA8 colour, the device's stencil format, the target's sample count.

| Pipeline | Blend | Colour write | Stencil | Used by |
|---|---|---|---|---|
| `solid` | off | RGBA | off | CLEAR, SOLID, restore (COPY mode) |
| `fill` | source-over | RGBA | off | RECTS, ELLIPSE, RING, IMAGE |
| `cover` | source-over | RGBA | not-equal 0, pass zero | COVER |
| `winding` | off | none | front increment-wrap, back decrement-wrap | STENCIL_FILL, NON_ZERO |
| `invert` | off | none | invert | STENCIL_FILL, EVEN_ODD |

Source-over: colour `SRC_ALPHA, ONE_MINUS_SRC_ALPHA`, alpha `ONE, ONE_MINUS_SRC_ALPHA`. Stencil draws push a SOLID Paint: the fragment program runs for them too, and an OVAL Paint left from an ellipse would discard outside it.

# Fragment modes

Paint block, 96 bytes: `mode_rgba[0]` = mode, opacity, linear, 0; `mode_rgba[1]` = r, g, b, a; `shape` = cx, cy, rx, ry; `band` = stroke, hole, samples, 0; `abcd`, `efwh` = inverse placement a..f, source width and height.[^frag][^metal]

| Mode | Output |
|---|---|
| SOLID 0, FILL 1 | the colour, straight alpha |
| OVAL 2 | coverage of the outer minus the inner ellipse at the pixel centre: a one-pixel ramp with four samples, in-or-out with one; alpha `(α·coverage + 127) / 255` |
| IMAGE 3 | the texel at the inverse placement of the pixel centre, nearest or Velvet's bilinear (weights `floor(frac·256)`, colours weighed by alpha); alpha `(sₐ·opacity + 127) / 255`; discards outside the source |
| COPY 4 | the texel under the pixel centre |

# Fences

`encode()` acquires a command buffer, fills it, and submits it, cancelling it when filling throws. `submit()` keeps a fence per command buffer in `pending`. Fences found signalled before a submit are released after it. `finish()` waits for all and keeps them. Reason (SDL 3.4 Metal): a submit cleans earlier command buffers whose fence is complete, and resets the fence it takes from the pool; a submitted buffer's completion handler marks its current fence. A fence released before its buffer is cleaned is reset by the next submit: the buffer is never cleaned (its transfer buffers and pending releases stay), or the fence is marked done early for its next holder. `release()` waits for the GPU to go idle, which cleans every buffer, before letting the fences go.

# Restore

An upload into `resolved` with 4 samples sets `stale`. The next `draw()` first runs a pass that draws `resolved` into the multisampled texture in COPY mode, after the frame's uploads and before its pass.

# Present and release

`adopt()` claims the lent window (`handle('window')`, an `SDL_Window` address) and sets MAILBOX, or VSYNC where the window refuses MAILBOX (Metal; its acquire waits in `nextDrawable`, one refresh as measured). `present()` acquires the swapchain texture without waiting; with none, or a failed acquire, it cancels the command buffer and answers false; otherwise it blits `resolved` (nearest) and submits. A released surface answers false; no adopted window throws. `release()` runs once: it finishes, waits for idle, releases the fences, then drops the target, every resolved texture still held, sampler, blank texture, shaders, the window claim, and the device when this object made it. Read, write and `target()` afterwards throw.

[^device]: src/Sdl3Device.php
[^frag]: resources/shaders/venusian.frag
[^metal]: resources/shaders/venusian.metal
