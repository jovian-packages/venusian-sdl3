# jovian/venusian-sdl3

The `sdl3` rendering engine for Venusian Surface, and the `sdl3` stager: staged windows on SDL 3. The engine is SDL_GPU through ext-sdl3: Metal on the Mac, Vulkan on the Pi. `driver()` says which.

## Where it sits

```
DrawingManager → GpuRenderingEngine (Surface) → Sdl3Device (this package) → ext-sdl3 → SDL_GPU → Metal | Vulkan
```

The engine's framebuffer is an `Sdl3Framebuffer`, a Surface `GLFramebuffer`.

## Requirements

- macOS or Linux with SDL 3.2+ and ext-sdl3 ^0.11. PHP 8.4, `venusian-surface/drawing` ^0.11.
- SDL video: the provider's creator brings SDL's video subsystem up (`SDL_InitSubSystem`, reference-counted) before the device; it never quits it, since the process may hold SDL windows of its own. On macOS with ext-appkit it first makes sure the application exists and has a delegate (an empty trampoline when it has none): otherwise SDL makes its own application, with a Dock icon and menus, and makes itself the delegate. Since SDL's delegate is not there to do it, the session makes the process a regular app and brings it to the front while connected (and a focusable staged window brings it to the front when shown): a process started from a terminal otherwise shows windows that take clicks but never keys. An `Sdl3Device` built by hand needs video up first; without it the refusal is a `DrawingException` saying so.
- On the Pi, the Wayland session (`WAYLAND_DISPLAY`, `XDG_RUNTIME_DIR`). No headless run there.

## Install

```
composer require jovian/venusian-sdl3
```

Provider discovered through `extra.venusian.providers`. It registers `sdl3` on `app('drawing')`, and, with Surface's bridge bound, the `sdl3` stager on `app('toolkit-bridge')`. With HumanInput bound too, it registers the `sdl3` input engine on `app('human-input')`.

## Usage

Offscreen. Run as a script; printed pixels are its output (Apple M1 Pro).

```php
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\NutsAndBolts\Color;

$engine = app('drawing')->renderer('sdl3', ['width' => 320, 'height' => 240]);
$engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(16, 24, 32))->fillEllipse(160, 120, 60, 40, Color::rgb(255, 128, 0)));
$rgba = $engine->framebuffer()->flush(FormatSpec::rgba8());

echo $engine->device()->driver(), "\n";                        // metal
echo bin2hex(substr($rgba, (120 * 320 + 160) * 4, 4)), "\n";   // ff8000ff  ellipse centre
echo bin2hex(substr($rgba, 0, 4)), "\n";                       // 101820ff  corner
```

On a display. The display sends what changed, in its own format.

```php
$display = app('displays')->panel('st7796', direct: true);
$engine = app('drawing')->renderer('sdl3', ['output' => $display]);
```

On a canvas.

```php
$engine = app('drawing')->renderer('sdl3', ['output' => $canvas]);
```

Needs a toolkit canvas that lends an SDL window. A window that lends none refuses: `This window cannot host 'sdl3': it lends … It can host: …`.

## Staged windows

`app('staged-windows')->open('game', 1280, 720, ['toolkit' => 'sdl3', …])` makes an `Sdl3StagedWindow`. Its backend names SDL's video driver: `sdl3/cocoa`, `sdl3/wayland`, `sdl3/x11`. The session never sleeps natively: the loop polls SDL at its pace and SDL's window events become Surface mail; display events become one `DisplaysChanged` per pump.

```php
$window = app('staged-windows')->open('game', 640, 400, ['toolkit' => 'sdl3', 'mode' => WindowMode::Fullscreen]);
$engine = app('drawing')->renderer('sdl3', ['output' => $window]);   // the engine draws into the window itself
```

* Presenting on the CPU: the frame is blitted into the window surface at `presentRect()` with the scaling filter (nearest or linear), and only the damaged rects go out (`SDL_UpdateWindowSurfaceRects`); a whole frame clears the bars first. Vsync is the window surface's: `On` 1, `Adaptive` adaptive, `Off` and `Mailbox` none.
* Modes: `Fullscreen` is SDL's desktop fullscreen (on macOS its own Space by default), on a given display: moved there first, or on Wayland through that display's desktop mode. `Exclusive` is SDL's fullscreen mode; Wayland scales the window to it instead of switching the display.
* Capabilities: opacity, icon, keep-awake (SDL's screen saver switch, off while any window asks), attention (flash until focused), exclusive fullscreen, hit tests, and, except on Wayland, position, always-on-top and focusable after open. No frame clock.
* Lending: the window itself (`SDL_WINDOW`, the `sdl3` engine), a Metal view's layer on macOS with ext-metal, a `VkSurfaceKHR` against the borrower's `instance` with ext-vulkan, an SDL GL context with ext-opengl (CGL through ext-appkit on macOS; EGL elsewhere, with `SDL_HINT_VIDEO_FORCE_EGL` on X11), whose present this window drives: current, the borrower's copy, swap. X11 and Wayland take OpenGL or Vulkan only at creation, so lending either remakes the native window with it and carries its state over. After `reclaim()` the window surface comes back on the next present, once the engine's device has let go of the window.

## Human input

Two HumanInput drivers. The `sdl3` input engine: keys, text, the pointer and the wheel of staged SDL windows; HumanInput starts it when the `sdl3` session connects. The `sdl3` pad source: SDL's pads, with or without a window, when `human-input.pads.<os>` is `'sdl3'` (on macOS it reads through IOKit HID, so a script with no window reads pads on every macOS version). Read through HumanInput, in a sketch's loop:

```php
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\Contracts\HumanInput\Key;
use Surface\HumanInput\MagicAliases\HumanInput;

$keys = HumanInput::keyboard();
if ($keys->isPressed(Key::SPACE)) { $this->jump(); }
$this->name .= $keys->text();

foreach (HumanInput::gameControllers() as $pad) {           // 'sdl3-<id>', a DualSense reads as "PS5 Controller" on the Pi
    $this->x += 2 * $pad->leftStick()['x'];
}
```

* Keys are SDL scancodes, so positions, not letters (`ScancodeMap`). Repeats change nothing. Text comes from `SDL_EVENT_TEXT_INPUT`: the engine starts text input on every window of the session, and stops it at disconnect.
* Mouse buttons 1 to 5 are left, middle, right, X1, X2. A flipped ("natural") wheel is turned round: `dy > 0` is always rolled away from the user. The pointer leaving its window sets `window()` to null and keeps the last position.
* Focus: a window losing focus to another of the session's windows keeps keys and buttons down; losing it to anything else releases them all.
* Pads (`Sdl3PadSource`): SDL gamepads are `sdl3-<id>` game controllers with every button and axis. Joysticks SDL has no mapping for are `sdl3-js-<id>` under a default map (`JoystickMap`): axes left x, left y, right x, right y, left trigger, right trigger; buttons south, east, west, north, left and right shoulder, back, start; hat 0 as the dpad. One with a left or right x axis is a game controller, otherwise a game pad.
* `hardwareId()` is the pad's serial in one spelling (a DualSense reads `4c:b9:9b:49:be:4a` on the Mac and the Pi alike): SDL's, or on Linux the input node's `uniq` when SDL reads the pad through evdev and has none; null when neither exists. `setPlayerIndex($i)` sets SDL's player index, which lights the pad's player LEDs where SDL can reach them.
* One pad, one device: SDL on macOS lists a hot-plugged DualSense through two drivers, sometimes dropping one later. Devices with one serial are one device; when SDL drops the one listed, the other takes over the same device, id and state, with no mail.
* Process-wide while the pad source is connected: SDL's joystick and gamepad events (0x600 up to 0x700) are off, since every `SDL_UpdateGamepads()` otherwise queues one nobody drains; they come back on at disconnect. `SDL_HINT_JOYSTICK_ALLOW_BACKGROUND_EVENTS` is `1`, so pads report while no SDL window has focus.
* By hand, without the loop: `new Sdl3InputEngine($frame, app('toolkit-bridge')->driver('sdl3'))`, then `connect()` once the session is connected (it throws otherwise), and `poll()` once a frame after the session's pump. Pads: `(new Sdl3PadSource($frame))->connect()`, `poll()` once a frame.

## Presenting and HDR

Present = show pass: target → `presentRect()`, window's filter, black bars, encoded for swapchain. Vsync VSYNC / IMMEDIATE / MAILBOX, no adaptive; under VSYNC off Metal, nothing presented while window hidden/occluded/minimised (Metal never stalls). `frames_in_flight` 1-3. No present times (SDL_GPU has none). Window claim given back with the surface.

HDR where window has the composition: rgba16float extended-linear sRGB / P3 through `HDR_EXTENDED_LINEAR`, rgb10a2 HDR10 through `HDR10_ST2084`. No P3 SDR, no HDR metadata: SDL_GPU has neither. Stager: GL borrower asking `'native'` gets the native window (opengl engine's own HDR surface).

## What it draws

| Operation | Draws |
|---|---|
| CLEAR | the colour over the whole target, no blend |
| SCISSOR | clip rectangle for what follows |
| SOLID | flat-colour triangles, no blend |
| STENCIL_FILL + COVER | a path: triangle lists into the stencil (winding or even-odd), then a cover quad blended where the stencil is non-zero, zeroing it |
| ELLIPSE, RING | coverage of the outer (and inner) ellipse in the fragment shader, folded into alpha as Velvet folds it |
| UPLOAD + IMAGE | a framebuffer as a texture, fetched texel by texel at the inverse placement of the pixel centre, nearest or Velvet's 256ths bilinear |
| RECTS | rectangles blended at the colour's alpha |

Edges: four samples resolved (anti-aliased) or one (hard).

## Blending

Fixed-function source-over: colour `SRC_ALPHA, ONE_MINUS_SRC_ALPHA`, alpha `ONE, ONE_MINUS_SRC_ALPHA`. The shader hands the blender Velvet's alpha: `(α·coverage + 127) / 255` for shapes, `(sₐ·opacity + 127) / 255` for images. Measured: Velvet's bytes on both backends. `GpuParity` 21/21 on Apple M1 Pro (Metal) and on the Pi 5 (V3DV Vulkan), no tolerance; translucent fills at α 1, 127, 128, 254, 255 match byte for byte with one sample and four.

## Shaders

`resources/shaders/`: `venusian.vert` and `venusian.frag` in GLSL 450, with `venusian.vert.spv` and `venusian.frag.spv` compiled by `glslangValidator -V -g` (run in that directory) and committed beside them; `-g` embeds the GLSL text, and `ShadersTest` fails when a `.spv` no longer carries its source; `venusian.metal` is the same program in MSL. `shader()` loads SPIR-V where the device takes it, MSL otherwise. A change to a `.vert` or `.frag` is recompiled and committed with its `.spv`.

Binding sets (SDL's): vertex uniforms `set = 1`, fragment sampled texture `set = 2`, fragment uniforms `set = 3`. MSL: `[[stage_in]]` vertex input, uniforms at `[[buffer(0)]]`, texture and sampler at index 0.

| Block | Slot | Bytes | Layout |
|---|---|---|---|
| `Frame { vec4 size; }` | vertex 0 | 16 | `pack('g4', $w, $h, 0.0, 0.0)` |
| `Paint { uvec4 mode_rgba[2]; vec4 shape; vec4 band; vec4 abcd; vec4 efwh; }` | fragment 0 | 96 | `V4` (mode, opacity, linear, 0) + `V4` (r, g, b, a) + `g16` |

Modes: SOLID 0, FILL 1, OVAL 2, IMAGE 3, COPY 4. `shape` = cx, cy, rx, ry; `band` = stroke, hole, samples; `abcd`, `efwh` = the inverse placement and the source size.

## Reference

- `Sdl3Device`: `__construct(?SDL_GPUDevice)`, `gpu()`, `driver()`, `stencilFormat()`, `shader()`, `target()`, `draw()`, `surfaces()`, `handles()`, `adopt()`, `present()`, `release()`, `read()`, `write()`, `finish()`.
- `Sdl3Framebuffer`: `texture()`, the `GLFramebuffer` methods.
- `VenusianSdl3ServiceProvider::extend(DrawingManager)`.
- `VenusianSdl3ServiceProvider::input(HumanInputManager, ToolkitManager)`: registers the `sdl3` input engine.
- `Sdl3InputEngine`: `connect()`, `disconnect()`, `connected()`, `see()`, `settle()`, `poll()`, `keyboard()`, `mouse()`. `Sdl3PadSource` (`PadSource`): `connect()`, `disconnect()`, `connected()`, `settle()`, `poll()`, `gamePads()`, `gameControllers()`. `ScancodeMap::key()`, `JoystickMap`.

## Behaviour

- Readback and upload each submit their own command buffer and wait for it.
- `draw()` and `present()` never wait. `finish()` waits for every command buffer out. A fence is let go only after a later submit has seen it signal: SDL cleans a command buffer (frees what it used, runs pending releases) in the first submit that finds its fence complete, and a fence handed back sooner is reused and reset first.
- Every pass binds a sampler, a 1 × 1 texture when the draw samples nothing.
- The stencil format is the device's: `D24_UNORM_S8_UINT` where supported (the Pi), `D32_FLOAT_S8_UINT` otherwise (the Mac).
- A pixel call between frames costs a readback and an upload and, with anti-aliased edges, a restore pass before the next frame.
- `adopt()` claims the window and takes MAILBOX where the window does (Wayland), VSYNC otherwise (Metal). FIFO on Wayland waits for the compositor, forever for a window it is not showing. Metal's VSYNC acquire waits in `nextDrawable`: one refresh (8 ms at 120 Hz) measured on hidden and shown windows.
- `present()` blits the target into the next swapchain texture; with none ready, or the window gone from the device, it cancels the command buffer and answers false. A released surface answers false.
- `release()` waits for the GPU, then lets go of everything it made, the resolved textures of framebuffers still held included; it runs once, and a later read, upload or `target()` throws `sdl3: this device was released.`. An engine that made its device destroys it; a device handed in is the caller's.
- Reads and uploads are bounded by the target, and an upload's bytes must fill its region exactly; otherwise `DrawingException`.
- Textures stop at 16384 × 16384 (`Sdl3Device::LARGEST`), SDL_GPU's 2D bound: a larger target or image source throws `DrawingException`. Metal aborts the process past it rather than answering null.
- A frame that fails while encoding cancels its command buffer and frees its buffers; the next frame draws.
- A re-made target is a new `Sdl3Framebuffer`; the old one keeps its own texture.

## Measured

320 × 240, 100 ellipses and a line of text, anti-aliased, peak memory 4.0 MB:

| Machine | Whole redraw | Partial frames |
|---|---|---|
| Apple M1 Pro, Metal | 1.38 ms | 4.91 ms |
| Raspberry Pi 5, V3DV Vulkan | 1.25 ms | 2.51 ms |

ST7796 480 × 320 on the Pi's SPI at 10 MHz through `DirectEDisplay`, a moving dot, a frame and a label, peak 14.0 MB:

| Engine | Whole frame: draw + present | Partial frame: draw + present | Rate |
|---|---|---|---|
| velvet | 3.8 + 246.7 ms | 0.6 + 15.6 ms | 61.8 fps |
| sdl3 | 1.9 + 250.8 ms | 0.7 + 16.5 ms | 58.3 fps |

## Testing

Real GPU only. `tests/Pest.php` requires Surface's `GpuParity` from the sibling checkout; set `SURFACE_TESTS` to a Surface checkout's `tests` directory to move it. Install with a temporary path repository to `<surface>/src/Surface/*`, run `php -d memory_limit=128M vendor/bin/pest` on `php84`, ZTS and the Pi. Runbook: [.okf/runbooks/testing.md](.okf/runbooks/testing.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT.
