# Log

## 2026-11-08

* G3 reuse (Surface drawing-path spec): the vertex buffer and its transfer buffer are kept across frames and written with cycle on (SDL keeps what a frame in flight reads), staging buffers are kept by size class, and source textures are pooled by format and size (8 a size, 24 sizes). Parity: all 63 Surface scenes pass on SDL_GPU's Metal backend and with `SDL_GPU_DRIVER=vulkan`; `Sdl3DeviceTest` draws six frames through the buffers and pool with a growth between. Data and Lists tabs at scale 2 (Metal backend): 41 and 19 ms CPU a frame, 42 and 23 before.

## 2026-11-07

* Glass (Surface drawing-path spec, G4): `Sdl3Device` is `DrawsGlass`. `GLASS_BACKDROP` ends the pass and runs the glass passes (`glass_paint` in `filter.metal`, `glass.frag.spv`: capture, down, up; integer texel reads, ext-fb's levels byte for byte) into pooled textures, one render pass each; the pyramid is kept across frames while Lowering says nothing under it changed. `GLASS` is mode 9 of the main fragment shader (MSL and SPIR-V; the fragment program now declares two samplers, the second the pane's sharp capture, and every draw binds both). Against Velvet+ext on a glass scene: max channel difference 11, 0.15 % of channels over 2, on both SDL_GPU backends (Metal with MSL, Vulkan with SPIR-V through `SDL_GPU_DRIVER=vulkan`); the kept pyramid draws the same. Glass tab, CPU time per frame: 4.6 ms at scale 1, 4.7 ms at scale 2.
* Filter passes FIELD (an image from the pixel position: a rounded box's windowed distance and direction, its inside mask, a ramp) and TABLE (a 256-entry lookup per straight channel), and a displacement whose map is an earlier pass's result (`params[5]`). Parity: the new scenes `layer fields`, `layer tables` and `layer displacement by a made map` pass on this engine (`venusian/surface/scripts/gpu-parity.php`). The SPIR-V (`filter.frag.spv`, rebuilt with glslangValidator -V -g) is not run on Linux yet; the Metal shading path is.

* Layers: the TARGET, BACKDROP, FILTER and LAYER_SOURCE operations. A layer target is a resolved RGBA8 texture, a multisampled one at four samples and a stencil, made on first use and kept until the target changes; a layer's drawing is a render pass of its own on it (cleared on entry; an enclosing target's pass is begun again with its colour loaded), and BACKDROP and FILTER write a target's texture with `SDL_BlitGPUTexture` between passes. A filter chain is Surface's `FilterPlan`: one render pass of `resources/shaders/filter.*` per plan pass (SPIR-V compiled with `glslangValidator -V -g`, SDL_GPU's set 2 samplers and set 3 uniform; MSL in `filter.metal`), over the region, into a texture of its own, four samplers (inputs, the pass's numbers as an RGBA8 texture of float32 bytes, a displacement map) and 64 bytes of uniforms. The 16 new parity tests pass on Homebrew PHP 8.4 (Mac, Metal): the layer, filter, backdrop, noise and displacement scenes are byte for byte Velvet's; the rounded-layer scene differs only at the rounded edge. The SPIR-V path is not run here (no Linux run yet). Layers on an HDR target throw.

## 2026-10-28

* **Version**: 0.11.0 with the Venusian 0.11 ecosystem: `dev-main` → `0.11.x-dev`, its extensions, `venusian-surface/*`, `venusian-voyager/*` and the jovian siblings at `^0.11.0`, PHP `~8.4.27||^8.5.12`, homepage `0.11.x`. No API change. 160 passed on Homebrew PHP 8.4 NTS and ZTS (one ZTS exclusive-fullscreen row is flaky and passes on rerun); 156 passed, 4 skipped on the Pi (8.4 ZTS).

## 2026-10-08

* SDL's pads are the `sdl3` pad source (`Sdl3PadSource`, `extendPads('sdl3', …)`), read with or without a session or video; pads carry SDL's serial as `hardwareId()` and set SDL's player index; the engine lists no pads and taps its session at `connect()` or throws. [input](architecture/input.md)

## 2026-10-08

* HumanInput's `sdl3` input engine: `Sdl3InputEngine`, `Sdl3Pads`, `ScancodeMap`, `JoystickMap`; the session shows events to taps; the provider registers the engine. Requires `venusian-surface/human-input`. [input](architecture/input.md)

## 2026-10-08

* Staged windows phase 6: show-pass presents, vsync, frames in flight, HDR, hidden windows, see [architecture](architecture/engine.md).

## 2026-10-08

* The `sdl3` stager: `Sdl3BridgeDriver`, `Sdl3Session`, `Sdl3StagedWindow`, `Sdl3Displays`; the provider registers it on the bridge. Requires `venusian-surface/bridge` and `windows`. [stager](architecture/stager.md)

## 2026-10-05

* venusian-sdl3 0.10.0: [engine](architecture/engine.md), [testing](runbooks/testing.md).
