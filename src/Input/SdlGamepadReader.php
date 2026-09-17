<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Input;

use Jovian\Bindings\Sdl3\Enums\SDLGamepadAxis;
use Jovian\Bindings\Sdl3\Enums\SDLGamepadButton;
use Jovian\Bindings\Sdl3\Input\SDLGamepad;

/** ReadsGamepads over jovian/sdl3: one SDLGamepad call per method. */
final class SdlGamepadReader implements ReadsGamepads
{
    public function ids(): array
    {
        return array_map(intval(...), array_values(SDLGamepad::SDLGetGamepads()));
    }

    public function open(int $instance_id): int
    {
        return SDLGamepad::SDLOpenGamepad($instance_id);
    }

    public function close(int $handle): void
    {
        SDLGamepad::SDLCloseGamepad($handle);
    }

    public function name(int $handle): string
    {
        return SDLGamepad::SDLGetGamepadName($handle);
    }

    public function refresh(): void
    {
        SDLGamepad::SDLUpdateGamepads();
    }

    public function button(int $handle, SDLGamepadButton $button): bool
    {
        return SDLGamepad::SDLGetGamepadButton($handle, $button);
    }

    public function axis(int $handle, SDLGamepadAxis $axis): int
    {
        return SDLGamepad::SDLGetGamepadAxis($handle, $axis);
    }
}
