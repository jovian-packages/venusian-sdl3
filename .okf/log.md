# Log

## 2026-10-08

* HumanInput slice 18b: SDL's pads are the `sdl3` pad source (`Sdl3PadSource`, `extendPads('sdl3', …)`), read with or without a session or video; pads carry SDL's serial as `hardwareId()` and set SDL's player index; the engine lists no pads and taps its session at `connect()` or throws. [input](architecture/input.md)

## 2026-10-08

* HumanInput's `sdl3` input engine: `Sdl3InputEngine`, `Sdl3Pads`, `ScancodeMap`, `JoystickMap`; the session shows events to taps; the provider registers the engine. Requires `venusian-surface/human-input`. [input](architecture/input.md)

## 2026-10-08

* Staged windows phase 6: show-pass presents, vsync, frames in flight, HDR, hidden windows, see [architecture](architecture/engine.md).

## 2026-10-08

* The `sdl3` stager: `Sdl3BridgeDriver`, `Sdl3Session`, `Sdl3StagedWindow`, `Sdl3Displays`; the provider registers it on the bridge. Requires `venusian-surface/bridge` and `windows`. [stager](architecture/stager.md)

## 2026-10-05

* venusian-sdl3 0.10.0: [engine](architecture/engine.md), [testing](runbooks/testing.md).
