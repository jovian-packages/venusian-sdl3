---
type: Module
title: Input
description: Sdl3InputEngine and Sdl3PadSource, HumanInput's sdl3 engine and sdl3 pad source.
resource: src/Input/Sdl3InputEngine.php
tags: [sdl3, human-input]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-08T23:30:00Z }
sources:
  - id: engine
    resource: src/Input/Sdl3InputEngine.php
    title: Sdl3InputEngine
  - id: pads
    resource: src/Input/Sdl3PadSource.php
    title: Sdl3PadSource
---

# Engine

Tap on `Sdl3Session`: `pump()` shows every event to taps before routing. `see()` copies the event into a `SeenEvent` at once: the session reuses one `SDL_Event`. `poll()` applies copies to a Surface `Keyboard` and `Mouse`; events for windows the session does not hold are dropped. `settle()` clears edges.

Keys by scancode (`ScancodeMap`), repeats ignored. Text input started on each session window as it appears, stopped at disconnect for windows still open. Focus lost: release all only when `SDL_GetKeyboardFocus()` is no session window. Mouse leave: `window()` null, position kept. Flipped wheel negated back.

Connect: taps the driver's session; no connected session → `HumanInputException`. Disconnect: untaps that session. Lists no pads.

Registered by `VenusianSdl3ServiceProvider::input()`: `extend('sdl3', fn (InputFrame) => …)` on the `sdl3` toolkit driver.

# Pads

`Sdl3PadSource`, HumanInput's `sdl3` pad source (`VenusianSdl3ServiceProvider::pads()`: `extendPads('sdl3', …)`), any session or none, no video needed. Connect: background-events hint `1`, `SDL_InitSubSystem(SDL_INIT_GAMEPAD)`, events 0x600..0x6FF off. Poll: `SDL_UpdateGamepads()`, diff `SDL_GetGamepads()` / `SDL_GetJoysticks()` against open handles, read state. Gamepads `sdl3-<id>`, all buttons and axes; unmapped joysticks `sdl3-js-<id>` under `JoystickMap`. Hardware id: `SDL_GetGamepadSerial` / `SDL_GetJoystickSerial`, else on Linux `/sys/class/input/eventN/device/uniq` for an `SDL_Get*Path` of `/dev/input/eventN`, empty → null; a Bluetooth address normalised to lowercase colons. Same serial twice (macOS hot-plug: HIDAPI plus another driver) = one device; the twin stays closed and takes over the device when SDL drops the first. Player lights: `SDL_SetGamepadPlayerIndex` / `SDL_SetJoystickPlayerIndex`, null → −1. Disconnect: close (a handle `SDL_Quit` already released is skipped), events back on, quit the subsystem.

# macOS application

`applicationFirst()` keeps SDL from setting its own delegate, so SDL never makes the process a regular app: without more, a terminal-started process's windows take clicks but never become key, and keys go to the frontmost app. `Sdl3Session` connect → `foreground(true)` (regular policy + `activateIgnoringOtherApps`), counted per session; last disconnect → prohibited. Showing a focusable staged window activates.

# Measured

Joystick events pile up in a queue nobody drains even with SDL's gamepad/joystick event switches off; only per-type `SDL_SetEventEnabled` stops them. With an SDL window open and none focused, pads read nothing without the background hint. Both on SDL 3.2.10 and 3.4.4. Virtual joysticks test pads without hardware. macOS 15.4.1, SDL 3.4.4: a DualSense turned on while the source runs lists as `sdl3-1` (serial `4c-b9-9b-49-be-4a`) and `sdl3-5` (`4C:B9:9B:49:BE:4A`); SDL dropped the first 90 s later. Pi (no hidraw access): serial null, path `/dev/input/event4`, `uniq` the address; player LEDs unreachable (`/dev/hidraw*` and `/sys/class/leds/*` root-only).
