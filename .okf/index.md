---
okf_version: "0.2"
---

# jovian/venusian-sdl3 — knowledge bundle

SDL3 composition for Venusian Surface. `jovian/sdl3` projects `ext-sdl3`; this package owns the `sdl3` stage host (`stage.sdl3`), the `sdl3` GPU engine (`gpu.sdl3`) and the `sdl3` input engine (`input.sdl3`).

Read this first. Concepts are `status: draft` until a human verifies them.

# Concepts

* [session.md](/session.md) - the stage host: lazy SDL_Init, the shared SdlEventPump (window-id routing, buffered input for the sdl3 input engine), the four lends and their flags, close order
* [input-engine.md](/input-engine.md) - the input engine: keys, text and mouse from the shared pump (stage windows only), gamepads polled with no window, never quits SDL
* [gpu-engine.md](/gpu-engine.md) - the SDL_GPU engine: device + window claim, offscreen target + blit, recorded draws, readPixels on its own command buffer, shaders and binding layouts

# Related bundles

* jovian/sdl3 — `../sdl3/.okf/index.md` (sibling repo): the typed projection this package composes
* venusian/surface — `../../venusian/surface/.okf/index.md` (sibling repo): Stage contracts and abstracts this package fills
