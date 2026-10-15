<?php

namespace Jovian\Engines\Sdl3\Windows;

use Closure;
use Jovian\Engines\Sdl3\Bridge\Sdl3BridgeDriver;
use Jovian\Engines\Sdl3\Bridge\Sdl3Session;
use Jovian\Engines\Sdl3\Providers\VenusianSdl3ServiceProvider;
use NSOpenGLContext;
use NSWindow;
use SDL_GLContext;
use SDL_MetalView;
use SDL_Point;
use SDL_Rect;
use SDL_Surface;
use SDL_Window;
use SDL_WindowEvent;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\DisplayMode;
use Surface\Contracts\Windows\Hdr;
use Surface\Contracts\Windows\HitArea;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Contracts\Windows\WindowCapability;
use Surface\Contracts\Windows\WindowException;
use Surface\Contracts\Windows\WindowMode;
use Surface\Windows\StagedWindow;

/**
 * A staged window on SDL 3. Its backend names the video driver: sdl3/cocoa,
 * sdl3/wayland, sdl3/x11.
 *
 * present() blits the frame into the window surface at presentRect() with
 * the scaling filter, and pushes only the damaged rects
 * (SDL_UpdateWindowSurfaceRects); a whole frame clears the bars around it
 * first. Vsync is the window surface's (Mailbox shows without waiting).
 *
 * Fullscreen is SDL's desktop fullscreen; Exclusive is SDL's fullscreen mode
 * (Wayland scales the window to it instead of switching the display). Mode
 * changes report from SDL's window events.
 *
 * Lends the window itself to the sdl3 engine, a Metal view's layer on macOS,
 * a VkSurfaceKHR against the borrower's instance, or an SDL GL context, whose
 * present this window drives (make current, the borrower's copy, swap). A lent
 * window has no window surface; the next present after reclaim() makes one,
 * once no GPU device still claims the window. X11 and Wayland take OpenGL or
 * Vulkan only at creation: lending either remakes the native window with it
 * (takeApi()), its state carried over.
 */
class Sdl3StagedWindow extends StagedWindow
{
    protected ?SDL_Window $window;

    protected int $id;

    protected readonly string $driver_name;

    protected bool $key = false;

    /** The mode last reported through modeChanged(). */
    protected WindowMode $native_mode = WindowMode::Windowed;

    /** Desktop fullscreen reached another display through its desktop mode (Wayland): SDL then holds a fullscreen mode that is not Exclusive. */
    protected bool $desktop_by_mode = false;

    protected bool $awake = false;

    protected ?SDL_MetalView $metal_view = null;

    protected ?int $vk_instance = null;

    protected ?int $vk_surface = null;

    protected ?SDL_GLContext $gl_context = null;

    /** @var array{string, int, int}|null the last icon, put on a remade window */
    protected ?array $icon = null;

    /** @var list<SDL_Rect> the rects the last present pushed, for tests */
    public array $pushed = [];

    public function __construct(
        string $name,
        Sdl3Session $session,
        protected readonly Sdl3BridgeDriver $driver,
        int $width,
        int $height,
        array $options,
    ) {
        parent::__construct($name, $session, $options);
        $this->driver_name = SDL_GetCurrentVideoDriver() ?? 'unknown';

        $this->window = $this->makeWindow($width, $height, $options['x'], $options['y'], $options['display']?->id ?? 0, true, 0);
        $this->id = SDL_GetWindowID($this->window);
        $session->attach($this->id, $this);

        $this->stage($options);
    }

    /**
     * The SDL window with the creation-time style this window holds, hidden or not, with $api
     * (SDL_WINDOW_OPENGL, SDL_WINDOW_VULKAN or 0) set: SDL takes those only at creation.
     * Placed at $x, $y, or centred on display $display (0: the primary).
     *
     * @throws WindowException When SDL makes no window.
     */
    protected function makeWindow(int $width, int $height, ?int $x, ?int $y, int $display, bool $hidden, int $api): SDL_Window
    {
        $props = SDL_CreateProperties();
        SDL_SetStringProperty($props, SDL_PROP_WINDOW_CREATE_TITLE_STRING, $this->title);
        SDL_SetNumberProperty($props, SDL_PROP_WINDOW_CREATE_WIDTH_NUMBER, $width);
        SDL_SetNumberProperty($props, SDL_PROP_WINDOW_CREATE_HEIGHT_NUMBER, $height);
        $centred = SDL_WINDOWPOS_CENTERED_MASK | $display;
        SDL_SetNumberProperty($props, SDL_PROP_WINDOW_CREATE_X_NUMBER, $x ?? $centred);
        SDL_SetNumberProperty($props, SDL_PROP_WINDOW_CREATE_Y_NUMBER, $y ?? $centred);
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_HIDDEN_BOOLEAN, $hidden);
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_HIGH_PIXEL_DENSITY_BOOLEAN, true);
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_RESIZABLE_BOOLEAN, $this->resizable);
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_BORDERLESS_BOOLEAN, $this->borderless);
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_ALWAYS_ON_TOP_BOOLEAN, $this->always_on_top);
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_FOCUSABLE_BOOLEAN, $this->focusable);
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_TRANSPARENT_BOOLEAN, $this->transparent);
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_OPENGL_BOOLEAN, $api === SDL_WINDOW_OPENGL);
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_VULKAN_BOOLEAN, $api === SDL_WINDOW_VULKAN);
        $window = SDL_CreateWindowWithProperties($props);
        SDL_DestroyProperties($props);

        return $window ?? throw new WindowException("Staged window '{$this->name}' could not be made by SDL: ".SDL_GetError());
    }

    /**
     * A window that takes $api: on macOS SDL turns the window it has into one
     * (measured); X11 and Wayland take the API only at creation, so the native
     * window is made again with it, hidden, the old one destroyed, and this
     * window's state put back on the new one: place, limits, aspect, opacity,
     * icon, hit test, mode, visibility.
     */
    protected function takeApi(int $api): void
    {
        $old = $this->native();
        if (PHP_OS_FAMILY === 'Darwin' || (SDL_GetWindowFlags($old) & $api) !== 0) {
            return;
        }
        [$width, $height] = $this->nativeSize();
        [$x, $y] = $this->supports(WindowCapability::Position) ? $this->nativePosition() : [null, null];
        $display = SDL_GetDisplayForWindow($old);
        $window = $this->makeWindow($width, $height, $x, $y, $display, true, $api);

        $this->session->detach($this->id);
        if (! is_null($this->hit_test)) {
            SDL_SetWindowHitTest($old, null);
        }
        SDL_DestroyWindow($old);
        $this->window = $window;
        $this->id = SDL_GetWindowID($window);
        $this->session->attach($this->id, $this);

        [$minWidth, $minHeight, $maxWidth, $maxHeight] = $this->limits;
        $this->applyLimits($minWidth, $minHeight, $maxWidth, $maxHeight);
        $this->applyAspectRatio(...$this->aspect);
        if ($this->opacity < 1.0) {
            $this->applyOpacity($this->opacity);
        }
        if (! is_null($this->icon)) {
            $this->applyIcon(...$this->icon);
        }
        if (! is_null($this->hit_test)) {
            $this->applyHitTest($this->hit_test);
        }
        if ($this->visible) {
            $this->applyVisible(true);
        }
        $mode = $this->mode;
        if ($mode !== WindowMode::Windowed) {
            $this->native_mode = WindowMode::Windowed;
            $this->applyMode($mode, $mode === WindowMode::Exclusive ? $this->exclusive : null);
        }
    }

    public function backend(): string
    {
        return "sdl3/{$this->driver_name}";
    }

    public function isKey(): bool
    {
        return $this->key;
    }

    /** The native window, for engines and tests. */
    public function native(): SDL_Window
    {
        return $this->window ?? throw new WindowException("Staged window '{$this->name}' was closed.");
    }

    /**
     * One SDL window event, routed here by the session.
     */
    public function handle(SDL_WindowEvent $event): void
    {
        match ($event->type) {
            SDL_EVENT_WINDOW_RESIZED => $this->resized($event->data1, $event->data2),
            SDL_EVENT_WINDOW_MOVED => $this->moved($event->data1, $event->data2),
            SDL_EVENT_WINDOW_FOCUS_GAINED => $this->gainedFocus(),
            SDL_EVENT_WINDOW_FOCUS_LOST => $this->lostFocus(),
            SDL_EVENT_WINDOW_OCCLUDED => $this->occluded(),
            SDL_EVENT_WINDOW_EXPOSED => $this->wasExposed(),
            SDL_EVENT_WINDOW_MINIMIZED, SDL_EVENT_WINDOW_MAXIMIZED, SDL_EVENT_WINDOW_RESTORED,
            SDL_EVENT_WINDOW_ENTER_FULLSCREEN, SDL_EVENT_WINDOW_LEAVE_FULLSCREEN, SDL_EVENT_WINDOW_SHOWN => $this->syncMode(),
            SDL_EVENT_WINDOW_DISPLAY_CHANGED => $this->displayChanged($event->data1),
            SDL_EVENT_WINDOW_DISPLAY_SCALE_CHANGED => $this->scaleChanged($this->nativeScale()),
            SDL_EVENT_WINDOW_CLOSE_REQUESTED => $this->closeRequested(),
            default => null,
        };
    }

    public function surfaces(): array
    {
        $kinds = [SurfaceKind::SDL_WINDOW];
        if (PHP_OS_FAMILY === 'Darwin' && class_exists(\CAMetalLayer::class)) {
            $kinds[] = SurfaceKind::METAL_LAYER;
        }
        if (extension_loaded('vulkan')) {
            $kinds[] = SurfaceKind::VULKAN_SURFACE;
        }
        // The opengl engine adopts a CGL context on macOS (through ext-appkit) and an EGL one elsewhere.
        if (extension_loaded('opengl') && (PHP_OS_FAMILY !== 'Darwin' || class_exists(NSOpenGLContext::class))) {
            $kinds[] = SurfaceKind::GL_CONTEXT;
        }

        return $kinds;
    }

    protected function nativeCapabilities(): array
    {
        $capabilities = [
            WindowCapability::Opacity, WindowCapability::Icon, WindowCapability::KeepAwake,
            WindowCapability::Attention, WindowCapability::ExclusiveFullscreen, WindowCapability::HitTest,
        ];
        // Measured: a Wayland toplevel has no position, and takes stacking and focus refusal only at creation.
        if ($this->driver_name !== 'wayland') {
            array_push($capabilities, WindowCapability::Position, WindowCapability::AlwaysOnTop, WindowCapability::Focusable);
        }

        return $capabilities;
    }

    protected function nativeSize(): array
    {
        SDL_GetWindowSize($this->native(), $width, $height);

        return [$width, $height];
    }

    protected function nativeScale(): float
    {
        return SDL_GetWindowPixelDensity($this->native());
    }

    protected function nativePosition(): array
    {
        SDL_GetWindowPosition($this->native(), $x, $y);

        return [$x, $y];
    }

    protected function nativeSafeArea(): Region
    {
        $area = new SDL_Rect();
        SDL_GetWindowSafeArea($this->native(), $area);

        return new Region($area->x, $area->y, $area->w, $area->h);
    }

    protected function nativeDisplay(): Display
    {
        return Sdl3Displays::describe(SDL_GetDisplayForWindow($this->native()));
    }

    protected function nativeDisplayModes(): array
    {
        return Sdl3Displays::modes(SDL_GetDisplayForWindow($this->native()));
    }

    protected function nativeHdr(): ?Hdr
    {
        $props = SDL_GetWindowProperties($this->native());

        return new Hdr(
            SDL_GetBooleanProperty($props, SDL_PROP_WINDOW_HDR_ENABLED_BOOLEAN, false),
            SDL_GetFloatProperty($props, SDL_PROP_WINDOW_SDR_WHITE_LEVEL_FLOAT, 1.0),
            SDL_GetFloatProperty($props, SDL_PROP_WINDOW_HDR_HEADROOM_FLOAT, 1.0),
        );
    }

    protected function applyTitle(string $title): void
    {
        SDL_SetWindowTitle($this->native(), $title);
    }

    protected function applyVisible(bool $visible): void
    {
        $visible ? SDL_ShowWindow($this->native()) : SDL_HideWindow($this->native());
        if ($visible && $this->focusable) {
            VenusianSdl3ServiceProvider::activate();
            SDL_RaiseWindow($this->native());
        }
    }

    /** @throws WindowException When the display offers no mode like an exclusive one asked for. */
    protected function applyMode(WindowMode $mode, Display|DisplayMode|null $on): void
    {
        $window = $this->native();
        $flags = SDL_GetWindowFlags($window);
        if (($flags & SDL_WINDOW_FULLSCREEN) !== 0 && ! in_array($mode, [WindowMode::Fullscreen, WindowMode::Exclusive], true)) {
            SDL_SetWindowFullscreen($window, false);
        }
        match ($mode) {
            WindowMode::Windowed => ($flags & (SDL_WINDOW_MAXIMIZED | SDL_WINDOW_MINIMIZED)) !== 0 ? SDL_RestoreWindow($window) : null,
            WindowMode::Maximized => SDL_MaximizeWindow($window),
            WindowMode::Minimized => SDL_MinimizeWindow($window),
            WindowMode::Fullscreen => $this->fullscreenOn($on),
            WindowMode::Exclusive => $this->exclusiveAt($on),
        };
        SDL_SyncWindow($window);
        $this->syncMode();
    }

    protected function applyResize(int $width, int $height): void
    {
        SDL_SetWindowSize($this->native(), $width, $height);
        SDL_SyncWindow($this->native());
    }

    protected function applyPosition(int $x, int $y): void
    {
        SDL_SetWindowPosition($this->native(), $x, $y);
        SDL_SyncWindow($this->native());
    }

    protected function applyDisplay(Display $display): void
    {
        $centred = SDL_WINDOWPOS_CENTERED_MASK | $display->id;
        SDL_SetWindowPosition($this->native(), $centred, $centred);
        SDL_SyncWindow($this->native());
    }

    protected function applyLimits(int $minWidth, int $minHeight, int $maxWidth, int $maxHeight): void
    {
        SDL_SetWindowMinimumSize($this->native(), $minWidth, $minHeight);
        SDL_SetWindowMaximumSize($this->native(), $maxWidth, $maxHeight);
    }

    protected function applyAspectRatio(float $min, float $max): void
    {
        SDL_SetWindowAspectRatio($this->native(), $min, $max);
    }

    protected function applyResizable(bool $resizable): void
    {
        SDL_SetWindowResizable($this->native(), $resizable);
    }

    protected function applyBorderless(bool $borderless): void
    {
        SDL_SetWindowBordered($this->native(), ! $borderless);
    }

    /** The window surface's vsync, set when the surface exists (the next present makes it). */
    protected function applyVsync(VSync $vsync): void
    {
        if (SDL_WindowHasSurface($this->native())) {
            SDL_SetWindowSurfaceVSync($this->native(), self::surfaceVsync($vsync));
        }
    }

    protected function applyAlwaysOnTop(bool $onTop): void
    {
        SDL_SetWindowAlwaysOnTop($this->native(), $onTop);
    }

    protected function applyFocusable(bool $focusable): void
    {
        SDL_SetWindowFocusable($this->native(), $focusable);
    }

    protected function applyOpacity(float $opacity): void
    {
        SDL_SetWindowOpacity($this->native(), $opacity);
    }

    protected function applyKeepAwake(bool $awake): void
    {
        if ($awake !== $this->awake) {
            $this->awake = $awake;
            $this->driver->keepAwake($awake);
        }
    }

    protected function applyAttention(): void
    {
        SDL_FlashWindow($this->native(), SDL_FLASH_UNTIL_FOCUSED);
    }

    protected function applyIcon(string $rgba8, int $width, int $height): void
    {
        $icon = SDL_CreateSurfaceFrom($width, $height, SDL_PIXELFORMAT_RGBA32, $rgba8, $width * 4)
            ?? throw new WindowException("Staged window '{$this->name}' could not make an icon surface: ".SDL_GetError());
        SDL_SetWindowIcon($this->native(), $icon);
        SDL_DestroySurface($icon);
        $this->icon = [$rgba8, $width, $height];
    }

    protected function applyHitTest(?Closure $test): void
    {
        $areas = [
            HitArea::Normal->value => SDL_HITTEST_NORMAL, HitArea::Draggable->value => SDL_HITTEST_DRAGGABLE,
            HitArea::ResizeTopLeft->value => SDL_HITTEST_RESIZE_TOPLEFT, HitArea::ResizeTop->value => SDL_HITTEST_RESIZE_TOP,
            HitArea::ResizeTopRight->value => SDL_HITTEST_RESIZE_TOPRIGHT, HitArea::ResizeRight->value => SDL_HITTEST_RESIZE_RIGHT,
            HitArea::ResizeBottomRight->value => SDL_HITTEST_RESIZE_BOTTOMRIGHT, HitArea::ResizeBottom->value => SDL_HITTEST_RESIZE_BOTTOM,
            HitArea::ResizeBottomLeft->value => SDL_HITTEST_RESIZE_BOTTOMLEFT, HitArea::ResizeLeft->value => SDL_HITTEST_RESIZE_LEFT,
        ];
        SDL_SetWindowHitTest($this->native(), is_null($test) ? null : fn (SDL_Window $window, SDL_Point $area): int => $areas[$test($area->x, $area->y)->value]);
    }

    protected function applyPixels(string $rgba8, int $width, int $height, array $damage): void
    {
        $this->blit(SDL_CreateSurfaceFrom($width, $height, SDL_PIXELFORMAT_RGBA32, $rgba8, $width * 4), $width, $height, $damage);
    }

    protected function applyAddress(int $address, int $width, int $height, int $stride, array $damage): void
    {
        $this->blit(SDL_CreateSurfaceFrom($width, $height, SDL_PIXELFORMAT_RGBA32, $address, $stride), $width, $height, $damage);
    }

    /**
     * The frame into the window surface at presentRect(), then out to the
     * screen: the damaged rects, or everything (bars cleared) for a whole frame.
     *
     * @param  list<Region>  $damage
     * @throws WindowException When the window surface cannot be had (a GPU device still claims the window).
     */
    protected function blit(?SDL_Surface $frame, int $width, int $height, array $damage): void
    {
        if (is_null($frame)) {
            throw new WindowException("Staged window '{$this->name}' could not wrap its frame: ".SDL_GetError());
        }
        $window = $this->native();
        $fresh = ! SDL_WindowHasSurface($window);
        $surface = SDL_GetWindowSurface($window);
        if (is_null($surface)) {
            SDL_DestroySurface($frame);

            throw new WindowException("Staged window '{$this->name}' has no window surface: ".SDL_GetError().' (a GPU device that claimed the window must let go of it first).');
        }
        if ($fresh) {
            SDL_SetWindowSurfaceVSync($window, self::surfaceVsync($this->vsync));
        }
        $into = $this->presentRect($width, $height);
        $mode = $this->filter === ScaleFilter::Nearest ? SDL_SCALEMODE_NEAREST : SDL_SCALEMODE_LINEAR;
        $whole = $damage === [] || $fresh || [$surface->w(), $surface->h()] !== $this->pixelSize();

        if ($whole) {
            SDL_FillSurfaceRect($surface, null, 0);
            SDL_BlitSurfaceScaled($frame, null, $surface, new SDL_Rect($into->x, $into->y, $into->width, $into->height), $mode);
            $this->pushed = [new SDL_Rect(0, 0, $surface->w(), $surface->h())];
            SDL_UpdateWindowSurface($window);
        } else {
            $this->pushed = [];
            foreach ($damage as $region) {
                [$from, $to] = $this->regionRects($region, $width, $height, $into);
                SDL_BlitSurfaceScaled($frame, $from, $surface, $to, $mode);
                $this->pushed[] = $to;
            }
            SDL_UpdateWindowSurfaceRects($window, $this->pushed);
        }
        SDL_DestroySurface($frame);
    }

    /**
     * A damaged region of the frame and where it lands in the window, grown
     * by a pixel so a linear filter's edge is redrawn with it.
     *
     * @return array{SDL_Rect, SDL_Rect}
     */
    protected function regionRects(Region $region, int $width, int $height, Region $into): array
    {
        $x0 = max(0, $region->x - 1);
        $y0 = max(0, $region->y - 1);
        $x1 = min($width, $region->right() + 1);
        $y1 = min($height, $region->bottom() + 1);
        $across = $into->width / $width;
        $down = $into->height / $height;
        $tx0 = $into->x + (int) floor($x0 * $across);
        $ty0 = $into->y + (int) floor($y0 * $down);
        $tx1 = $into->x + (int) ceil($x1 * $across);
        $ty1 = $into->y + (int) ceil($y1 * $down);

        return [new SDL_Rect($x0, $y0, $x1 - $x0, $y1 - $y0), new SDL_Rect($tx0, $ty0, $tx1 - $tx0, $ty1 - $ty0)];
    }

    /** @return array<string, int> */
    protected function makeSurface(SurfaceKind $kind, array $handles): array
    {
        $window = $this->native();
        SDL_DestroyWindowSurface($window);

        return match ($kind) {
            SurfaceKind::SDL_WINDOW => ['window' => $window->pointer()],
            SurfaceKind::METAL_LAYER => $this->makeMetalView(),
            SurfaceKind::VULKAN_SURFACE => $this->makeVulkanSurface($handles),
            SurfaceKind::GL_CONTEXT => ($handles['native'] ?? 0) === 1 ? $this->lendNative() : $this->makeGLContext(),
            default => throw new WindowException("Staged window '{$this->name}' cannot make a {$kind->value} surface."),
        };
    }

    protected function removeSurface(SurfaceKind $kind): void
    {
        match ($kind) {
            SurfaceKind::METAL_LAYER => $this->removeMetalView(),
            SurfaceKind::VULKAN_SURFACE => $this->removeVulkanSurface(),
            SurfaceKind::GL_CONTEXT => $this->removeGLContext(),
            default => null,
        };
        // The next present makes the window surface again, the whole frame shown.
        $this->shown = null;
    }

    /** A GL context's frame: current, the borrower's copy into framebuffer 0, swapped. */
    protected function presentLent(LentSurface $surface, SurfaceBorrower $borrower): static
    {
        if (is_null($this->gl_context)) {
            return parent::presentLent($surface, $borrower);
        }
        $frame = $borrower->framebuffer();
        $tracked = $frame instanceof DamageTrackingFramebuffer;
        if (! is_null($this->shown) && $tracked && $frame->damage() === []) {
            return $this;
        }
        SDL_GL_MakeCurrent($this->native(), $this->gl_context);
        $interval = self::swapInterval($surface->vsync());
        // A swap that waits for vsync waits for the compositor to show the window: none while it is hidden.
        if ($interval !== 0 && (SDL_GetWindowFlags($this->native()) & (SDL_WINDOW_OCCLUDED | SDL_WINDOW_MINIMIZED | SDL_WINDOW_HIDDEN)) !== 0) {
            return $this;
        }
        SDL_GL_SetSwapInterval($interval);
        if ($borrower->presentInto($surface)) {
            SDL_GL_SwapWindow($this->native());
            $this->shown = 0;
            if ($tracked) {
                $frame->beginEpoch();
            }
        }

        return $this;
    }

    /**
     * The native window for a GL borrower that makes its own context and
     * surface on it (an HDR or wide-gamut one). On Linux a window SDL made
     * for OpenGL or Vulkan already has SDL's surface on it, so it is remade
     * with neither; a macOS window takes every API as it is.
     *
     * @return array<string, int>
     */
    protected function lendNative(): array
    {
        if (PHP_OS_FAMILY !== 'Darwin' && (SDL_GetWindowFlags($this->native()) & (SDL_WINDOW_OPENGL | SDL_WINDOW_VULKAN)) !== 0) {
            $this->takeApi(0);
        }
        $props = SDL_GetWindowProperties($this->native());

        return match ($this->driver_name) {
            'cocoa' => ['context' => 0, 'view' => NSWindow::fromPointer(SDL_GetPointerProperty($props, SDL_PROP_WINDOW_COCOA_WINDOW_POINTER, 0) ?? 0)->contentView()?->pointer()
                ?? throw new WindowException("Staged window '{$this->name}' has no content view to lend.")],
            'wayland' => ['context' => 0, 'wl_display' => SDL_GetPointerProperty($props, SDL_PROP_WINDOW_WAYLAND_DISPLAY_POINTER, 0) ?? 0, 'wl_surface' => SDL_GetPointerProperty($props, SDL_PROP_WINDOW_WAYLAND_SURFACE_POINTER, 0) ?? 0],
            'x11' => ['context' => 0, 'x11_display' => SDL_GetPointerProperty($props, SDL_PROP_WINDOW_X11_DISPLAY_POINTER, 0) ?? 0, 'x11_window' => SDL_GetNumberProperty($props, SDL_PROP_WINDOW_X11_WINDOW_NUMBER, 0)],
            default => throw new WindowException("Staged window '{$this->name}' has no native window to lend on sdl3/{$this->driver_name}."),
        };
    }

    /** @return array{layer: int, view: int} */
    protected function makeMetalView(): array
    {
        $this->metal_view = SDL_Metal_CreateView($this->native())
            ?? throw new WindowException("Staged window '{$this->name}' could not make a Metal view: ".SDL_GetError());

        return ['layer' => SDL_Metal_GetLayer($this->metal_view), 'view' => $this->metal_view->pointer()];
    }

    protected function removeMetalView(): void
    {
        if (! is_null($this->metal_view)) {
            SDL_Metal_DestroyView($this->metal_view);
            $this->metal_view = null;
        }
    }

    /**
     * @param  array<string, int>  $handles  The borrower's: its VkInstance as 'instance'.
     * @return array{surface: int}
     */
    protected function makeVulkanSurface(array $handles): array
    {
        $instance = $handles['instance'] ?? throw new WindowException("Staged window '{$this->name}' makes a Vulkan surface against the borrower's 'instance' handle, and it has none.");
        $this->takeApi(SDL_WINDOW_VULKAN);
        $surface = 0;
        if (! SDL_Vulkan_CreateSurface($this->native(), $instance, null, $surface)) {
            throw new WindowException("Staged window '{$this->name}' could not make a Vulkan surface: ".SDL_GetError());
        }
        $this->vk_instance = $instance;
        $this->vk_surface = $surface;

        return ['surface' => $surface];
    }

    /** After the borrower let go (LentSurface released first): its swapchain is gone, the surface may go. */
    protected function removeVulkanSurface(): void
    {
        if (! is_null($this->vk_surface)) {
            SDL_Vulkan_DestroySurface($this->vk_instance, $this->vk_surface, null);
            $this->vk_surface = null;
            $this->vk_instance = null;
        }
    }

    /**
     * An SDL GL context on the window: OpenGL 4.1 core on macOS, OpenGL ES 3.1
     * elsewhere, the dialects the opengl engine speaks. macOS hands its
     * CGLContextObj; EGL its context and display.
     *
     * @return array<string, int>
     */
    protected function makeGLContext(): array
    {
        $mac = PHP_OS_FAMILY === 'Darwin';
        if (! $mac) {
            // X11's GL is GLX unless told otherwise; the opengl engine adopts EGL. Read when the GL window is made.
            SDL_SetHint(SDL_HINT_VIDEO_FORCE_EGL, '1');
        }
        $this->takeApi(SDL_WINDOW_OPENGL);
        SDL_GL_SetAttribute(SDL_GL_CONTEXT_PROFILE_MASK, $mac ? SDL_GL_CONTEXT_PROFILE_CORE : SDL_GL_CONTEXT_PROFILE_ES);
        SDL_GL_SetAttribute(SDL_GL_CONTEXT_MAJOR_VERSION, $mac ? 4 : 3);
        SDL_GL_SetAttribute(SDL_GL_CONTEXT_MINOR_VERSION, 1);
        $this->gl_context = SDL_GL_CreateContext($this->native())
            ?? throw new WindowException("Staged window '{$this->name}' could not make a GL context: ".SDL_GetError());
        SDL_GL_MakeCurrent($this->native(), $this->gl_context);
        if ($mac) {
            return ['context' => NSOpenGLContext::fromPointer($this->gl_context->pointer())->CGLContextObj()];
        }

        return ['context' => $this->gl_context->pointer(), 'display' => SDL_EGL_GetCurrentDisplay() ?? 0];
    }

    protected function removeGLContext(): void
    {
        if (! is_null($this->gl_context)) {
            SDL_GL_MakeCurrent($this->native(), null);
            SDL_GL_DestroyContext($this->gl_context);
            $this->gl_context = null;
        }
    }

    protected function destroyNative(): void
    {
        if (is_null($this->window)) {
            return;
        }
        $this->applyKeepAwake(false);
        $this->session->detach($this->id);
        if (! is_null($this->hit_test)) {
            SDL_SetWindowHitTest($this->window, null);
        }
        SDL_DestroyWindow($this->window);
        $this->window = null;
    }

    protected function forget(): void
    {
        $this->driver->forgetStaged($this->name);
    }

    protected function live(): static
    {
        if (is_null($this->window)) {
            throw new WindowException("Staged window '{$this->name}' was closed.");
        }

        return parent::live();
    }

    /** Desktop fullscreen; on another display, moved there first, or on Wayland (no position) through that display's desktop mode. */
    protected function fullscreenOn(?Display $on): void
    {
        $window = $this->native();
        $target = null;
        if (! is_null($on) && $on->id !== SDL_GetDisplayForWindow($window)) {
            if ($this->supports(WindowCapability::Position)) {
                $this->applyDisplay($on);
            } else {
                $target = Sdl3Displays::nativeMode($on->desktop);
            }
        }
        $this->desktop_by_mode = ! is_null($target);
        SDL_SetWindowFullscreenMode($window, $target);
        SDL_SetWindowFullscreen($window, true);
    }

    protected function exclusiveAt(DisplayMode $mode): void
    {
        $native = Sdl3Displays::nativeMode($mode)
            ?? throw new WindowException("Staged window '{$this->name}': display {$mode->displayId} offers no {$mode->width}x{$mode->height} mode at {$mode->refreshRate} Hz and density {$mode->pixelDensity}.");
        $this->desktop_by_mode = false;
        if (! SDL_SetWindowFullscreenMode($this->native(), $native)) {
            throw new WindowException("Staged window '{$this->name}' could not take that mode: ".SDL_GetError());
        }
        SDL_SetWindowFullscreen($this->native(), true);
    }

    /** Report the mode SDL's window is in, when it changed. */
    protected function syncMode(): void
    {
        if (is_null($this->window)) {
            return;
        }
        $flags = SDL_GetWindowFlags($this->window);
        $actual = match (true) {
            ($flags & SDL_WINDOW_FULLSCREEN) !== 0 => $this->desktop_by_mode || is_null(SDL_GetWindowFullscreenMode($this->window)) ? WindowMode::Fullscreen : WindowMode::Exclusive,
            ($flags & SDL_WINDOW_MINIMIZED) !== 0 => WindowMode::Minimized,
            ($flags & SDL_WINDOW_MAXIMIZED) !== 0 => WindowMode::Maximized,
            default => WindowMode::Windowed,
        };
        if ($actual !== $this->native_mode) {
            $this->native_mode = $actual;
            $this->modeChanged($actual);
        }
    }

    protected function gainedFocus(): void
    {
        $this->key = true;
        $this->focused();
    }

    protected function lostFocus(): void
    {
        $this->key = false;
        $this->focusLost();
    }

    /** Shown again: the window surface may have lost its pixels, so the next present is whole. */
    protected function wasExposed(): void
    {
        $this->shown = null;
        $this->exposed();
    }

    /** Window surface vsync: SDL takes 1, 0 or adaptive; Mailbox shows without waiting. */
    protected static function surfaceVsync(VSync $vsync): int
    {
        return match ($vsync) {
            VSync::On => 1,
            VSync::Adaptive => SDL_WINDOW_SURFACE_VSYNC_ADAPTIVE,
            VSync::Off, VSync::Mailbox => SDL_WINDOW_SURFACE_VSYNC_DISABLED,
        };
    }

    /** GL swap interval: 1, 0, or -1 for adaptive. */
    protected static function swapInterval(VSync $vsync): int
    {
        return match ($vsync) {
            VSync::On => 1,
            VSync::Adaptive => -1,
            VSync::Off, VSync::Mailbox => 0,
        };
    }
}
