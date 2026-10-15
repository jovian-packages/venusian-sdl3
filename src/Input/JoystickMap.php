<?php

namespace Jovian\Engines\Sdl3\Input;

use Closure;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\HumanInput\Devices\GameController;
use Surface\HumanInput\Devices\GamePad;
use Surface\HumanInput\InputFrame;

/**
 * The default map for a joystick SDL has no gamepad mapping for: axis i reads as AXES[i],
 * button i as BUTTONS[i], hat 0 as the dpad. A stick with LEFT_X or RIGHT_X is a game
 * controller, otherwise a game pad.
 */
final class JoystickMap
{
    /** @var list<GamepadAxis> by SDL joystick axis index */
    public const array AXES = [
        GamepadAxis::LEFT_X, GamepadAxis::LEFT_Y, GamepadAxis::RIGHT_X,
        GamepadAxis::RIGHT_Y, GamepadAxis::LEFT_TRIGGER, GamepadAxis::RIGHT_TRIGGER,
    ];

    /** @var list<GamepadButton> by SDL joystick button index */
    public const array BUTTONS = [
        GamepadButton::SOUTH, GamepadButton::EAST, GamepadButton::WEST, GamepadButton::NORTH,
        GamepadButton::LEFT_SHOULDER, GamepadButton::RIGHT_SHOULDER, GamepadButton::BACK, GamepadButton::START,
    ];

    /** @return list<GamepadAxis> the axes a stick with $count axes reads as */
    public static function axes(int $count): array
    {
        return array_slice(self::AXES, 0, max(0, $count));
    }

    /** @return list<GamepadButton> the buttons a stick with $buttons buttons and $hats hats has */
    public static function buttons(int $buttons, int $hats): array
    {
        $dpad = $hats > 0 ? [GamepadButton::DPAD_UP, GamepadButton::DPAD_DOWN, GamepadButton::DPAD_LEFT, GamepadButton::DPAD_RIGHT] : [];

        return [...array_slice(self::BUTTONS, 0, max(0, $buttons)), ...$dpad];
    }

    public static function device(InputFrame $frame, string $id, string $name, int $axes, int $buttons, int $hats, ?string $hardware_id = null, ?Closure $player_lights = null): GamePad
    {
        $mapped = self::axes($axes);
        $pressed = self::buttons($buttons, $hats);

        return in_array(GamepadAxis::LEFT_X, $mapped, true) || in_array(GamepadAxis::RIGHT_X, $mapped, true)
            ? new GameController($frame, $id, $name, $pressed, $mapped, $hardware_id, $player_lights)
            : new GamePad($frame, $id, $name, $pressed, $hardware_id, $player_lights);
    }

    /** A raw SDL axis (−32768…32767) as the axis reads: sticks from the centre, triggers from their resting minimum. */
    public static function value(GamepadAxis $axis, int $raw): float
    {
        return in_array($axis, [GamepadAxis::LEFT_TRIGGER, GamepadAxis::RIGHT_TRIGGER], true)
            ? ($raw + 32768) / 65535
            : $raw / 32767;
    }

    /** @return array<string, bool> each dpad button by GamepadButton value, down when the hat holds it */
    public static function dpad(int $hat): array
    {
        return [
            GamepadButton::DPAD_UP->value => ($hat & SDL_HAT_UP) !== 0,
            GamepadButton::DPAD_DOWN->value => ($hat & SDL_HAT_DOWN) !== 0,
            GamepadButton::DPAD_LEFT->value => ($hat & SDL_HAT_LEFT) !== 0,
            GamepadButton::DPAD_RIGHT->value => ($hat & SDL_HAT_RIGHT) !== 0,
        ];
    }
}
