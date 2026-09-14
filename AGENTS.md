# Agent guidelines — jovian/venusian-sdl3

## Knowledge Bundle (OKF)

This package ships an Open Knowledge Format bundle at [`.okf/`](.okf/) (excluded from the Composer dist). Read [`.okf/index.md`](.okf/index.md) first; update the affected concept and append [`.okf/log.md`](.okf/log.md) when you learn something durable; new or changed concepts stay `status: draft`.

## Where this package sits

`ext-sdl3` (1:1 binding) → `jovian/sdl3` (typed projection) → **`jovian/venusian-sdl3`** (composition: the `sdl3` stage host and the `sdl3` GPU engine) → `venusian/surface`.

## Rules

- The provider binds `stage.sdl3` (`SdlStageSession`) and `gpu.sdl3` (`Sdl3GpuEngine`). Do not rename them.
- Never import an AppKit, Metal, Vulkan, OpenGL or GTK symbol. What SDL lends another engine (a CAMetalLayer, a VkSurfaceKHR, a GL context) crosses as pointer bits or a Surface contract through `GPUHost`.
- Every `SDLReadEvent` reader frees its event. Never call `SDLFreeEvent` after `SDLReadEvent`.
- ext-sdl3 creators throw `RuntimeException`. Catch at this package's boundary and rethrow `Sdl3StageException` / `Sdl3DrawingException`.
- The SDL_GPU frame command buffer records nothing until `endFrame()` (offscreen target + blit; see `.okf`).
- `PHP_OS_FAMILY` may be read here (composition chooses); never in `jovian/sdl3`.

## Verification

`vendor/bin/pest` — Feature suites skip without ext-sdl3 or a video device; run them on a GUI session (Mac) and the Pi seat. A skipped test is not evidence.
