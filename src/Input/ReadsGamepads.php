<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Input;

use Jovian\Bindings\Sdl3\Enums\SDLGamepadAxis;
use Jovian\Bindings\Sdl3\Enums\SDLGamepadButton;

/** The only SDL gamepad calls the sdl3 input engine makes. */
interface ReadsGamepads
{
    /** @return list<int> instance ids of the gamepads SDL sees */
    public function ids(): array;

    /** @return int the gamepad handle, 0 on failure */
    public function open(int $instance_id): int;

    public function close(int $handle): void;

    public function name(int $handle): string;

    /** SDL_UpdateGamepads: latch the state the next reads return. */
    public function refresh(): void;

    public function button(int $handle, SDLGamepadButton $button): bool;

    /** @return int −32768…32767; triggers 0…32767 */
    public function axis(int $handle, SDLGamepadAxis $axis): int;
}
