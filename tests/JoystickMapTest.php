<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Input\JoystickMap;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\HumanInput\Devices\GameController;
use Surface\HumanInput\Devices\GamePad;

it('takes as many default axes and buttons as the stick has, and the dpad from a hat', function (): void {
    expect(JoystickMap::axes(3))->toBe([GamepadAxis::LEFT_X, GamepadAxis::LEFT_Y, GamepadAxis::RIGHT_X])
        ->and(JoystickMap::axes(12))->toBe(JoystickMap::AXES)
        ->and(JoystickMap::buttons(9, 1))->toBe([
            GamepadButton::SOUTH, GamepadButton::EAST, GamepadButton::WEST, GamepadButton::NORTH,
            GamepadButton::LEFT_SHOULDER, GamepadButton::RIGHT_SHOULDER, GamepadButton::BACK, GamepadButton::START,
            GamepadButton::DPAD_UP, GamepadButton::DPAD_DOWN, GamepadButton::DPAD_LEFT, GamepadButton::DPAD_RIGHT,
        ])
        ->and(JoystickMap::buttons(2, 0))->toBe([GamepadButton::SOUTH, GamepadButton::EAST]);
});

it('builds a game controller when there is a stick, a game pad when not', function (): void {
    $stick = JoystickMap::device(inputFrame(), 'sdl3-js-1', 'Stick', 2, 4, 0);
    $buttons = JoystickMap::device(inputFrame(), 'sdl3-js-2', 'Buttons', 0, 4, 1);

    expect($stick)->toBeInstanceOf(GameController::class)
        ->and($stick->axes())->toBe([GamepadAxis::LEFT_X, GamepadAxis::LEFT_Y])
        ->and($buttons)->toBeInstanceOf(GamePad::class)
        ->and($buttons)->not->toBeInstanceOf(GameController::class)
        ->and($buttons->supports(GamepadButton::DPAD_LEFT))->toBeTrue()
        ->and([$buttons->id(), $buttons->name()])->toBe(['sdl3-js-2', 'Buttons']);
});

it('reads sticks from the centre and triggers from their resting minimum', function (): void {
    expect(JoystickMap::value(GamepadAxis::LEFT_X, 32767))->toBe(1.0)
        ->and(JoystickMap::value(GamepadAxis::LEFT_Y, 0))->toBe(0.0)
        ->and(JoystickMap::value(GamepadAxis::RIGHT_TRIGGER, -32768))->toBe(0.0)
        ->and(JoystickMap::value(GamepadAxis::RIGHT_TRIGGER, 32767))->toBe(1.0);
});

it('presses the dpad buttons a hat holds', function (): void {
    expect(JoystickMap::dpad(SDL_HAT_UP | SDL_HAT_LEFT))->toBe(['dpad_up' => true, 'dpad_down' => false, 'dpad_left' => true, 'dpad_right' => false])
        ->and(JoystickMap::dpad(SDL_HAT_CENTERED))->toBe(['dpad_up' => false, 'dpad_down' => false, 'dpad_left' => false, 'dpad_right' => false]);
});
