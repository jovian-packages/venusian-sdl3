<?php

declare(strict_types=1);

use Jovian\Bindings\Sdl3\Enums\SDLEventType;
use Jovian\Bindings\Sdl3\Enums\SDLGamepadAxis;
use Jovian\Bindings\Sdl3\Enums\SDLGamepadButton;
use Jovian\Venusian\Sdl3\Events\SdlEventPump;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3InputException;
use Jovian\Venusian\Sdl3\Input\Sdl3InputEngine;
use Jovian\Venusian\Sdl3\Sessions\SdlStageSession;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\Contracts\HumanInput\InputEngine;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\Tests\Support\FakeGamepadReader;

/** @return array{Sdl3InputEngine, FakeGamepadReader} over an empty queue unless a pump is given; no mouse focus unless one is given */
function sdlEngine(?FakeGamepadReader $reader = null, bool $init = true, ?SdlEventPump $pump = null, ?Closure $mouse_focus = null): array
{
    $reader ??= new FakeGamepadReader();
    $pump ??= new SdlEventPump(poll: fn () => null, read: fn () => [], free: fn () => null);
    $engine = new Sdl3InputEngine(
        $pump,
        new SdlStageSession($pump),
        $reader,
        init: fn (): bool => $init,
        start_text: fn (int $w): bool => true,
        stop_text: fn (int $w): bool => true,
        mouse_focus: $mouse_focus ?? fn (): int => 0,
    );

    return [$engine, $reader];
}

/**
 * A pump whose queue is refilled per poll: each drain yields the next batch of decoded payloads.
 *
 * @param list<list<array<string, mixed>>> $batches payloads, each with its SDLEventType under 'type'
 */
function batchedPump(array $batches): SdlEventPump
{
    $queue = [];
    $payloads = [];
    $next = 1;

    return new SdlEventPump(
        poll: function () use (&$queue, &$batches, &$payloads, &$next): ?array {
            if ($queue === []) {
                if ($batches === []) {
                    return null;
                }

                foreach (array_shift($batches) as $payload) {
                    $payloads[$next] = $payload;
                    $queue[] = ['ptr' => $next++, 'event_type' => $payload['type']->value];
                }

                if ($queue === []) {   // an empty batch: this drain ends here
                    return null;
                }
            }

            return array_shift($queue);
        },
        read: function (int $ptr, string $key) use (&$payloads): array {
            $payload = $payloads[$ptr];
            unset($payload['type']);

            return $payload;
        },
        free: fn (int $ptr) => null,
    );
}

it('is the sdl3 engine, with no devices until connected', function () {
    [$engine] = sdlEngine();

    expect($engine->engine())->toBe(InputEngine::SDL3)
        ->and($engine->connected())->toBeFalse()
        ->and($engine->keyboard())->toBeNull()
        ->and($engine->gameControllers())->toBe([]);
});

it('refuses to connect when SDL will not start the gamepad subsystem', function () {
    [$engine] = sdlEngine(init: false);

    expect(fn () => $engine->connect())->toThrow(Sdl3InputException::class);
});

it('folds key, text and modifier events into the keyboard, ignoring repeats', function () {
    [$engine] = sdlEngine();
    $engine->connect();

    $engine->apply([
        ['kind' => 'key', 'window_id' => 1, 'scancode' => 26, 'down' => true, 'repeat' => false, 'mod' => 0x0001],
        ['kind' => 'key', 'window_id' => 1, 'scancode' => 26, 'down' => true, 'repeat' => true, 'mod' => 0x0001],
        ['kind' => 'text', 'window_id' => 1, 'text' => 'W'],
    ]);

    expect($engine->keyboard()->pressedKeys())->toBe([Key::W])
        ->and($engine->keyboard()->text())->toBe('W')
        ->and($engine->keyboard()->modifiers()->shift)->toBeTrue()
        ->and($engine->keyboard()->modifiers()->ctrl)->toBeFalse();
});

it('folds motion, buttons and wheel into the mouse', function () {
    [$engine] = sdlEngine();
    $engine->connect();

    $engine->apply([
        ['kind' => 'motion', 'window_id' => 1, 'x' => 40.0, 'y' => 30.0, 'xrel' => 4.0, 'yrel' => -2.0],
        ['kind' => 'button', 'window_id' => 1, 'button' => 3, 'down' => true, 'x' => 41.0, 'y' => 30.0],
        ['kind' => 'wheel', 'window_id' => 1, 'x' => 0.0, 'y' => 1.0, 'direction' => 0],
    ]);
    $mouse = $engine->mouse();

    expect([$mouse->x(), $mouse->y()])->toBe([41.0, 30.0])
        ->and($mouse->motion())->toBe(['dx' => 4.0, 'dy' => -2.0])
        ->and($mouse->wheel())->toBe(['dx' => 0.0, 'dy' => 1.0])
        ->and($mouse->isPressed(MouseButton::RIGHT))->toBeTrue()
        ->and($mouse->window())->toBeNull();   // window 1 is no stage of this session
});

it('negates a flipped wheel so dy > 0 is always away from the user', function () {
    [$engine] = sdlEngine();
    $engine->connect();

    $engine->apply([
        ['kind' => 'wheel', 'window_id' => 1, 'x' => 2.0, 'y' => 1.0, 'direction' => 1],   // SDL_MOUSEWHEEL_FLIPPED
        ['kind' => 'wheel', 'window_id' => 1, 'x' => 0.5, 'y' => 3.0, 'direction' => 0],   // SDL_MOUSEWHEEL_NORMAL
    ]);

    expect($engine->mouse()->wheel())->toBe(['dx' => -1.5, 'dy' => 2.0]);
});

it('reads no mouse window when SDL reports no focus, keeping the position', function () {
    $focus_reads = 0;
    $pump = batchedPump([[['type' => SDLEventType::MOUSE_MOTION, 'window_id' => 1, 'x' => 12.0, 'y' => 8.0, 'xrel' => 1.0, 'yrel' => 1.0]]]);
    [$engine] = sdlEngine(pump: $pump, mouse_focus: function () use (&$focus_reads): int {
        $focus_reads++;

        return 0;
    });
    $engine->connect()->poll();

    expect($engine->mouse()->window())->toBeNull()
        ->and([$engine->mouse()->x(), $engine->mouse()->y()])->toBe([12.0, 8.0])
        ->and($focus_reads)->toBe(1);
});

it('reads no mouse window when the focused window is no stage of this session', function () {
    [$engine] = sdlEngine(mouse_focus: fn (): int => 0xBEEF);
    $engine->connect()->poll();

    expect($engine->mouse()->window())->toBeNull();
});

it('settles a key press after one poll: pressed in the poll it lands, held after, text cleared', function () {
    $pump = batchedPump([
        [
            ['type' => SDLEventType::KEY_DOWN, 'window_id' => 1, 'scancode' => 44, 'key' => 32, 'mod' => 0, 'down' => true, 'repeat' => false],
            ['type' => SDLEventType::TEXT_INPUT, 'window_id' => 1, 'text' => ' '],
        ],
        [],
    ]);
    [$engine] = sdlEngine(pump: $pump);
    $engine->connect();

    $engine->poll();
    $keyboard = $engine->keyboard();
    expect($keyboard->isPressed(Key::SPACE))->toBeTrue()
        ->and($keyboard->isDown(Key::SPACE))->toBeTrue()
        ->and($keyboard->text())->toBe(' ');

    $engine->poll();
    expect($keyboard->isDown(Key::SPACE))->toBeTrue()
        ->and($keyboard->isPressed(Key::SPACE))->toBeFalse()
        ->and($keyboard->text())->toBe('');
});

it('opens every gamepad at connect and reads buttons and scaled axes each poll', function () {
    $reader = new FakeGamepadReader();
    $reader->plug(7, 'Xbox Wireless Controller');
    [$engine] = sdlEngine($reader);
    $engine->connect();
    $reader->buttons[7] = [SDLGamepadButton::SOUTH->value => true];
    $reader->axes[7] = [SDLGamepadAxis::LEFTX->value => 32767, SDLGamepadAxis::LEFTY->value => -32768, SDLGamepadAxis::RIGHT_TRIGGER->value => 16384];

    $engine->poll();
    $pad = $engine->gameControllers()['sdl3-7'];

    expect($pad->name())->toBe('Xbox Wireless Controller')
        ->and($reader->refreshes)->toBe(1)
        ->and($pad->isPressed(GamepadButton::SOUTH))->toBeTrue()
        ->and($pad->leftStick())->toBe(['x' => 1.0, 'y' => -1.0])
        ->and(round($pad->rightTrigger(), 2))->toBe(0.5)
        ->and($pad->axes())->toBe(GamepadAxis::cases())
        ->and($engine->gamePads())->toBe([]);
});

it('rescans when the pump says a gamepad came or went', function () {
    $reader = new FakeGamepadReader();
    [$engine] = sdlEngine($reader);
    $engine->connect();

    $reader->plug(3, 'Late Pad');
    $engine->apply([['kind' => 'gamepads']]);
    expect(array_keys($engine->gameControllers()))->toBe(['sdl3-3']);

    $reader->unplug(3);
    $engine->apply([['kind' => 'gamepads']]);
    expect($engine->gameControllers())->toBe([])->and($reader->closed)->toBe([3]);
});

it('closes every gamepad and stops wanting input on disconnect', function () {
    $reader = new FakeGamepadReader();
    $reader->plug(1, 'Pad');
    [$engine] = sdlEngine($reader);
    $engine->connect()->disconnect();

    expect($engine->connected())->toBeFalse()->and($reader->closed)->toBe([1])->and($engine->gameControllers())->toBe([]);
});
