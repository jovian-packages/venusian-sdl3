# Log

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
