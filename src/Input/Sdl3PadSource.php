<?php

namespace Jovian\Engines\Sdl3\Input;

use Closure;
use SDL_Gamepad;
use SDL_Joystick;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\Contracts\HumanInput\HumanInputException;
use Surface\Contracts\HumanInput\PadSource;
use Surface\HumanInput\Devices\GameController;
use Surface\HumanInput\Devices\GamePad;
use Surface\HumanInput\InputFrame;
use ValueError;

/**
 * SDL's game pads and joysticks, read by polling: no window and no event needed.
 * Gamepads (SDL has a mapping) are `sdl3-<instance>` game controllers with every button
 * and axis; other joysticks are `sdl3-js-<instance>` under JoystickMap's default map.
 * SDL's joystick and gamepad events are off while this runs: state is read after
 * SDL_UpdateGamepads(), and an event nobody drains piles up in SDL's queue on every
 * update (measured on 3.2.10 and 3.4.4). Pads report while no SDL window has focus, and
 * with no SDL window or video at all: they belong to the process. HumanInput's 'sdl3'
 * pad source. A pad's hardware id is SDL's serial, or on Linux its input node's uniq when
 * SDL has none (null when neither); its player lights are SDL's player index.
 */
final class Sdl3PadSource implements PadSource
{
    private bool $up = false;

    /** @var array<int, SDL_Gamepad> by instance id */
    private array $gamepads = [];

    /** @var array<int, GameController> by instance id */
    private array $pad_devices = [];

    /** @var array<int, SDL_Joystick> by instance id */
    private array $joysticks = [];

    /** @var array<int, GamePad> by instance id */
    private array $stick_devices = [];

    /** @var array<int, array{int, int, int}> axes, buttons, hats by instance id */
    private array $shapes = [];

    /** @var array<int, string> instance ids left closed, by the hardware id an open device already has */
    private array $twins = [];

    /** @var Closure(SDL_Gamepad|SDL_Joystick, int): ?string */
    private readonly Closure $serial;

    /** @param (Closure(SDL_Gamepad|SDL_Joystick, int): ?string)|null $serial a device's serial by handle and instance id; SDL's by default */
    public function __construct(private readonly InputFrame $frame, ?Closure $serial = null)
    {
        $this->serial = $serial ?? static fn (SDL_Gamepad|SDL_Joystick $handle): ?string => $handle instanceof SDL_Gamepad
            ? (SDL_GetGamepadSerial($handle) ?: self::uniq(SDL_GetGamepadPath($handle)))
            : (SDL_GetJoystickSerial($handle) ?: self::uniq(SDL_GetJoystickPath($handle)));
    }

    /**
     * A Linux input node's uniq (a Bluetooth pad's address, which the kernel driver also sets
     * over USB), for a pad SDL reads through evdev: without access to /dev/hidraw SDL has no
     * serial for it, and sysfs is readable by every user. Null for any other path, or empty.
     */
    public static function uniq(?string $path, string $sysfs = '/sys/class/input'): ?string
    {
        if (is_null($path) || preg_match('#^/dev/input/(event\d+)$#', $path, $node) !== 1) {
            return null;
        }
        $file = "{$sysfs}/{$node[1]}/device/uniq";
        $uniq = is_readable($file) ? trim((string) file_get_contents($file)) : '';

        return $uniq === '' ? null : $uniq;
    }

    /** @throws HumanInputException SDL's gamepad subsystem would not start. */
    public function connect(): static
    {
        if ($this->up) {
            return $this;
        }

        SDL_SetHint(SDL_HINT_JOYSTICK_ALLOW_BACKGROUND_EVENTS, '1');
        if (! SDL_InitSubSystem(SDL_INIT_GAMEPAD)) {
            throw new HumanInputException('sdl3: the gamepad subsystem would not start: '.SDL_GetError());
        }
        self::events(false);
        $this->up = true;

        return $this;
    }

    public function connected(): bool
    {
        return $this->up;
    }

    public function disconnect(): void
    {
        if (! $this->up) {
            return;
        }

        foreach ($this->gamepads as $pad) {
            self::close(fn () => SDL_CloseGamepad($pad));
        }
        foreach ($this->joysticks as $stick) {
            self::close(fn () => SDL_CloseJoystick($stick));
        }
        [$this->gamepads, $this->pad_devices, $this->joysticks, $this->stick_devices, $this->shapes, $this->twins] = [[], [], [], [], [], []];
        self::events(true);
        SDL_QuitSubSystem(SDL_INIT_GAMEPAD);
        $this->up = false;
    }

    public function settle(): void
    {
        foreach ([...$this->pad_devices, ...$this->stick_devices] as $device) {
            $device->settle();
        }
    }

    public function poll(): void
    {
        if (! $this->up) {
            return;
        }

        SDL_UpdateGamepads();
        $this->follow();

        foreach ($this->pad_devices as $id => $device) {
            $pad = $this->gamepads[$id];
            foreach (GamepadButton::cases() as $button) {
                $device->update($button, SDL_GetGamepadButton($pad, self::sdlButton($button)));
            }
            foreach (GamepadAxis::cases() as $axis) {
                $device->setAxis($axis, SDL_GetGamepadAxis($pad, self::sdlAxis($axis)) / 32767);
            }
        }

        foreach ($this->stick_devices as $id => $device) {
            $stick = $this->joysticks[$id];
            [$axes, $buttons, $hats] = $this->shapes[$id];
            if ($device instanceof GameController) {
                foreach (JoystickMap::axes($axes) as $index => $axis) {
                    $device->setAxis($axis, JoystickMap::value($axis, SDL_GetJoystickAxis($stick, $index)));
                }
            }
            foreach (array_slice(JoystickMap::BUTTONS, 0, max(0, $buttons)) as $index => $button) {
                $device->update($button, SDL_GetJoystickButton($stick, $index));
            }
            if ($hats > 0) {
                foreach (JoystickMap::dpad(SDL_GetJoystickHat($stick, 0)) as $button => $down) {
                    $device->update(GamepadButton::from($button), $down);
                }
            }
        }
    }

    /** @return array<string, GamePad> joysticks without sticks, by id */
    public function gamePads(): array
    {
        $pads = [];
        foreach ($this->stick_devices as $device) {
            if (! $device instanceof GameController) {
                $pads[$device->id()] = $device;
            }
        }

        return $pads;
    }

    /** @return array<string, GameController> gamepads, then joysticks with a stick, by id */
    public function gameControllers(): array
    {
        $controllers = [];
        foreach ([...$this->pad_devices, ...$this->stick_devices] as $device) {
            if ($device instanceof GameController) {
                $controllers[$device->id()] = $device;
            }
        }

        return $controllers;
    }

    /**
     * Close what SDL no longer lists; open what it lists that is not open. A device whose
     * serial an open device already has is the same pad under a second SDL driver (macOS
     * lists a hot-plugged DualSense through HIDAPI and again through another driver, and
     * may later drop either): it stays closed while the first is listed, and when the
     * first goes it takes over the same Surface device, id and edges, with no mail.
     */
    private function follow(): void
    {
        $pads = SDL_GetGamepads();
        $sticks = array_values(array_filter(SDL_GetJoysticks(), fn (int $id): bool => ! SDL_IsGamepad($id)));
        $this->twins = array_intersect_key($this->twins, array_flip([...$pads, ...$sticks]));

        foreach (array_diff(array_keys($this->gamepads), $pads) as $gone) {
            [$pad, $device] = [$this->gamepads[$gone], $this->pad_devices[$gone]];
            self::close(fn () => SDL_CloseGamepad($pad));
            unset($this->gamepads[$gone], $this->pad_devices[$gone]);
            $twin = $this->twinOf($device, $pads);
            if (! is_null($twin) && ! is_null($handle = SDL_OpenGamepad($twin))) {
                [$this->gamepads[$twin], $this->pad_devices[$twin]] = [$handle, $device];
                unset($this->twins[$twin]);
            }
        }
        foreach (array_diff(array_keys($this->joysticks), $sticks) as $gone) {
            [$stick, $device, $shape] = [$this->joysticks[$gone], $this->stick_devices[$gone], $this->shapes[$gone]];
            self::close(fn () => SDL_CloseJoystick($stick));
            unset($this->joysticks[$gone], $this->stick_devices[$gone], $this->shapes[$gone]);
            $twin = $this->twinOf($device, $sticks);
            if (! is_null($twin) && ! is_null($handle = SDL_OpenJoystick($twin))) {
                [$this->joysticks[$twin], $this->stick_devices[$twin], $this->shapes[$twin]] = [$handle, $device, $shape];
                unset($this->twins[$twin]);
            }
        }

        foreach ($pads as $id) {
            if (isset($this->gamepads[$id]) || isset($this->twins[$id]) || is_null($pad = SDL_OpenGamepad($id))) {
                continue;
            }
            $hardware_id = self::hardwareId(($this->serial)($pad, $id));
            if (! is_null($twin = $this->holding($hardware_id))) {
                $this->twins[$id] = $twin;
                SDL_CloseGamepad($pad);

                continue;
            }
            $this->gamepads[$id] = $pad;
            $this->pad_devices[$id] = new GameController(
                $this->frame,
                "sdl3-{$id}",
                SDL_GetGamepadName($pad) ?? "SDL gamepad {$id}",
                GamepadButton::cases(),
                GamepadAxis::cases(),
                $hardware_id,
                fn (?int $index) => $this->light("sdl3-{$id}", $index),
            );
        }

        foreach ($sticks as $id) {
            if (isset($this->joysticks[$id]) || isset($this->twins[$id]) || is_null($stick = SDL_OpenJoystick($id))) {
                continue;
            }
            $hardware_id = self::hardwareId(($this->serial)($stick, $id));
            if (! is_null($twin = $this->holding($hardware_id))) {
                $this->twins[$id] = $twin;
                SDL_CloseJoystick($stick);

                continue;
            }
            $shape = [SDL_GetNumJoystickAxes($stick), SDL_GetNumJoystickButtons($stick), SDL_GetNumJoystickHats($stick)];
            $this->joysticks[$id] = $stick;
            $this->shapes[$id] = $shape;
            $this->stick_devices[$id] = JoystickMap::device(
                $this->frame,
                "sdl3-js-{$id}",
                SDL_GetJoystickName($stick) ?? "SDL joystick {$id}",
                ...$shape,
                hardware_id: $hardware_id,
                player_lights: fn (?int $index) => $this->light("sdl3-js-{$id}", $index),
            );
        }
    }

    /** The hardware id of an open device, if one has it: the twin it would duplicate. */
    private function holding(?string $hardware_id): ?string
    {
        if (is_null($hardware_id)) {
            return null;
        }
        foreach ([...$this->pad_devices, ...$this->stick_devices] as $device) {
            if ($device->hardwareId() === $hardware_id) {
                return $hardware_id;
            }
        }

        return null;
    }

    /** @param list<int> $listed a listed, closed instance id with $device's hardware id, if any */
    private function twinOf(GamePad $device, array $listed): ?int
    {
        foreach ($listed as $id) {
            if (! is_null($device->hardwareId()) && ($this->twins[$id] ?? null) === $device->hardwareId()) {
                return $id;
            }
        }

        return null;
    }

    /** SDL's player index on whichever handle the device with $device_id reads through now; −1 clears it. */
    private function light(string $device_id, ?int $index): void
    {
        foreach ($this->pad_devices as $id => $device) {
            if ($device->id() === $device_id) {
                SDL_SetGamepadPlayerIndex($this->gamepads[$id], $index ?? -1);
            }
        }
        foreach ($this->stick_devices as $id => $device) {
            if ($device->id() === $device_id) {
                SDL_SetJoystickPlayerIndex($this->joysticks[$id], $index ?? -1);
            }
        }
    }

    /** Every joystick and gamepad event type: SDL_EventType numbers them 0x600 up to touch's 0x700. */
    private static function events(bool $enabled): void
    {
        for ($type = SDL_EVENT_JOYSTICK_AXIS_MOTION; $type < SDL_EVENT_FINGER_DOWN; $type++) {
            SDL_SetEventEnabled($type, $enabled);
        }
    }

    /**
     * A serial as a hardware id: a Bluetooth address in one spelling (SDL's drivers spell
     * one pad's '4c-b9-9b-49-be-4a' and '4C:B9:9B:49:BE:4A'), any other serial as given,
     * null when SDL has none.
     */
    private static function hardwareId(?string $serial): ?string
    {
        if ($serial === null || $serial === '') {
            return null;
        }
        if (preg_match('/^[0-9a-f]{2}([-:]?[0-9a-f]{2}){5}$/i', $serial) === 1) {
            return implode(':', str_split(strtolower((string) preg_replace('/[-:]/', '', $serial)), 2));
        }

        return $serial;
    }

    /** A close, unless SDL_Quit already closed the device and released its handle. */
    private static function close(callable $close): void
    {
        try {
            $close();
        } catch (ValueError) {
            // The handle was released when SDL took the joystick subsystem down: nothing left to close.
        }
    }

    private static function sdlButton(GamepadButton $button): int
    {
        return match ($button) {
            GamepadButton::SOUTH => SDL_GAMEPAD_BUTTON_SOUTH,
            GamepadButton::EAST => SDL_GAMEPAD_BUTTON_EAST,
            GamepadButton::WEST => SDL_GAMEPAD_BUTTON_WEST,
            GamepadButton::NORTH => SDL_GAMEPAD_BUTTON_NORTH,
            GamepadButton::BACK => SDL_GAMEPAD_BUTTON_BACK,
            GamepadButton::GUIDE => SDL_GAMEPAD_BUTTON_GUIDE,
            GamepadButton::START => SDL_GAMEPAD_BUTTON_START,
            GamepadButton::LEFT_STICK => SDL_GAMEPAD_BUTTON_LEFT_STICK,
            GamepadButton::RIGHT_STICK => SDL_GAMEPAD_BUTTON_RIGHT_STICK,
            GamepadButton::LEFT_SHOULDER => SDL_GAMEPAD_BUTTON_LEFT_SHOULDER,
            GamepadButton::RIGHT_SHOULDER => SDL_GAMEPAD_BUTTON_RIGHT_SHOULDER,
            GamepadButton::DPAD_UP => SDL_GAMEPAD_BUTTON_DPAD_UP,
            GamepadButton::DPAD_DOWN => SDL_GAMEPAD_BUTTON_DPAD_DOWN,
            GamepadButton::DPAD_LEFT => SDL_GAMEPAD_BUTTON_DPAD_LEFT,
            GamepadButton::DPAD_RIGHT => SDL_GAMEPAD_BUTTON_DPAD_RIGHT,
        };
    }

    private static function sdlAxis(GamepadAxis $axis): int
    {
        return match ($axis) {
            GamepadAxis::LEFT_X => SDL_GAMEPAD_AXIS_LEFTX,
            GamepadAxis::LEFT_Y => SDL_GAMEPAD_AXIS_LEFTY,
            GamepadAxis::RIGHT_X => SDL_GAMEPAD_AXIS_RIGHTX,
            GamepadAxis::RIGHT_Y => SDL_GAMEPAD_AXIS_RIGHTY,
            GamepadAxis::LEFT_TRIGGER => SDL_GAMEPAD_AXIS_LEFT_TRIGGER,
            GamepadAxis::RIGHT_TRIGGER => SDL_GAMEPAD_AXIS_RIGHT_TRIGGER,
        };
    }
}
