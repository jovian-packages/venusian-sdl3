<?php

namespace Jovian\Engines\Sdl3\Providers;

use Jovian\Engines\Sdl3\Sdl3Device;
use NSApplication;
use ObjCDelegate;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Drawing\DrawingManager;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Registers the 'sdl3' engine with the drawing manager: each renderer() call
 * builds a new engine over a new Sdl3Device from the shared arguments.
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
    }

    /**
     * On macOS with ext-appkit, the application exists, with a delegate, before SDL video does:
     * with no NSApp, SDL makes its own (a Dock icon, its own menus); with no delegate, it makes
     * itself the delegate and the URL event handler. The delegate set here is a trampoline that
     * answers no selector, so AppKit behaves as with none.
     */
    private static function applicationFirst(): void
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
