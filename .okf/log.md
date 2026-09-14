# Update Log

## 2026-09-14
* **Fix**: an engine's `attach()` failure in `mintStage()` is rolled back as before, then rethrown as `Sdl3StageException::attachFailed` (host + engine, engine exception as previous); a `StageException` passes through unwrapped. Concept `session.md`.
* **Fix**: `Sdl3GpuEngine::attach()` reads the swapchain format as `SDLGPUTextureFormat`, not any `BackedEnum`.
* **Update**: SDL_GPU clip space y-up proven on Vulkan (Pi V3D, Mesa V3DV) as well as Metal. `gpu-engine.md`, `src/Shaders/README.md`.
* **Tests**: +2 (`StageTest`: wrapped attach failure with rollback, StageException passthrough).

## 2026-09-14
* **Create**: `jovian/venusian-sdl3` 0.8.0, namespace `Jovian\Venusian\Sdl3\`. Path repos `../sdl3`, `../../venusian/surface`.
* **Add**: `stage.sdl3` host (`SdlStageSession`, `SdlStagedWindow`); lenders `SdlGLSurface`, `SdlMetalView`, `SdlVulkanSurface`; `GLProfile` enum; `Sdl3StageException`.
* **Concept**: `session.md`.
* **Tests**: 8 Pest (7 pass, 1 platform skip, 18 assertions) on the Mac; Linux refusal + ES 3.0 path unproven until the Pi seat.
* **Fix**: VULKAN_SURFACE refused on macOS by kind (LAYER through the Metal view there); off-macOS LAYER refusal unchanged.
* **Fix**: mint rollback always destroys the window and rethrows the attach failure; `disconnect()` closes every stage, rethrows the first failure; events for a closed stage skipped.
* **Tests**: +2 (macOS VULKAN_SURFACE refusal, disconnect survives a throwing close); Vulkan lender test Linux only.
* **Add**: `gpu.sdl3` engine — `Sdl3GpuEngine`, `Sdl3GpuContext`, `Sdl3GpuExecutor` (`Contracts\Sdl3Drawing`); `Support\Topologies`, `Support\Pixels`, `Values\RecordedDraw`; painter MSL + committed SPIR-V (`src/Shaders`); `Sdl3DrawingException`. Createinfo values from jovian/sdl3 `SDLGPU*` enums; no local GPU enums.
* **Concept**: `gpu-engine.md`. Index names the GPU engine without plan wording.
* **Tests**: 24 Pest (22 pass, 2 Linux-only skips, 71 assertions) on the Mac, Metal backend. DrawTest proves orientation (no y flip), 0.5-alpha blend, mid-frame readback, stage path, indexed / textured / line-strip / point draws. Vulkan (Pi) unproven.
* **Fix**: `releaseTexture()` mid-frame deferred until after the frame's submit (`endFrame()` / `release()`); `beginFrame()` refuses a live frame (`frameOpen`); `endFrame()` submits and drops frame state in `finally`, throws `submitFailed`; `beginFrame()` / `readPixels()` always cancel or submit their command buffer; pipelines built before the render pass; zero-index draws and empty-scissor draws skipped.
* **Tests**: +4 (outside-frame `DrawingException`, zero-index recording, deferred release on hardware, empty scissor on hardware) → 28 Pest (26 pass, 2 Linux-only skips, 89 assertions) on the Mac, Metal.
