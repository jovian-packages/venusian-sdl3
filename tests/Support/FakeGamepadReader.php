<?php

declare(strict_types=1);

namespace Venusian\Tests\Support;

use Jovian\Bindings\Sdl3\Enums\SDLGamepadAxis;
use Jovian\Bindings\Sdl3\Enums\SDLGamepadButton;
use Jovian\Venusian\Sdl3\Input\ReadsGamepads;

/** Gamepads in memory: plug/unplug instance ids, script button and axis state per handle. A handle is its instance id. */
final class FakeGamepadReader implements ReadsGamepads
{
    /** @var array<int, string> instance id → name */
    public array $names = [];

    /** @var array<int, array<int, bool>> handle → SDLGamepadButton value → down */
    public array $buttons = [];

    /** @var array<int, array<int, int>> handle → SDLGamepadAxis value → raw */
    public array $axes = [];

    public int $refreshes = 0;

    /** @var list<int> handles closed, in order */
    public array $closed = [];

    public function plug(int $id, string $name): void
    {
        $this->names[$id] = $name;
    }

    public function unplug(int $id): void
    {
        unset($this->names[$id]);
    }

    public function ids(): array
    {
        return array_keys($this->names);
    }

    public function open(int $instance_id): int
    {
        return $instance_id;
    }

    public function close(int $handle): void
    {
        $this->closed[] = $handle;
    }

    public function name(int $handle): string
    {
        return $this->names[$handle] ?? '';
    }

    public function refresh(): void
    {
        $this->refreshes++;
    }

    public function button(int $handle, SDLGamepadButton $button): bool
    {
        return $this->buttons[$handle][$button->value] ?? false;
    }

    public function axis(int $handle, SDLGamepadAxis $axis): int
    {
        return $this->axes[$handle][$axis->value] ?? 0;
    }
}
