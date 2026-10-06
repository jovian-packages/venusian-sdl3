<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Sdl3Device;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\DrawingManager;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\FramebufferManager;
use Surface\Rasterize\RasterizeManager;

if (! extension_loaded('sdl3')) {
    throw new RuntimeException('venusian-sdl3 tests need ext-sdl3 loaded.');
}

// The parity suite every engine runs lives in the Surface checkout (export-ignored from its package).
$parity = (getenv('SURFACE_TESTS') ?: dirname(__DIR__, 3).'/venusian/surface/tests').'/Support/GpuParity/GpuParity.php';
if (! is_file($parity)) {
    throw new RuntimeException("The GPU parity suite was not found at {$parity}: set SURFACE_TESTS to a Surface checkout's tests directory.");
}
require_once $parity;

/** SDL's video subsystem, up once for the process: SDL_GPU needs it. */
function video(): void
{
    static $up = false;
    if (! $up) {
        SDL_Init(SDL_INIT_VIDEO) || throw new RuntimeException('SDL_Init: '.SDL_GetError().' (on the Pi, run in the Wayland session)');
        register_shutdown_function('SDL_Quit');
        $up = true;
    }
}

/** An sdl3 engine with no output, its own device. */
function sdl3Engine(int $width = 64, int $height = 32, Edges $edges = Edges::ANTIALIASED): GpuRenderingEngine
{
    video();

    return new GpuRenderingEngine(new Sdl3Device, $width, $height, $edges);
}

/** The RGBA8 pixel at ($x, $y) of a tightly packed frame, as hex. */
function pixelOf(string $rgba8, int $width, int $x, int $y): string
{
    return bin2hex(substr($rgba8, ($y * $width + $x) * 4, 4));
}

/** A config repository over an array, as Surface's own suite builds one. */
function sdl3Config(array $items): object
{
    return new class($items)
    {
        public function __construct(private readonly array $items) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }
    };
}

/** A drawing manager without a container: Velvet registered, nothing else. */
function sdl3Drawing(): DrawingManager
{
    $framebuffers = new class extends FramebufferManager
    {
        public function __construct()
        {
            $this->config = sdl3Config([]);
        }
    };
    $rasterize = new class extends RasterizeManager
    {
        public function __construct()
        {
            $this->config = sdl3Config([]);
        }
    };

    return new DrawingManager(sdl3Config([]), $framebuffers, $rasterize);
}

/**
 * The process's memory now, in MB, GPU allocations included: macOS's
 * phys_footprint (private GPU textures count there, not in RSS); on Linux
 * VmRSS plus each DRM client's drm-total-* (V3D's buffers are not in VmRSS).
 */
function residentMb(): float
{
    if (PHP_OS_FAMILY === 'Darwin') {
        preg_match('/phys_footprint:\s+([\d.]+)\s+(KB|MB|GB)/', (string) shell_exec('footprint -p '.getmypid().' 2>/dev/null'), $m)
            || throw new RuntimeException('footprint gave no phys_footprint for this process.');

        return (float) $m[1] * ['KB' => 1 / 1024, 'MB' => 1, 'GB' => 1024][$m[2]];
    }
    preg_match('/^VmRSS:\s+(\d+) kB/m', (string) file_get_contents('/proc/self/status'), $m)
        || throw new RuntimeException('/proc/self/status gave no VmRSS.');
    $kib = (int) $m[1];
    $clients = [];
    // An fd can close between the listing and the read: that entry is skipped, not reported.
    set_error_handler(fn (): bool => true);
    try {
        $infos = array_map(fn (string $fdinfo): string => (string) file_get_contents($fdinfo), glob('/proc/self/fdinfo/*') ?: []);
    } finally {
        restore_error_handler();
    }
    foreach ($infos as $info) {
        if (preg_match('/^drm-client-id:\s+(\d+)/m', $info, $client) && ! isset($clients[$client[1]])) {
            preg_match_all('/^drm-total-\S+:\s+(\d+) KiB/m', $info, $totals);
            $clients[$client[1]] = array_sum(array_map('intval', $totals[1]));
        }
    }

    return ($kib + array_sum($clients)) / 1024;
}
