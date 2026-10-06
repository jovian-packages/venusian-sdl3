# jovian/venusian-sdl3

The `sdl3` rendering engine for Venusian Surface. SDL_GPU through ext-sdl3: Metal on the Mac, Vulkan on the Pi. `driver()` says which.

## Where it sits

```
DrawingManager → GpuRenderingEngine (Surface) → Sdl3Device (this package) → ext-sdl3 → SDL_GPU → Metal | Vulkan
```

The engine's framebuffer is an `Sdl3Framebuffer`, a Surface `GLFramebuffer`.

## Requirements

- macOS or Linux with SDL 3.2+ and ext-sdl3 ^0.10. PHP 8.4, `venusian-surface/drawing` ^0.10.
- SDL video: the provider's creator brings SDL's video subsystem up (`SDL_InitSubSystem`, reference-counted) before the device; it never quits it, since the process may hold SDL windows of its own. On macOS with ext-appkit it first makes sure the application exists and has a delegate (an empty trampoline when it has none): otherwise SDL makes its own application, with a Dock icon and menus, and makes itself the delegate. An `Sdl3Device` built by hand needs video up first; without it the refusal is a `DrawingException` saying so.
- On the Pi, the Wayland session (`WAYLAND_DISPLAY`, `XDG_RUNTIME_DIR`). No headless run there.

## Install

```
composer require jovian/venusian-sdl3
```

Provider discovered through `extra.venusian.providers`. It registers `sdl3` on `app('drawing')`.

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

Needs a toolkit that lends an SDL window (slice 9b). Until then the refusal reads `This window cannot host 'sdl3': it lends … It can host: …`.

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
