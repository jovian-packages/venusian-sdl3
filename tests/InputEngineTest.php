<?php

declare(strict_types=1);

use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;

/*
 * The engine over the real SDL session, fed with SDL_PushEvent: the session pumps
 * the events as the loop's poller does and shows each to the engine. The windows
 * are hidden and unfocusable, so nothing real reaches them.
 */

afterEach(function (): void {
    letGoOfInput();
    stager()->closeAllStaged();
});

it('reads keys, text and modifiers, a repeat changing nothing', function (): void {
    $window = inputWindow();
    $engine = sdl3Input();

    deliver($engine,
        keyEvent($window, SDL_SCANCODE_W, true, SDL_KMOD_LSHIFT),
        keyEvent($window, SDL_SCANCODE_W, true, SDL_KMOD_LSHIFT, repeat: true),
        textEvent($window, 'W'),
    );

    expect($engine->keyboard()->pressedKeys())->toBe([Key::W])
        ->and($engine->keyboard()->text())->toBe('W')
        ->and($engine->keyboard()->modifiers())->toEqual(new Modifiers(shift: true));

    deliver($engine, keyEvent($window, SDL_SCANCODE_W, false));

    expect($engine->keyboard()->isDown(Key::W))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::W))->toBeTrue()
        ->and($engine->keyboard()->modifiers())->toEqual(new Modifiers());
});

it('copies each event as the session pumps it, though the session reuses one SDL_Event', function (): void {
    $window = inputWindow();
    $engine = sdl3Input();

    deliver($engine, keyEvent($window, SDL_SCANCODE_A, true), keyEvent($window, SDL_SCANCODE_B, true));

    expect($engine->keyboard()->downKeys())->toBe([Key::A, Key::B]);
});

it('ignores events for windows the session does not hold', function (): void {
    inputWindow();
    $engine = sdl3Input();
    $stray = new SDL_Event();
    $stray->type = SDL_EVENT_KEY_DOWN;
    $stray->key->windowID = 999_999;
    $stray->key->scancode = SDL_SCANCODE_Q;
    $stray->key->down = true;

    deliver($engine, $stray);

    expect($engine->keyboard()->downKeys())->toBe([]);
});

it('tracks the pointer, its window, motion and buttons', function (): void {
    $window = inputWindow('pointer');
    $engine = sdl3Input();

    deliver($engine, motionEvent($window, 10.0, 20.0, 3.0, -1.0), buttonEvent($window, SDL_BUTTON_RIGHT, true, 11.0, 20.0));
    $mouse = $engine->mouse();

    expect([$mouse->x(), $mouse->y(), $mouse->window()])->toBe([11.0, 20.0, 'pointer'])
        ->and($mouse->motion())->toBe(['dx' => 3.0, 'dy' => -1.0])
        ->and($mouse->isPressed(MouseButton::RIGHT))->toBeTrue();
});

it('reads SDL buttons 1 to 5 as left, middle, right, x1, x2', function (int $sdl, MouseButton $button): void {
    $window = inputWindow();
    $engine = sdl3Input();

    deliver($engine, buttonEvent($window, $sdl, true));

    expect($engine->mouse()->isDown($button))->toBeTrue();
})->with([
    [SDL_BUTTON_LEFT, MouseButton::LEFT],
    [SDL_BUTTON_MIDDLE, MouseButton::MIDDLE],
    [SDL_BUTTON_RIGHT, MouseButton::RIGHT],
    [SDL_BUTTON_X1, MouseButton::X1],
    [SDL_BUTTON_X2, MouseButton::X2],
]);

it('turns a flipped wheel round, so dy > 0 is always rolled away from the user', function (): void {
    $window = inputWindow();
    $engine = sdl3Input();

    deliver($engine, wheelEvent($window, 2.0, 1.0, SDL_MOUSEWHEEL_FLIPPED), wheelEvent($window, 0.5, 3.0));

    expect($engine->mouse()->wheel())->toBe(['dx' => -1.5, 'dy' => 2.0]);
});

it('forgets the window the pointer left and keeps where it was', function (): void {
    $window = inputWindow('left');
    $engine = sdl3Input();

    deliver($engine, motionEvent($window, 5.0, 6.0, 0.0, 0.0), windowSignal($window, SDL_EVENT_WINDOW_MOUSE_LEAVE));

    expect([$engine->mouse()->x(), $engine->mouse()->y(), $engine->mouse()->window()])->toBe([5.0, 6.0, null]);
});

it('releases every key and button when focus leaves the session\'s windows', function (): void {
    $window = inputWindow();
    $engine = sdl3Input(focus: fn (): ?int => null);

    deliver($engine, keyEvent($window, SDL_SCANCODE_D, true), buttonEvent($window, SDL_BUTTON_LEFT, true));
    deliver($engine, windowSignal($window, SDL_EVENT_WINDOW_FOCUS_LOST));

    expect($engine->keyboard()->isDown(Key::D))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::D))->toBeTrue()
        ->and($engine->mouse()->isDown(MouseButton::LEFT))->toBeFalse();
});

it('keeps keys and buttons down when focus moves to another of the session\'s windows', function (): void {
    $one = inputWindow('one');
    $two = inputWindow('two');
    $engine = sdl3Input(focus: fn (): ?int => SDL_GetWindowID($two->native()));

    deliver($engine, keyEvent($one, SDL_SCANCODE_D, true), buttonEvent($two, SDL_BUTTON_LEFT, true));
    deliver($engine, windowSignal($one, SDL_EVENT_WINDOW_FOCUS_LOST));

    expect($engine->keyboard()->isDown(Key::D))->toBeTrue()
        ->and($engine->mouse()->isDown(MouseButton::LEFT))->toBeTrue();
});

it('starts text input on every window the session holds and stops it on disconnect', function (): void {
    $window = inputWindow();
    $engine = sdl3Input();

    $engine->poll();

    expect(SDL_TextInputActive($window->native()))->toBeTrue();

    $engine->disconnect();

    expect(SDL_TextInputActive($window->native()))->toBeFalse();
});

it('sees nothing once disconnected, and taps again when reconnected', function (): void {
    $window = inputWindow();
    $engine = sdl3Input();
    $engine->disconnect();

    deliver($engine, keyEvent($window, SDL_SCANCODE_K, true));

    expect($engine->connected())->toBeFalse()
        ->and($engine->keyboard()->downKeys())->toBe([]);

    $engine->connect();
    deliver($engine, keyEvent($window, SDL_SCANCODE_K, true));

    expect($engine->keyboard()->downKeys())->toBe([Key::K]);
});

it('keeps edges until settled, and settles keyboard and mouse together', function (): void {
    $window = inputWindow();
    $engine = sdl3Input();

    deliver($engine, keyEvent($window, SDL_SCANCODE_J, true), keyEvent($window, SDL_SCANCODE_J, false), wheelEvent($window, 0.0, 1.0));
    $engine->poll();

    expect($engine->keyboard()->isPressed(Key::J))->toBeTrue()
        ->and($engine->keyboard()->wasReleased(Key::J))->toBeTrue();

    $engine->settle();

    expect($engine->keyboard()->isPressed(Key::J))->toBeFalse()
        ->and($engine->mouse()->wheel())->toBe(['dx' => 0.0, 'dy' => 0.0]);
});

it('lists no pads: SDL\'s pads are the sdl3 pad source', function (): void {
    expect(sdl3Input())->not->toBeInstanceOf(Surface\Contracts\HumanInput\PadSource::class);
});

it('refuses to connect while its driver has no connected session, naming the toolkit', function (): void {
    $engine = new Jovian\Engines\Sdl3\Input\Sdl3InputEngine(inputFrame(), new Jovian\Engines\Sdl3\Bridge\Sdl3BridgeDriver(new Voyager\Vessel\ControlPanel()));

    expect(fn () => $engine->connect())->toThrow(Surface\Contracts\HumanInput\HumanInputException::class, "sdl3: no connected SDL session to read input from")
        ->and($engine->connected())->toBeFalse();
});
