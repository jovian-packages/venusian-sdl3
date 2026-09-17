---
type: Component
title: The sdl3 input engine
description: >-
  Sdl3InputEngine reads keyboard, mouse and gamepads from SDL3 behind
  Surface's InputEngineDriver, under the input.sdl3 alias.
resource: src/Input/Sdl3InputEngine.php
tags: [sdl3, input, keyboard, mouse, gamepad, engine]
status: draft
generated:
  by: claude-opus-5/claude-code
  at: "2026-09-17T18:00:00Z"
sources:
  - id: human-input
    resource: ../../venusian/surface/.okf/human-input.md
    title: Surface human input — devices, engines, settle/update
  - id: pump
    resource: /session.md
    title: The sdl3 stage host and its SdlEventPump
---

# Overview

Alias `input.sdl3` → `Sdl3InputEngine` (singleton), built from the shared `SdlEventPump` and `SdlStageSession`. Fills `Surface\Contracts\HumanInput\InputEngineDriver`; devices are Surface's `Keyboard`, `Mouse`, `GameController` concretes.[^human-input]

# One queue, two consumers

SDL has one event queue. `SdlEventPump` owns it; stage host and input engine both call `drain()`, first one per tick empties it, second is a no-op.[^pump] `connect()` → `wantInput(true)`: pump decodes key/text/motion/button/wheel into a buffer, and turns GAMEPAD_ADDED/REMOVED into `['kind' => 'gamepads']`. `disconnect()` → `wantInput(false)`, buffer dropped, input events freed unread again.

# Lifecycle

- `connect()`: `SDL_InitSubSystem(GAMEPAD | EVENTS)`; false → `Sdl3InputException::init` (SDL error text). Builds keyboard + mouse, opens every gamepad.
- `disconnect()`: `SDL_StopTextInput(window)` on every still-open window text input was started on (closed stage = destroyed handle, skipped); closes every gamepad handle, devices → null / `[]`. Never `SDL_Quit` — stage host may own video.
- `poll()` (never waits): start text input on new stage windows → `drain()` → settle every device → `apply(takeInput())` → set mouse window from focus → read gamepads.
- Settle: key down in poll N → `isPressed` + `isDown`; poll N+1, no events → `isDown`, not `isPressed`; text cleared.
- SDL calls injected as constructor Closures (defaults = jovian/sdl3 statics): `init`, `start_text`, `stop_text`, `mouse_focus`.
- `apply(array $events)` is the pure half of `poll()` (`@internal`, tests drive it).

# Event-driven: keys, text, mouse

- Scope = windows of the sdl3 stage host only.
- `Mouse::window()`: end of each `poll()`, `SDL_GetMouseFocus()` handle → window id (inverted `windowHandles()`) → stage name. Focus 0 or a window that is no stage → null. x/y kept (last motion/button). Motion/button events also set it from their `window_id` while applying.
- Focus loss: nothing here. SDL resets its keyboard state on focus loss and sends key-ups; they fold in like any key event.
- `key`: repeats skipped. `ScancodeMap::key(scancode)` (USB HID order, layout independent; unmapped → `Key::UNKNOWN`). Scancode 83 = `NUM_LOCK` (SDL `NUMLOCKCLEAR`); the Mac Clear key reports it, so it reads `NUM_LOCK` here (`CLEAR` on appkit). Modifiers from `mod`: shift `0x0003`, ctrl `0x00C0`, alt `0x0300`, meta/gui `0x0C00`.
- `text` → `appendText`. SDL sends TEXT_INPUT only after `SDL_StartTextInput(window)`: engine calls it once per stage window as it appears in `windowHandles()`, forgets closed ids.
- `motion` → position + relative motion. `button` 1 LEFT, 2 MIDDLE, 3 RIGHT, 4 X1, 5 X2, else ignored; also sets position. `wheel` → `addWheel(x, y)`, dy > 0 = up/away. `direction` = `SDL_MouseWheelDirection` (NORMAL 0, FLIPPED 1; no jovian/sdl3 enum, literal in the engine): FLIPPED ("natural" scrolling) → x, y negated first, so the sign never depends on the OS setting.

# Polled: gamepads

- Need no SDL window. All SDL gamepads are `GameController`s (id `sdl3-<instance_id>`, all 15 buttons, all 6 axes); `gamePads()` always `[]`.
- Add/remove events only trigger a rescan (`syncGamepads()`): open new ids (handle 0 skipped), close + drop vanished ones. State never read from events.
- Each poll: `SDL_UpdateGamepads` once, then every button (15-arm enum `match`) and axis (`LEFT_X → LEFTX` …) per pad. Axis = raw / 32767; controller clamps (−32768 → −1, triggers 0…1).
- SDL calls go through `ReadsGamepads` (`SdlGamepadReader` = one `SDLGamepad` call per method) so tests swap in a fake.

[^human-input]: Surface HumanInput contracts and device semantics.
[^pump]: `SdlEventPump` routing rules, in the session concept.
