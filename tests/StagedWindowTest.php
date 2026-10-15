<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Bridge\Sdl3BridgeDriver;
use Jovian\Engines\Sdl3\Providers\VenusianSdl3ServiceProvider;
use Jovian\Engines\Sdl3\Sdl3Device;
use Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow;
use Jovian\Engines\Vulkan\VulkanDevice;
use Surface\Bridge\ToolkitManager;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\DisplayMode;
use Surface\Contracts\Windows\HitArea;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowModeChanged;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Contracts\Windows\ScaleFit;
use Surface\Contracts\Windows\StagedWindow;
use Surface\Contracts\Windows\StagedWindowDriver;
use Surface\Contracts\Windows\WindowCapability;
use Surface\Contracts\Windows\WindowException;
use Surface\Contracts\Windows\WindowMode;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;
use Voyager\Vessel\ControlPanel;

/*
 * Real SDL windows through one stager. Mail is read from the session's
 * outbox; pumpUntil() pumps as the loop's poller does. The backend names the
 * video driver, and what Wayland cannot do is asserted as measured.
 */

function stage(string $name = 'stage', array $options = [], int $width = 320, int $height = 200): Sdl3StagedWindow
{
    stager()->getStaged($name)?->close();
    $window = stager()->openStaged($name, $width, $height, $options);
    pumpFor(0.1);
    takeMail();

    return $window;
}

function wayland(): bool
{
    return SDL_GetCurrentVideoDriver() === 'wayland';
}

/** One SDL window event, as SDL delivers it. */
function windowEvent(Sdl3StagedWindow $window, int $type): SDL_WindowEvent
{
    $event = new SDL_WindowEvent();
    $event->type = $type;
    $event->windowID = SDL_GetWindowID($window->native());

    return $event;
}

afterEach(function (): void {
    stager()->closeAllStaged();
    pumpFor(0.05);
});

it('registers the sdl3 stager on the bridge and stages windows', function (): void {
    $container = new ControlPanel();
    $container->registerInstance('config', sdl3Config([]));
    $toolkits = new ToolkitManager($container);
    VenusianSdl3ServiceProvider::stage($toolkits);
    $window = stage('stage', ['title' => 'Doom']);

    expect($toolkits->driver('sdl3'))->toBeInstanceOf(Sdl3BridgeDriver::class)
        ->and(stager())->toBeInstanceOf(StagedWindowDriver::class)
        ->and($window)->toBeInstanceOf(StagedWindow::class)
        ->and($window->backend())->toBe('sdl3/'.SDL_GetCurrentVideoDriver())
        ->and(SDL_GetWindowTitle($window->native()))->toBe('Doom')
        ->and($window->size())->toBe([320, 200])
        ->and($window->scale())->toBe(SDL_GetWindowPixelDensity($window->native()))
        ->and(SDL_GetWindowFlags($window->native()) & SDL_WINDOW_HIDDEN)->toBe(0)
        ->and($window->supports(WindowCapability::HitTest))->toBeTrue()
        ->and($window->supports(WindowCapability::Position))->toBe(! wayland())
        ->and($window->supports(WindowCapability::AlwaysOnTop))->toBe(! wayland())
        ->and($window->supports(WindowCapability::FrameClock))->toBeFalse()
        ->and(fn () => stager()->openStaged('stage', 1, 1))->toThrow(WindowException::class, "A staged window named 'stage' is already open.");
});

it('pushes only the damaged rects of a dirty framebuffer', function (): void {
    $window = stage('stage', ['vsync' => VSync::Off]);
    $frame = $window->framebuffer();
    [$width, $height] = $window->pixelSize();
    $window->present();
    expect($window->pushed)->toHaveCount(1)
        ->and([$window->pushed[0]->w, $window->pushed[0]->h])->toBe([$width, $height]);

    $frame->setPixel(4, 4, 0xFF0000FF);
    $window->present();

    expect($window->pushed)->toHaveCount(1)
        ->and($window->pushed[0]->w)->toBeLessThan(8)
        ->and($window->pushed[0]->h)->toBeLessThan(8)
        ->and(SDL_GetWindowSurfaceVSync($window->native(), $vsync))->toBeTrue()
        ->and($vsync)->toBe(SDL_WINDOW_SURFACE_VSYNC_DISABLED);
});

it('places a small framebuffer by the scaling fit, the bars black', function (): void {
    $window = stage();
    $window->setScaling(ScaleFilter::Nearest, ScaleFit::Integer);
    $frame = $window->framebuffer('full', 64, 40);
    $frame->setPixel(0, 0, 0xFF0000FF);

    $window->present();

    $surface = SDL_GetWindowSurface($window->native());
    $into = $window->presentRect(64, 40);
    $read = function (int $x, int $y) use ($surface): string {
        $io = SDL_IOFromConstMem($surface->pixels() + $y * $surface->pitch() + $x * 4, 4);
        $bytes = SDL_ReadIO($io, 4);
        SDL_CloseIO($io);

        return $bytes;
    };
    expect($into->width % 64)->toBe(0)
        ->and(trim($read($into->x, $into->y), "\0"))->not->toBe('')
        ->and($into->x > 0 ? trim(substr($read(0, $into->y), 0, 3), "\0") : '')->toBe('');
});

it('lists displays and modes, and names the display a window is on', function (): void {
    $window = stage();
    $displays = stager()->displays();

    expect($displays)->not->toBeEmpty()
        ->and($displays[0])->toBeInstanceOf(Display::class)
        ->and($displays[0]->id)->toBe(SDL_GetPrimaryDisplay())
        ->and(stager()->primaryDisplay()->id)->toBe(SDL_GetPrimaryDisplay())
        ->and(array_map(fn (Display $d): int => $d->id, $displays))->toContain($window->display()->id)
        ->and($window->displayModes())->not->toBeEmpty()
        ->and($window->displayModes()[0])->toBeInstanceOf(DisplayMode::class)
        ->and($window->hdr())->not->toBeNull();
});

it('moves where the compositor allows it', function (): void {
    $window = stage();

    if (wayland()) {
        expect(fn () => $window->move(40, 60))->toThrow(WindowException::class, 'the sdl3/wayland backend does not support it');
    } else {
        // Inside the usable area: macOS keeps a title bar clear of the menu bar.
        $usable = $window->display()->usable;
        expect($window->move($usable->x + 40, $usable->y + 60)->position())->toBe([$usable->x + 40, $usable->y + 60]);
    }
});

it('bounds its size, keeps an aspect range and toggles its style natively', function (): void {
    $window = stage();

    $window->setLimits(100, 80, 800, 600)->setAspectRatio(1.0, 2.0)->setBorderless(true)->setResizable(false)->setOpacity(0.5);

    SDL_GetWindowMinimumSize($window->native(), $minW, $minH);
    SDL_GetWindowMaximumSize($window->native(), $maxW, $maxH);
    SDL_GetWindowAspectRatio($window->native(), $minA, $maxA);
    $flags = SDL_GetWindowFlags($window->native());
    expect([$minW, $minH, $maxW, $maxH])->toBe([100, 80, 800, 600])
        ->and([$minA, $maxA])->toBe([1.0, 2.0])
        ->and($flags & SDL_WINDOW_BORDERLESS)->toBe(SDL_WINDOW_BORDERLESS)
        ->and($flags & SDL_WINDOW_RESIZABLE)->toBe(0)
        ->and(SDL_GetWindowOpacity($window->native()))->toBe(0.5);

    if (! wayland()) {
        $window->setAlwaysOnTop(true)->setFocusable(false);
        expect(SDL_GetWindowFlags($window->native()) & (SDL_WINDOW_ALWAYS_ON_TOP | SDL_WINDOW_NOT_FOCUSABLE))->toBe(SDL_WINDOW_ALWAYS_ON_TOP | SDL_WINDOW_NOT_FOCUSABLE);
    }
});

it('takes creation-time style on every backend', function (): void {
    $window = stage('stage', ['borderless' => true, 'always_on_top' => true, 'focusable' => false, 'transparent' => true]);

    $want = SDL_WINDOW_BORDERLESS | SDL_WINDOW_ALWAYS_ON_TOP | SDL_WINDOW_NOT_FOCUSABLE | SDL_WINDOW_TRANSPARENT;
    expect(SDL_GetWindowFlags($window->native()) & $want)->toBe($want);
});

it('maximizes and restores, posting each mode it entered', function (): void {
    $window = stage('stage', ['resizable' => true]);

    $window->setMode(WindowMode::Maximized);
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Maximized)]);

    $window->setMode(WindowMode::Windowed);
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Windowed)]);
});

it('enters and leaves desktop fullscreen', function (): void {
    $window = stage();

    $window->setMode(WindowMode::Fullscreen);
    expect(mailOf(WindowModeChanged::class, 5.0))->toEqual([new WindowModeChanged('stage', WindowMode::Fullscreen)])
        ->and(SDL_GetWindowFlags($window->native()) & SDL_WINDOW_FULLSCREEN)->toBe(SDL_WINDOW_FULLSCREEN);

    $window->setMode(WindowMode::Windowed);
    expect(mailOf(WindowModeChanged::class, 5.0))->toEqual([new WindowModeChanged('stage', WindowMode::Windowed)]);
});

it('covers another display in desktop fullscreen and reports it as desktop fullscreen', function (): void {
    $window = stage();
    $other = array_values(array_filter(stager()->displays(), fn (Display $d): bool => $d->id !== $window->display()->id))[0];

    // Wayland has no position: the window reaches the other display through its desktop mode.
    $window->setMode(WindowMode::Fullscreen, $other);
    expect(mailOf(WindowModeChanged::class, 5.0))->toEqual([new WindowModeChanged('stage', WindowMode::Fullscreen)])
        ->and($window->mode())->toBe(WindowMode::Fullscreen)
        ->and(SDL_GetDisplayForWindow($window->native()))->toBe($other->id);

    $window->setMode(WindowMode::Windowed);
    expect(mailOf(WindowModeChanged::class, 5.0))->toEqual([new WindowModeChanged('stage', WindowMode::Windowed)]);
})->skip(fn (): bool => count(stager()->displays()) < 2, 'needs a second display');

it('runs exclusive fullscreen at a display mode', function (): void {
    $window = stage();
    $display = $window->display();
    $other = array_values(array_filter($window->displayModes(), fn (DisplayMode $m): bool => $m->width !== $display->desktop->width))[0];

    $window->setMode(WindowMode::Exclusive, $other);
    expect(mailOf(WindowModeChanged::class, 5.0))->toEqual([new WindowModeChanged('stage', WindowMode::Exclusive)])
        ->and($window->exclusiveMode())->toBe($other);
    // Wayland scales the window to the mode; elsewhere the display switches.
    if (wayland()) {
        SDL_GetWindowSizeInPixels($window->native(), $pw, $ph);
        expect([$pw, $ph])->toBe([$other->width, $other->height]);
    } else {
        expect(SDL_GetCurrentDisplayMode($display->id)->w)->toBe($other->width);
    }

    $window->setMode(WindowMode::Windowed);
    pumpUntil(fn (): bool => SDL_GetCurrentDisplayMode($display->id)->w === $display->desktop->width, 5.0);
    expect(SDL_GetCurrentDisplayMode($display->id)->w)->toBe($display->desktop->width);
});

it('keeps the display awake while any window asks', function (): void {
    $a = stage('a');
    $b = stage('b');

    $a->keepAwake(true);
    $b->keepAwake(true);
    $a->keepAwake(false);
    expect(SDL_ScreenSaverEnabled())->toBeFalse();

    $b->close();
    expect(SDL_ScreenSaverEnabled())->toBeTrue();
});

it('asks for attention, takes an icon, reads its safe area and maps its hit test', function (): void {
    $window = stage();

    $window->requestAttention()->setIcon(str_repeat("\xff\x80\x00\xff", 16 * 16), 16, 16)->hitTest(fn (int $x, int $y): HitArea => HitArea::Draggable);
    $area = $window->safeArea();

    expect([$area->width, $area->height])->toBe([320, 200])
        ->and($window->hitTest(null))->toBe($window);
});

it('closes on a close request, or asks first with confirm_close', function (): void {
    $asking = stage('asking', ['confirm_close' => true]);
    $asking->handle(windowEvent($asking, SDL_EVENT_WINDOW_CLOSE_REQUESTED));
    expect($asking->isOpen())->toBeTrue()
        ->and(takeMail())->toContainEqual(new WindowCloseRequested('asking'));

    $plain = stage('plain');
    $plain->handle(windowEvent($plain, SDL_EVENT_WINDOW_CLOSE_REQUESTED));
    expect($plain->isOpen())->toBeFalse()
        ->and(takeMail())->toContainEqual(new WindowClosed('plain'))
        ->and(stager()->hasStaged('plain'))->toBeFalse()
        ->and(fn () => $plain->native())->toThrow(WindowException::class, "Staged window 'plain' was closed.");
});

it('lends the window itself to the sdl3 engine, and draws on the CPU again once the engine lets go', function (): void {
    $window = stage();
    $drawing = sdl3Drawing();
    VenusianSdl3ServiceProvider::extend($drawing);

    $engine = $drawing->renderer('sdl3', ['output' => $window]);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 0, 0)));
    $window->present();

    expect($window->lent()?->kind)->toBe(SurfaceKind::SDL_WINDOW)
        ->and($window->lent()->handle('window'))->toBe($window->native()->pointer())
        ->and(SDL_WindowHasSurface($window->native()))->toBeFalse();

    $engine->release();
    $window->framebuffer()->setPixel(0, 0, 0xFF00FFFF);
    $window->present();
    expect($window->lent())->toBeNull()
        ->and(SDL_WindowHasSurface($window->native()))->toBeTrue();
});

it('lends a GL context and swaps after the borrower\'s copy', function (): void {
    $window = stage('stage', ['title' => 'GL']);
    $window->setOpacity(0.5)->setLimits(100, 80);
    $borrower = new StageBorrower();

    $surface = $window->lend(SurfaceKind::GL_CONTEXT, $borrower);
    $window->present();

    SDL_GetWindowMinimumSize($window->native(), $minW, $minH);
    expect($surface->handle('context'))->toBeGreaterThan(0)
        ->and(SDL_GetWindowTitle($window->native()))->toBe('GL')
        ->and($window->size())->toBe([320, 200])
        ->and(SDL_GetWindowOpacity($window->native()))->toBe(0.5)
        ->and([$minW, $minH])->toBe([100, 80])
        ->and(SDL_GetWindowFlags($window->native()) & SDL_WINDOW_HIDDEN)->toBe(0)
        ->and(PHP_OS_FAMILY === 'Darwin' || (SDL_GetWindowFlags($window->native()) & SDL_WINDOW_OPENGL) !== 0)->toBeTrue()
        ->and(PHP_OS_FAMILY === 'Darwin' || $surface->handle('display') > 0)->toBeTrue()
        ->and($borrower->presented)->toHaveCount(1);

    $window->reclaim();
    expect($window->lent())->toBeNull();
})->skip(! extension_loaded('opengl') || (PHP_OS_FAMILY === 'Darwin' && ! class_exists(NSOpenGLContext::class)), 'needs ext-opengl (and ext-appkit on macOS)');

it('lends a Metal view\'s layer on macOS', function (): void {
    $window = stage();

    $surface = $window->lend(SurfaceKind::METAL_LAYER, new StageBorrower());

    expect($surface->handle('layer'))->toBeGreaterThan(0)
        ->and(CAMetalLayer::fromPointer($surface->handle('layer')))->toBeInstanceOf(CAMetalLayer::class);
    $window->reclaim();
})->skip(PHP_OS_FAMILY !== 'Darwin' || ! class_exists(CAMetalLayer::class), 'needs macOS and ext-metal');

it('lends a Vulkan surface against the borrower\'s instance', function (): void {
    $window = stage();
    $device = new VulkanDevice();

    $surface = $window->lend(SurfaceKind::VULKAN_SURFACE, new StageBorrower($device->handles()));

    expect($surface->handle('surface'))->toBeGreaterThan(0)
        ->and(PHP_OS_FAMILY === 'Darwin' || (SDL_GetWindowFlags($window->native()) & SDL_WINDOW_VULKAN) !== 0)->toBeTrue()
        ->and(stager()->getStaged('stage'))->toBe($window)
        ->and(fn () => stage('other')->lend(SurfaceKind::VULKAN_SURFACE, new StageBorrower()))
            ->toThrow(WindowException::class, "Staged window 'other' makes a Vulkan surface against the borrower's 'instance' handle, and it has none.");
    $window->reclaim();
    $device->release();
})->skip(! extension_loaded('vulkan') || ! class_exists(VulkanDevice::class), 'needs ext-vulkan and jovian/venusian-vulkan');

it('swaps a lent GL context under vsync only while the window shows', function (): void {
    $window = stage('hidden-gl', ['vsync' => VSync::On]);
    $borrower = new StageBorrower();
    $window->lend(SurfaceKind::GL_CONTEXT, $borrower);

    $window->hide();
    pumpFor(0.1);
    $started = hrtime(true);
    $window->present();
    $hidden = count($borrower->presented);
    $elapsed = (hrtime(true) - $started) / 1e6;
    $window->show();
    pumpFor(0.2);
    $window->present();

    expect($hidden)->toBe(0)
        ->and($elapsed)->toBeLessThan(50.0)
        ->and($borrower->presented)->toHaveCount(1);
    $window->reclaim();
})->skip(! extension_loaded('opengl') || (PHP_OS_FAMILY === 'Darwin' && ! class_exists(NSOpenGLContext::class)), 'needs ext-opengl (and ext-appkit on macOS)');

it('hands a GL borrower that asks for it the native window, without an SDL context', function (): void {
    $window = stage('native-gl');
    $borrower = new StageBorrower(['native' => 1]);

    $surface = $window->lend(SurfaceKind::GL_CONTEXT, $borrower);
    $window->present();
    $keys = array_keys($surface->handles());
    sort($keys);
    $driver = SDL_GetCurrentVideoDriver();

    expect($keys)->toBe(match ($driver) {
        'cocoa' => ['context', 'view'],
        'wayland' => ['context', 'wl_display', 'wl_surface'],
        'x11' => ['context', 'x11_display', 'x11_window'],
    })->and($surface->handle('context'))->toBe(0)->and(PHP_OS_FAMILY === 'Darwin' || (SDL_GetWindowFlags($window->native()) & SDL_WINDOW_OPENGL) === 0)->toBeTrue()
        ->and($borrower->presented)->toHaveCount(1);
    $window->reclaim();
    $window->framebuffer()->setPixel(0, 0, 0x00FF00FF);
    $window->present();
    expect($window->lent())->toBeNull();
});

it('takes presents through a lent window while hidden, and a window made after it is shown again still opens', function (): void {
    $window = stage('hidden-sdl');
    $device = new Sdl3Device;
    $engine = new GpuRenderingEngine($device, 320, 200, output: $window);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 80)));
    $window->present();
    $window->hide();
    pumpFor(0.2);
    for ($i = 0; $i < 10; $i++) {
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 0, 0)));
        $window->present();
    }
    $window->show();
    pumpFor(0.3);
    $window->present();
    $engine->release();
    $next = stage('after-hidden-sdl');

    expect($next->native())->toBeInstanceOf(SDL_Window::class);
});
