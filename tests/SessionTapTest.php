<?php

declare(strict_types=1);

use Surface\Contracts\HumanInput\InputTap;

afterEach(function (): void {
    stager()->closeAllStaged();
});

it('shows every pumped event to its taps, then routes it', function (): void {
    $window = inputWindow('tapped');
    $session = stager()->connect();
    $tap = new class implements InputTap {
        /** @var list<array{int, int}> */
        public array $seen = [];

        public function see(object $native_event): void
        {
            $this->seen[] = [$native_event->type, $native_event->window->windowID];
        }
    };
    $session->pump(0);
    $session->tap($tap);

    $event = new SDL_Event();
    $event->type = SDL_EVENT_WINDOW_FOCUS_GAINED;
    $event->window->windowID = SDL_GetWindowID($window->native());
    SDL_PushEvent($event);
    $session->pump(0);
    $session->untap($tap);

    expect($tap->seen)->toContain([SDL_EVENT_WINDOW_FOCUS_GAINED, SDL_GetWindowID($window->native())])
        ->and($window->isKey())->toBeTrue();
});

it('holds its windows by SDL window id', function (): void {
    $window = inputWindow('mapped');

    expect(stager()->connect()->windows())->toHaveKey(SDL_GetWindowID($window->native()))
        ->and(stager()->connect()->windows()[SDL_GetWindowID($window->native())])->toBe($window);

    $window->close();

    expect(stager()->connect()->windows())->not->toContain($window);
});

it('makes the process a regular, active macOS app while any session is connected, as SDL does when it owns the application', function (): void {
    $driver = new Jovian\Engines\Sdl3\Bridge\Sdl3BridgeDriver(new Voyager\Vessel\ControlPanel());
    $application = NSApplication::sharedApplication();

    stager()->connect();
    $driver->connect();
    $both = $application->activationPolicy();
    $driver->session()->disconnect();
    $one = $application->activationPolicy();
    stager()->session()->disconnect();
    $none = $application->activationPolicy();
    stager()->connect();

    expect([$both, $one, $none])->toBe([NSApplicationActivationPolicy::REGULAR, NSApplicationActivationPolicy::REGULAR, NSApplicationActivationPolicy::PROHIBITED])
        ->and($application->activationPolicy())->toBe(NSApplicationActivationPolicy::REGULAR);
})->skip(fn () => PHP_OS_FAMILY !== 'Darwin' || ! class_exists(NSApplication::class), 'the macOS application is ours only where ext-appkit is loaded');
