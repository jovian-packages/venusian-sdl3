---
type: Module
title: Stager
description: Sdl3BridgeDriver, Sdl3Session polled at the loop's pace, Sdl3StagedWindow and Sdl3Displays.
resource: src/Windows/Sdl3StagedWindow.php
tags: [sdl3, staged-windows, bridge]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-08T15:00:00Z }
sources:
  - id: window
    resource: src/Windows/Sdl3StagedWindow.php
    title: Sdl3StagedWindow
  - id: session
    resource: src/Bridge/Sdl3Session.php
    title: Sdl3Session
  - id: driver
    resource: src/Bridge/Sdl3BridgeDriver.php
    title: Sdl3BridgeDriver
---

# Overview

`VenusianSdl3ServiceProvider::stage()` registers `sdl3` on the toolkit manager; `boot()` calls it when `toolkit-bridge` is bound. `Sdl3BridgeDriver` stages windows only, one session, one set of windows; it counts windows keeping the display awake (SDL's screen saver switch is process-wide).[^driver]

# Session

`Sdl3Session`: video up at init (reference-counted, never quit; on macOS the application and delegate first). `sleepsNatively()` false: joined as a `ToolkitPoller`, no descriptor. `pump()` waits at most the budget for the first event (`SDL_WaitEventTimeout`), drains the rest, routes window events to the window with that `SDL_WindowID` (`attach` / `detach`), and posts one `DisplaysChanged` for any display events.[^session]

# Window

Backend `sdl3/<video driver>`. Made from properties: title, size, position (x/y or centred on a display), hidden until `stage()`, high pixel density, resizable, borderless, always-on-top, focusable, transparent.

* Present: frame wrapped as an RGBA32 `SDL_Surface` (string or address), blitted scaled into the window surface at `presentRect()`. Whole frame (no damage, new surface, size change): fill black, blit, `SDL_UpdateWindowSurface`. Else per region grown by a pixel: blit, then `SDL_UpdateWindowSurfaceRects`. Surface vsync set when the surface is made. Exposed resets to whole.
* Events: resized, moved, focus gained/lost, occluded, exposed, display and scale changed, close requested; minimize/maximize/restore/fullscreen enter-leave/shown → `syncMode()` from window flags (fullscreen with a fullscreen mode = Exclusive).
* Modes: leaving fullscreen first; restore, maximize, minimize; desktop fullscreen (display: moved, or on Wayland its desktop mode); exclusive at the matching `SDL_DisplayMode` from `Sdl3Displays::nativeMode()`. `SDL_SyncWindow` then `syncMode()`.
* Capabilities: opacity, icon, keep-awake, attention, exclusive fullscreen, hit test; position, always-on-top, focusable except on Wayland (measured).
* Hit test: the closure's `HitArea` mapped to `SDL_HITTEST_*`.

# Lending

`SDL_DestroyWindowSurface` first. `SDL_WINDOW`: the window's address. `METAL_LAYER` (macOS, ext-metal): `SDL_Metal_CreateView`, its layer. `VULKAN_SURFACE` (ext-vulkan): `SDL_Vulkan_CreateSurface` against the borrower's `instance`, destroyed after the borrower let go. `GL_CONTEXT` (ext-opengl; ext-appkit on macOS): GL 4.1 core on macOS, ES 3.1 elsewhere; handles CGLContextObj, or EGL context and display (`SDL_HINT_VIDEO_FORCE_EGL` set first so X11 is EGL too); `presentLent()` makes it current, sets the swap interval from the lent vsync, copies, swaps.

`takeApi()`: macOS turns the window into an OpenGL or Vulkan one itself; X11 and Wayland take the API only at creation, so the window is made again with it (hidden), the old one destroyed and detached, and place, limits, aspect, opacity, icon, hit test, visibility and mode put back.[^window]

# Displays

`Sdl3Displays`: SDL's display ids, primary first; bounds and usable bounds; content scale; current and desktop `DisplayMode` (w, h, pixel density, refresh rate); HDR from the display properties; modes from `SDL_GetFullscreenDisplayModes`.

[^driver]: Sdl3BridgeDriver
[^session]: Sdl3Session
[^window]: Sdl3StagedWindow
