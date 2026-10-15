<?php

namespace Jovian\Engines\Sdl3\Providers;

use Jovian\Engines\Sdl3\Bridge\Sdl3BridgeDriver;
use Jovian\Engines\Sdl3\Input\Sdl3InputEngine;
use Jovian\Engines\Sdl3\Input\Sdl3PadSource;
use Jovian\Engines\Sdl3\Sdl3Device;
use NSApplication;
use NSApplicationActivationPolicy;
use ObjCDelegate;
use Surface\Bridge\ToolkitManager;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Drawing\DrawingManager;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\HumanInput\HumanInputManager;
use Surface\HumanInput\InputFrame;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Registers the 'sdl3' engine with the drawing manager: each renderer() call
 * builds a new engine over a new Sdl3Device from the shared arguments. With
 * Surface's bridge bound, registers the 'sdl3' stager too: staged windows on SDL.
 *
 * With Surface's HumanInput bound, registers the 'sdl3' pad source (SDL's pads,
 * window or not) and, with the bridge, the 'sdl3' input engine: keys, text and the
 * pointer of SDL staged windows.
 *
 * The creator brings SDL's video subsystem up (SDL_InitSubSystem,
 * reference-counted) before the device; it never quits it, since the process
 * may hold SDL windows of its own. On macOS with ext-appkit, the application
 * and its delegate come first, so SDL takes neither.
 */
class VenusianSdl3ServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }

    public function boot(): void
    {
        self::extend($this->app->get('drawing'));
        if ($this->app->has('toolkit-bridge')) {
            self::stage($this->app->get('toolkit-bridge'));
            if ($this->app->has('human-input')) {
                self::input($this->app->get('human-input'), $this->app->get('toolkit-bridge'));
            }
        }
        if ($this->app->has('human-input')) {
            self::pads($this->app->get('human-input'));
        }
    }

    /** The 'sdl3' stager, on any toolkit manager: Surface names no toolkit. */
    public static function stage(ToolkitManager $toolkits): void
    {
        $toolkits->extend('sdl3', fn ($app): Sdl3BridgeDriver => new Sdl3BridgeDriver($app));
    }

    /** The 'sdl3' input engine for the SDL session on $toolkits: Surface names no engine. */
    public static function input(HumanInputManager $input, ToolkitManager $toolkits): void
    {
        $input->extend('sdl3', fn (InputFrame $frame): Sdl3InputEngine => new Sdl3InputEngine($frame, $toolkits->driver('sdl3')));
    }

    /** The 'sdl3' pad source: SDL's pads for every toolkit's windows and for scripts with none. */
    public static function pads(HumanInputManager $input): void
    {
        $input->extendPads('sdl3', fn (InputFrame $frame): Sdl3PadSource => new Sdl3PadSource($frame));
    }

    /**
     * On macOS with ext-appkit, the application exists, with a delegate, before SDL video does:
     * with no NSApp, SDL makes its own (a Dock icon, its own menus); with no delegate, it makes
     * itself the delegate and the URL event handler. The delegate set here is a trampoline that
     * answers no selector, so AppKit behaves as with none.
     */
    public static function applicationFirst(): void
    {
        static $delegate = null;
        if (PHP_OS_FAMILY !== 'Darwin' || ! class_exists(NSApplication::class)) {
            return;
        }
        $application = NSApplication::sharedApplication();
        if (is_null($application->delegate())) {
            $delegate = new ObjCDelegate('NSApplicationDelegate');
            $application->setDelegate($delegate);
        }
    }

    /**
     * On macOS with ext-appkit, what SDL's own delegate would do, since applicationFirst()
     * keeps SDL from setting one: a regular app (Dock icon, app switcher, key windows) while
     * any session holds it, a background one once the last lets go. activateIgnoringOtherApps,
     * not activate: a process started from a terminal is never frontmost, and macOS declines
     * activate's cooperative request, which leaves the window clickable but never key, so
     * keys reach another app.
     */
    public static function foreground(bool $hold): void
    {
        static $holders = 0;
        if (PHP_OS_FAMILY !== 'Darwin' || ! class_exists(NSApplication::class)) {
            return;
        }
        $holders = max(0, $holders + ($hold ? 1 : -1));
        $application = NSApplication::sharedApplication();
        if ($hold) {
            $application->setActivationPolicy(NSApplicationActivationPolicy::REGULAR);
            $application->activateIgnoringOtherApps(true);
        } elseif ($holders === 0) {
            $application->setActivationPolicy(NSApplicationActivationPolicy::PROHIBITED);
        }
    }

    /** On macOS with ext-appkit, bring the app to the front, as showing a focusable window should. */
    public static function activate(): void
    {
        if (PHP_OS_FAMILY === 'Darwin' && class_exists(NSApplication::class)) {
            NSApplication::sharedApplication()->activateIgnoringOtherApps(true);
        }
    }

    /** The engine's creator, on any drawing manager. */
    public static function extend(DrawingManager $drawing): void
    {
        $drawing->extend('sdl3', function (array $args, DrawingManager $drawing): GpuRenderingEngine {
            self::applicationFirst();
            if (! SDL_InitSubSystem(SDL_INIT_VIDEO)) {
                throw new DrawingException('sdl3: SDL video could not start: '.SDL_GetError());
            }

            return GpuRenderingEngine::from(new Sdl3Device, $args, $drawing);
        });
    }
}
