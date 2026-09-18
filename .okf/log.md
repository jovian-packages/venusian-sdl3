# Update Log

## 2026-09-17 (CPU stages)
* **Update**: [session](/session.md) — `mintCPUStage` mints `SdlCPUStagedWindow` (software renderer from `STAGE_CPU_RENDERER` / `stage.cpu_renderer`, streaming texture, `Fits` / `Pixels::rgba32`, `NEAREST`). `RoutableStage` is the pump's type. Host table gains the CPU row. Close order: texture → renderer → window.
* **Update**: [index](/index.md) — opening names CPU stages.
* **Tests**: Mac 67 passed (2 Linux-only skips) including both CPU feature tests; Pi fnk0107 67 passed (2 macOS-only skips) on `DISPLAY=:0` `SDL_VIDEODRIVER=x11` (this SDL build has no Wayland driver). Eyeball: 128×64 mono at 4× and 320×200 `nframes` in 960×600, `INTEGER_SCALE` + `NEAREST`, canvas size unchanged on resize.

## 2026-09-17
* **Fix**: `Sdl3InputEngine` wheel negates x/y when `direction` = SDL_MOUSEWHEEL_FLIPPED (1).
* **Fix**: `Mouse::window()` set at the end of each `poll()` from `SDL_GetMouseFocus()` → stage name, null for no focus / no stage; x/y kept. Constructor takes `mouse_focus` Closure.
* **Fix**: `disconnect()` calls `SDL_StopTextInput` on every still-open window text input was started on. Constructor takes `stop_text` Closure.
* **Fix**: `SdlStageSession::windowHandles()` returns open stages only, as documented (a closed stage stays in the map until the next pump; its handle is destroyed).
* **Concept**: `input-engine.md` — wheel sign, mouse window semantics, focus loss (SDL keyboard reset), scancode 83 = `NUM_LOCK`, settle across polls, stop text input.
* **Correction**: the Task 7 entry below says +19 tests; it was +18 (`ScancodeMapTest` 11, `InputEngineTest` 7; the provider alias went into an existing test).
* **Tests**: +6 (`InputEngineTest`: flipped wheel, no focus, focus on a non-stage, poll-level settle; `Feature/InputWindowsTest`: focused stage name then null, stop text input on open windows only) → 59 Pest (2 Linux-only skips) on the Mac.

## 2026-09-17
* **Add**: `input.sdl3` — `Sdl3InputEngine` (`src/Input/`), `ScancodeMap`, `ReadsGamepads` + `SdlGamepadReader`, `Sdl3InputException`. Keys/text/mouse from the shared `SdlEventPump`; gamepads polled, rescanned on add/remove; text input started per stage window; never `SDL_Quit`. Requires `surface/human-input` (resolved through `venusian/surface`'s replace map).
* **Concept**: `input-engine.md`; `session.md` pump paragraph names the input engine; index line.
* **Tests**: +19 (`ScancodeMapTest` 11, `InputEngineTest` 7, provider alias `input.sdl3`) → 53 Pest (2 Linux-only skips) on the Mac.

* **Add**: `SdlEventPump` (`src/Events/SdlEventPump.php`) — one owner of SDL's event queue. Routes QUIT/WINDOW_* to a registered router; decodes key/text/motion/button/wheel/gamepads for the sdl3 input engine (Task 7) into a buffer when `wantInput(true)`, else frees unread; a second `drain()` in one tick is a no-op.
* **Change**: `SdlStageSession` takes an `SdlEventPump` (constructor default `new SdlEventPump()`); `pumpEngine()` delegates to `$pump->drain()`; `connectToEngine()` registers `route(...)` as the window router; `route()` no longer frees non-window events itself (the pump does). Adds `windowName(int): ?string` and `windowHandles(): array<int, int>` for the input engine to name/reach windows.
* **Change**: provider binds `SdlEventPump::class` as a singleton and builds `SdlStageSession` from it.
* **Concept**: `session.md`, index line for it.
* **Tests**: +7 (`EventPumpTest`: 6; `SessionTest`: shared pump / no window yet, provider binds `SdlEventPump`) → 35 Pest (33 pass, 2 Linux-only skips, 113 assertions) on the Mac. `Feature/StageTest` ran un-skipped (ext-sdl3 loaded), proving window routing survived the refactor.

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

## 2026-09-17
* **Update**: [session](/session.md) — owns the native pump on macOS; `SdlEventPump::drain($wait_ms)` waits for the first event with `SDLWaitEventTimeout` when handed the idle wait.
