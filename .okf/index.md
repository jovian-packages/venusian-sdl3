---
okf_version: "0.2"
---

# jovian/venusian-sdl3

* [Stager](architecture/stager.md) - Sdl3BridgeDriver, Sdl3Session polled at the pace, Sdl3StagedWindow: window-surface present with damage, modes, displays, capabilities by video driver, four lent surfaces, remaking for GL and Vulkan.
* [Input](architecture/input.md) - Sdl3InputEngine taps the session, copies events, applies on poll; Sdl3PadSource polls SDL gamepads and joysticks with their events off, window or not.
* [Engine](architecture/engine.md) - Sdl3Device: textures, the render pass, five pipelines, the Paint block, fences, restore pass, present, release.

# Runbooks

* [Testing](runbooks/testing.md) - real-GPU Pest suite on the Mac and the Pi, Surface's GpuParity from the sibling checkout, temporary path repository, php84 and zhp.
