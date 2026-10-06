---
type: Runbook
title: Testing
description: Real-GPU Pest suite on the Mac and the Pi with Surface's GpuParity; temporary path repository; php84, zhp, Pi php.
resource: tests/
tags: [sdl3, pest]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-05T12:00:00Z }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
---

# Steps

* The suite runs the real GPU. `tests/Pest.php` fails with a message without ext-sdl3; `video()` brings SDL video up once per process; on the Pi that needs the Wayland session. Nothing skips.
* `tests/Pest.php` requires Surface's `GpuParity` from the sibling checkout (`dev/venusian/surface/tests`); `SURFACE_TESTS` overrides the directory.
* Surface's 0.10 splits are not on Packagist: add a path repository to `<surface>/src/Surface/*` for the run (`composer config repositories.surface '{"type":"path","url":"…/src/Surface/*","options":{"symlink":true}}'`), `composer update`.
* Mac: `php84 -d memory_limit=128M vendor/bin/pest`, then the same on ZTS (`zhp`).
* Pi: copy this package and the Surface checkout without `vendor/`, `.git` and locks (`tar` piped into `fnk`), path repository `../surface/src/Surface/*`, then `WAYLAND_DISPLAY=wayland-0 XDG_RUNTIME_DIR=/run/user/1000 SURFACE_TESTS=<surface>/tests php -d memory_limit=128M vendor/bin/pest`.
* Panel: an app requiring this package, `dept-of-scrapyard-robotics/st77xx` and `microscrap/scrapyard-linux`; `config/circuits/st7796.php` as st77xx's quick start; a script that boots the console kernel, brings video up, and for `velvet` then `sdl3` makes `app('displays')->panel('st7796', direct: true)`, `app('drawing')->renderer($name, ['output' => $display])`, draws one whole frame and 60 partial ones with `present()` after each, and prints the times.
* After: remove `vendor/`, `composer.lock` and the repository entry; `git diff composer.json` is empty at commit time.
