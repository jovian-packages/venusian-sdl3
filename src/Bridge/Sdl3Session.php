<?php

namespace Jovian\Engines\Sdl3\Bridge;

use Jovian\Engines\Sdl3\Providers\VenusianSdl3ServiceProvider;
use Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow;
use SDL_Event;
use Surface\Bridge\BridgedToolkitSession;
use Surface\Contracts\Bridge\BridgeException;
use Surface\Contracts\Windows\Mail\DisplaysChanged;

/**
 * SDL 3's video subsystem as a bridge session; every event it pumps is shown
 * to its taps (HumanInput's sdl3 engine) before it is routed. SDL cannot
 * fold a descriptor into its wait, so the session never sleeps natively: the
 * loop polls it at its pace (ToolkitPoller), and pump() drains SDL's queue.
 * Window events go to the staged window with that SDL_WindowID; display
 * events become one DisplaysChanged per pump.
 */
class Sdl3Session extends BridgedToolkitSession
{
    /** @var array<int, Sdl3StagedWindow> by SDL_WindowID */
    protected array $windows = [];

    protected SDL_Event $event;

    /**
     * Video up, reference-counted; never quit, since engines may hold SDL windows of their own.
     * On macOS with ext-appkit the application and its delegate come first, so SDL takes neither.
     *
     * @throws BridgeException When SDL video cannot start.
     */
    protected function initializeEngine(): void
    {
        $this->event = new SDL_Event();
        VenusianSdl3ServiceProvider::applicationFirst();
        if (! SDL_InitSubSystem(SDL_INIT_VIDEO)) {
            throw new BridgeException('sdl3: SDL video could not start: '.SDL_GetError());
        }
    }

    /** On macOS with ext-appkit, a regular, active app while connected (SDL's delegate would have done it). */
    protected function connectToEngine(): void
    {
        VenusianSdl3ServiceProvider::foreground(true);
    }

    protected function disconnectEngine(): void
    {
        VenusianSdl3ServiceProvider::foreground(false);
    }

    protected function sleepsNatively(): bool
    {
        return false;
    }

    /** Never called: the session does not sleep natively. */
    protected function wakeDescriptor(int $fd): void {}

    /** Never called: the session does not sleep natively. */
    protected function releaseWakeDescriptor(): void {}

    /**
     * Wait at most $budget_ns for the first event (SDL's wait, in milliseconds),
     * then dispatch it and everything queued behind it.
     */
    public function pump(int $budget_ns): int
    {
        $event = $this->event;
        $dispatched = 0;
        $displays = false;
        $got = $budget_ns > 0 ? SDL_WaitEventTimeout($event, (int) ceil($budget_ns / 1e6)) : SDL_PollEvent($event);
        while ($got) {
            $dispatched++;
            $this->see($event);
            if ($event->type >= SDL_EVENT_DISPLAY_FIRST && $event->type <= SDL_EVENT_DISPLAY_LAST) {
                $displays = true;
            } elseif ($event->type >= SDL_EVENT_WINDOW_FIRST && $event->type <= SDL_EVENT_WINDOW_LAST) {
                ($this->windows[$event->window->windowID] ?? null)?->handle($event->window);
            }
            $got = SDL_PollEvent($event);
        }
        if ($displays) {
            $this->post(new DisplaysChanged());
        }

        return $dispatched;
    }

    public function attach(int $windowId, Sdl3StagedWindow $window): void
    {
        $this->windows[$windowId] = $window;
    }

    public function detach(int $windowId): void
    {
        unset($this->windows[$windowId]);
    }

    /**
     * The windows this session routes events to, by SDL window id.
     *
     * @return array<int, Sdl3StagedWindow>
     */
    public function windows(): array
    {
        return $this->windows;
    }
}
