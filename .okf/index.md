---
okf_version: "0.2"
---

# jovian/venusian-sdl3 — knowledge bundle

SDL3 composition for Venusian Surface. `jovian/sdl3` projects `ext-sdl3`; this package owns the `sdl3` stage host (`stage.sdl3`) and the `sdl3` GPU engine (`gpu.sdl3`).

Read this first. Concepts are `status: draft` until a human verifies them.

# Concepts

* [session.md](/session.md) - the stage host: lazy SDL_Init, own pump, window-id routing, event freeing, the four lends and their flags, close order
* [gpu-engine.md](/gpu-engine.md) - the SDL_GPU engine: device + window claim, offscreen target + blit, recorded draws, readPixels on its own command buffer, shaders and binding layouts

# Related bundles

* jovian/sdl3 — `../sdl3/.okf/index.md` (sibling repo): the typed projection this package composes
* venusian/surface — `../../venusian/surface/.okf/index.md` (sibling repo): Stage contracts and abstracts this package fills
