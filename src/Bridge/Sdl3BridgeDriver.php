<?php

namespace Jovian\Engines\Sdl3\Bridge;

use Jovian\Engines\Sdl3\Windows\Sdl3Displays;
use Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow;
use Surface\Bridge\ToolkitBridgeDriver;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\StagedWindowDriver;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\StagedWindow;

/**
 * SDL 3 as a stager: toolkit 'sdl3' on the bridge, staged windows only (SDL
 * has no primitives). One session, one set of windows.
 */
class Sdl3BridgeDriver extends ToolkitBridgeDriver implements StagedWindowDriver
{
    /** @var array<string, Sdl3StagedWindow> */
    protected array $staged = [];

    /** Windows keeping the display awake: SDL's screen saver switch is process-wide. */
    protected int $awake = 0;

    public function connect(): Sdl3Session
    {
        $this->session ??= new Sdl3Session();

        return $this->session->connect();
    }

    public function openStaged(string $name, int $width, int $height, array $options = []): Sdl3StagedWindow
    {
        if (isset($this->staged[$name])) {
            throw new WindowException("A staged window named '{$name}' is already open.");
        }

        return $this->staged[$name] = new Sdl3StagedWindow($name, $this->connect(), $this, $width, $height, StagedWindow::options($options, $name));
    }

    public function hasStaged(string $name): bool
    {
        return isset($this->staged[$name]);
    }

    public function getStaged(string $name): ?Sdl3StagedWindow
    {
        return $this->staged[$name] ?? null;
    }

    /** @return array<string, Sdl3StagedWindow> */
    public function allStaged(): array
    {
        return $this->staged;
    }

    public function closeAllStaged(): void
    {
        foreach ($this->staged as $window) {
            $window->close();
        }
    }

    public function displays(): array
    {
        $this->connect();

        return Sdl3Displays::all();
    }

    public function primaryDisplay(): Display
    {
        $this->connect();

        return Sdl3Displays::primary();
    }

    public function forgetStaged(string $name): void
    {
        unset($this->staged[$name]);
    }

    /** One window more or fewer keeps the display awake; the screen saver is off while any does. */
    public function keepAwake(bool $awake): void
    {
        $this->awake = max(0, $this->awake + ($awake ? 1 : -1));
        $this->awake > 0 ? SDL_DisableScreenSaver() : SDL_EnableScreenSaver();
    }
}
