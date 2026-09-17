<?php

declare(strict_types=1);

use Jovian\Bindings\Sdl3\Enums\SDLEventType;
use Jovian\Venusian\Sdl3\Events\SdlEventPump;

/** A pump over a scripted queue. $log records every read and free by ptr. */
function scriptedPump(array $queue, array &$log): SdlEventPump
{
    return new SdlEventPump(
        poll: function () use (&$queue): ?array {
            return array_shift($queue);
        },
        read: function (int $ptr, string $key) use (&$log): array {
            $log[] = "read:{$ptr}:{$key}";

            return ['window_id' => 9, 'ptr_was' => $ptr];
        },
        free: function (int $ptr) use (&$log): void {
            $log[] = "free:{$ptr}";
        },
    );
}

function ev(int $ptr, SDLEventType $type): array
{
    return ['ptr' => $ptr, 'event_type' => $type->value];
}

it('frees input events unread until someone wants input', function () {
    $log = [];
    $pump = scriptedPump([ev(1, SDLEventType::KEY_DOWN), ev(2, SDLEventType::MOUSE_MOTION)], $log);

    expect($pump->drain())->toBe(2)
        ->and($log)->toBe(['free:1', 'free:2'])
        ->and($pump->takeInput())->toBe([]);
});

it('decodes each input event once, tagged by kind, in order', function () {
    $log = [];
    $pump = scriptedPump([
        ev(1, SDLEventType::KEY_DOWN), ev(2, SDLEventType::KEY_UP), ev(3, SDLEventType::TEXT_INPUT),
        ev(4, SDLEventType::MOUSE_MOTION), ev(5, SDLEventType::MOUSE_BUTTON_DOWN), ev(6, SDLEventType::MOUSE_BUTTON_UP),
        ev(7, SDLEventType::MOUSE_WHEEL),
    ], $log);
    $pump->wantInput(true);

    $pump->drain();

    expect($log)->toBe(['read:1:key', 'read:2:key', 'read:3:text', 'read:4:motion', 'read:5:button', 'read:6:button', 'read:7:wheel'])
        ->and(array_column($pump->takeInput(), 'kind'))->toBe(['key', 'key', 'text', 'motion', 'button', 'button', 'wheel'])
        ->and($pump->takeInput())->toBe([]);
});

it('turns a gamepad arriving or leaving into one rescan marker, freed unread', function () {
    $log = [];
    $pump = scriptedPump([ev(1, SDLEventType::GAMEPAD_ADDED), ev(2, SDLEventType::GAMEPAD_REMOVED)], $log);
    $pump->wantInput(true);

    $pump->drain();

    expect($log)->toBe(['free:1', 'free:2'])
        ->and($pump->takeInput())->toBe([['kind' => 'gamepads'], ['kind' => 'gamepads']]);
});

it('hands quit and window events to the window router, and frees them when there is none', function () {
    $log = [];
    $routed = [];
    $pump = scriptedPump([ev(1, SDLEventType::QUIT), ev(2, SDLEventType::WINDOW_RESIZED)], $log);
    $pump->routeWindowsTo(function (int $ptr, int $type) use (&$routed): void {
        $routed[] = [$ptr, $type];
    });
    $pump->drain();

    $bare = scriptedPump([ev(3, SDLEventType::WINDOW_RESIZED)], $log);
    $bare->drain();

    expect($routed)->toBe([[1, SDLEventType::QUIT->value], [2, SDLEventType::WINDOW_RESIZED->value]])
        ->and($log)->toBe(['free:3']);
});

it('frees what nobody reads: joystick, sensor, audio', function () {
    $log = [];
    $pump = scriptedPump([ev(1, SDLEventType::JOYSTICK_AXIS_MOTION), ev(2, SDLEventType::GAMEPAD_AXIS_MOTION)], $log);
    $pump->wantInput(true);

    $pump->drain();

    expect($log)->toBe(['free:1', 'free:2'])->and($pump->takeInput())->toBe([]);
});

it('drains twice in one tick harmlessly', function () {
    $log = [];
    $pump = scriptedPump([ev(1, SDLEventType::KEY_DOWN)], $log);
    $pump->wantInput(true);

    expect($pump->drain())->toBe(1)->and($pump->drain())->toBe(0)
        ->and($pump->takeInput())->toHaveCount(1);
});

it('waits for the first event only when handed a wait, then drains the rest', function () {
    $log = [];
    $queue = [ev(2, SDLEventType::KEY_UP)];
    $waits = [];
    $pump = new SdlEventPump(
        poll: function () use (&$queue): ?array {
            return array_shift($queue);
        },
        read: function (int $ptr, string $key) use (&$log): array {
            $log[] = "read:{$ptr}:{$key}";

            return ['window_id' => 9];
        },
        free: function (int $ptr) use (&$log): void {
            $log[] = "free:{$ptr}";
        },
        wait: function (int $ms) use (&$waits): ?array {
            $waits[] = $ms;

            return ev(1, SDLEventType::KEY_DOWN);
        },
    );
    $pump->wantInput(true);

    expect($pump->drain())->toBe(1)
        ->and($waits)->toBe([]);

    $queue = [ev(2, SDLEventType::KEY_UP)];
    expect($pump->drain(16))->toBe(2)
        ->and($waits)->toBe([16])
        ->and($log)->toBe(['read:2:key', 'read:1:key', 'read:2:key']);
});

it('returns quietly when the wait times out', function () {
    $pump = new SdlEventPump(poll: fn () => null, read: fn () => [], free: fn () => null, wait: fn (int $ms): ?array => null);

    expect($pump->drain(16))->toBe(0);
});
