<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Sdl3Device;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceKind;
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

/** The one SDL stager for the process, through a container like the framework's. */
function stager(): \Jovian\Engines\Sdl3\Bridge\Sdl3BridgeDriver
{
    static $driver = null;

    return $driver ??= new \Jovian\Engines\Sdl3\Bridge\Sdl3BridgeDriver(new \Voyager\Vessel\ControlPanel());
}

/** Mail the session is holding for a loop, taken out. */
function takeMail(): array
{
    $session = stager()->connect();

    return (function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    })->call($session);
}

/** Pump SDL as the loop's poller does, every 5 ms, until $until() holds or $seconds pass; whether it held. */
function pumpUntil(callable $until, float $seconds): bool
{
    $session = stager()->connect();
    $limit = microtime(true) + $seconds;
    while (! $until()) {
        if (microtime(true) >= $limit) {
            return false;
        }
        $session->pump(5_000_000);
        $session->flushLatest();
    }

    return true;
}

function pumpFor(float $seconds): void
{
    pumpUntil(fn (): bool => false, $seconds);
}

/** Mail of class $class, pumping until one arrives or $seconds pass. */
function mailOf(string $class, float $seconds = 3.0): array
{
    $seen = [];
    pumpUntil(function () use ($class, &$seen): bool {
        foreach (takeMail() as $mail) {
            if ($mail instanceof $class) {
                $seen[] = $mail;
            }
        }

        return $seen !== [];
    }, $seconds);

    return $seen;
}

/** A borrower that keeps what it was asked to present into, with the handles given. */
final class StageBorrower implements \Surface\Contracts\Drawing\SurfaceBorrower
{
    /** @var list<\Surface\Contracts\Drawing\LentSurface> */
    public array $presented = [];

    public \Surface\Contracts\Framebuffers\Framebuffer $frame;

    public function __construct(private readonly array $handles = [])
    {
        $this->frame = new \Surface\Framebuffers\Native\NativeDirtyFramebuffer(\Surface\Contracts\Framebuffers\FormatSpec::rgba8(), 8, 8);
    }

    public function framebuffer(): \Surface\Contracts\Framebuffers\Framebuffer
    {
        return $this->frame;
    }

    public function lendingHandles(): array
    {
        return $this->handles;
    }

    public function presentInto(\Surface\Contracts\Drawing\LentSurface $surface): bool
    {
        $this->presented[] = $surface;

        return true;
    }
}

function sdlWindow(int $width = 64, int $height = 32): SDL_Window
{
    video();

    return SDL_CreateWindow('venusian-sdl3 test', $width, $height, SDL_WINDOW_HIDDEN) ?? throw new RuntimeException('SDL_CreateWindow: '.SDL_GetError());
}

function windowSurface(SDL_Window $window, int $width = 64, int $height = 32): LentSurface
{
    return new LentSurface(SurfaceKind::SDL_WINDOW, ['window' => $window->pointer()], fn (): array => [$width, $height]);
}

/** A frame an hour long: within a test only reads end it. */
function inputFrame(): \Surface\HumanInput\InputFrame
{
    return new \Surface\HumanInput\InputFrame(fn (): int => 3_600_000_000_000);
}

/** A staged window nothing real reaches: hidden and unfocusable. */
function inputWindow(string $name = 'keys'): \Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow
{
    stager()->getStaged($name)?->close();

    return stager()->openStaged($name, 160, 120, ['visible' => false, 'focusable' => false]);
}

/** An sdl3 input engine on the shared stager's session, connected; let go by letGoOfInput(). */
function sdl3Input(?Closure $focus = null): \Jovian\Engines\Sdl3\Input\Sdl3InputEngine
{
    stager()->connect();
    $engine = new \Jovian\Engines\Sdl3\Input\Sdl3InputEngine(inputFrame(), stager(), $focus);
    $GLOBALS['sdl3_input_engines'][] = $engine;

    return $engine->connect();
}

/** The sdl3 pad source, connected, its serials read through $serial (SDL handle, instance id) when given; let go by letGoOfPadSources(). */
function sdl3Pads(?Closure $serial = null): \Jovian\Engines\Sdl3\Input\Sdl3PadSource
{
    $pads = new \Jovian\Engines\Sdl3\Input\Sdl3PadSource(inputFrame(), $serial);
    $GLOBALS['sdl3_pad_sources'][] = $pads;

    return $pads->connect();
}

/** Disconnects every pad source sdl3Pads() made. */
function letGoOfPadSources(): void
{
    foreach ($GLOBALS['sdl3_pad_sources'] ?? [] as $pads) {
        $pads->disconnect();
    }
    $GLOBALS['sdl3_pad_sources'] = [];
}

/** Disconnects every engine sdl3Input() made: their taps leave the shared session. */
function letGoOfInput(): void
{
    foreach ($GLOBALS['sdl3_input_engines'] ?? [] as $engine) {
        $engine->disconnect();
    }
    $GLOBALS['sdl3_input_engines'] = [];
}

/** Pushes the events, pumps the session once as the loop's poller does, and polls the engine. */
function deliver(\Jovian\Engines\Sdl3\Input\Sdl3InputEngine $engine, SDL_Event ...$events): void
{
    $session = stager()->connect();
    $session->pump(0);
    foreach ($events as $event) {
        SDL_PushEvent($event) || throw new RuntimeException('SDL_PushEvent: '.SDL_GetError());
    }
    $session->pump(0);
    $engine->poll();
}

function keyEvent(\Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow $window, int $scancode, bool $down, int $mod = SDL_KMOD_NONE, bool $repeat = false): SDL_Event
{
    $event = new SDL_Event();
    $event->type = $down ? SDL_EVENT_KEY_DOWN : SDL_EVENT_KEY_UP;
    $event->key->windowID = SDL_GetWindowID($window->native());
    $event->key->scancode = $scancode;
    $event->key->down = $down;
    $event->key->mod = $mod;
    $event->key->repeat = $repeat;

    return $event;
}

function textEvent(\Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow $window, string $text): SDL_Event
{
    $event = new SDL_Event();
    $event->type = SDL_EVENT_TEXT_INPUT;
    $event->text->windowID = SDL_GetWindowID($window->native());
    $event->text->text = $text;

    return $event;
}

function motionEvent(\Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow $window, float $x, float $y, float $dx, float $dy): SDL_Event
{
    $event = new SDL_Event();
    $event->type = SDL_EVENT_MOUSE_MOTION;
    $event->motion->windowID = SDL_GetWindowID($window->native());
    $event->motion->x = $x;
    $event->motion->y = $y;
    $event->motion->xrel = $dx;
    $event->motion->yrel = $dy;

    return $event;
}

function buttonEvent(\Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow $window, int $button, bool $down, float $x = 0.0, float $y = 0.0): SDL_Event
{
    $event = new SDL_Event();
    $event->type = $down ? SDL_EVENT_MOUSE_BUTTON_DOWN : SDL_EVENT_MOUSE_BUTTON_UP;
    $event->button->windowID = SDL_GetWindowID($window->native());
    $event->button->button = $button;
    $event->button->down = $down;
    $event->button->x = $x;
    $event->button->y = $y;

    return $event;
}

function wheelEvent(\Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow $window, float $dx, float $dy, int $direction = SDL_MOUSEWHEEL_NORMAL): SDL_Event
{
    $event = new SDL_Event();
    $event->type = SDL_EVENT_MOUSE_WHEEL;
    $event->wheel->windowID = SDL_GetWindowID($window->native());
    $event->wheel->x = $dx;
    $event->wheel->y = $dy;
    $event->wheel->direction = $direction;

    return $event;
}

/** A window event of $type for $window: focus lost, pointer left. */
function windowSignal(\Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow $window, int $type): SDL_Event
{
    $event = new SDL_Event();
    $event->type = $type;
    $event->window->windowID = SDL_GetWindowID($window->native());

    return $event;
}

/** A virtual device on SDL's joystick subsystem, held up by the test's own reference for the process: the id, detached by letGoOfPads(). */
function virtualPad(string $name, int $type = SDL_JOYSTICK_TYPE_GAMEPAD, int $axes = 6, int $buttons = 15, int $hats = 0): int
{
    static $up = false;
    if (! $up) {
        SDL_InitSubSystem(SDL_INIT_GAMEPAD) || throw new RuntimeException('SDL_InitSubSystem: '.SDL_GetError());
        $up = true;
    }
    $desc = new SDL_VirtualJoystickDesc();
    $desc->type = $type;
    $desc->naxes = $axes;
    $desc->nbuttons = $buttons;
    $desc->nhats = $hats;
    $desc->name = $name;
    $id = SDL_AttachVirtualJoystick($desc);
    $id > 0 || throw new RuntimeException('SDL_AttachVirtualJoystick: '.SDL_GetError());
    $GLOBALS['sdl3_virtual_pads'][] = $id;

    return $id;
}

function letGoOfPads(): void
{
    foreach ($GLOBALS['sdl3_virtual_pads'] ?? [] as $id) {
        SDL_DetachVirtualJoystick($id);
    }
    $GLOBALS['sdl3_virtual_pads'] = [];
}
