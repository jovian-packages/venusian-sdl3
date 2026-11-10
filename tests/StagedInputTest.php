<?php

declare(strict_types=1);

use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\Staged\StagedDragged;
use Surface\Contracts\Windows\Mail\Staged\StagedEntered;
use Surface\Contracts\Windows\Mail\Staged\StagedKeyPressed;
use Surface\Contracts\Windows\Mail\Staged\StagedKeyReleased;
use Surface\Contracts\Windows\Mail\Staged\StagedLeft;
use Surface\Contracts\Windows\Mail\Staged\StagedMoved;
use Surface\Contracts\Windows\Mail\Staged\StagedPressed;
use Surface\Contracts\Windows\Mail\Staged\StagedReleased;
use Surface\Contracts\Windows\Mail\Staged\StagedTextCommitted;
use Surface\Contracts\Windows\Mail\Staged\StagedEditCommanded;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\Staged\StagedWheeled;

/*
 * The staged window's input mail from real SDL events, pushed and pumped as the
 * loop's poller does. The windows are hidden and unfocusable, so nothing real
 * reaches them.
 */

afterEach(fn () => stager()->closeAllStaged());

/** Pushes the events, pumps the session once, and takes the mail. */
function feed(SDL_Event ...$events): array
{
    $session = stager()->connect();
    $session->pump(0);
    takeMail();
    foreach ($events as $event) {
        SDL_PushEvent($event) || throw new RuntimeException('SDL_PushEvent: '.SDL_GetError());
    }
    $session->pump(0);
    $session->flushLatest();

    return takeMail();
}

function clicked(SDL_Event $event, int $clicks): SDL_Event
{
    $event->button->clicks = $clicks;

    return $event;
}

it('posts presses with the native click count, drags, and releases', function (): void {
    $window = inputWindow('in')->watchInput();

    $mail = feed(
        clicked(buttonEvent($window, SDL_BUTTON_LEFT, true, 3.5, 4.0), 2),
        motionEvent($window, 5.0, 6.0, 1.5, 2.0),
        buttonEvent($window, SDL_BUTTON_LEFT, false, 5.0, 6.0),
        clicked(buttonEvent($window, SDL_BUTTON_RIGHT, true, 1.0, 1.0), 1),
    );
    $none = new Modifiers();

    expect($mail)->toEqual([
        new StagedPressed('in', 3.5, 4.0, MouseButton::LEFT, 2, $none),
        new StagedDragged('in', 5.0, 6.0, MouseButton::LEFT, $none),
        new StagedReleased('in', 5.0, 6.0, MouseButton::LEFT, $none),
        new StagedPressed('in', 1.0, 1.0, MouseButton::RIGHT, 1, $none),
    ]);
});

it('posts a move with no button down as latest mail', function (): void {
    $window = inputWindow('in')->watchInput();

    $mail = feed(motionEvent($window, 1.0, 1.0, 1.0, 1.0), motionEvent($window, 2.0, 3.0, 1.0, 2.0));

    expect($mail)->toEqual([new StagedMoved('in', 2.0, 3.0, new Modifiers())]);
});

it('posts a wheel, a flipped one turned round', function (): void {
    $window = inputWindow('in')->watchInput();

    expect(feed(wheelEvent($window, 2.0, 1.0, SDL_MOUSEWHEEL_FLIPPED), wheelEvent($window, 0.5, 3.0)))->toEqual([
        new StagedWheeled('in', -2.0, -1.0, false, new Modifiers()),
        new StagedWheeled('in', 0.5, 3.0, false, new Modifiers()),
    ]);
});

it('posts the pointer entering and leaving', function (): void {
    $window = inputWindow('in')->watchInput();

    expect(feed(windowSignal($window, SDL_EVENT_WINDOW_MOUSE_ENTER), windowSignal($window, SDL_EVENT_WINDOW_MOUSE_LEAVE)))
        ->toEqual([new StagedEntered('in'), new StagedLeft('in')]);
});

/** A key event as SDL fills it: the keycode of a printable key is its character. */
function typed(SDL_Event $event, string $character): SDL_Event
{
    $event->key->key = mb_ord($character);

    return $event;
}

it('posts keys with their modifiers, a repeat flagged, and committed text', function (): void {
    $window = inputWindow('in')->watchInput();
    $shift = new Modifiers(shift: true);

    $mail = feed(
        typed(keyEvent($window, SDL_SCANCODE_W, true, SDL_KMOD_LSHIFT), 'w'),
        typed(keyEvent($window, SDL_SCANCODE_W, true, SDL_KMOD_LSHIFT, repeat: true), 'w'),
        textEvent($window, 'W'),
        keyEvent($window, SDL_SCANCODE_W, false, SDL_KMOD_LSHIFT),
    );

    expect($mail)->toEqual([
        new StagedKeyPressed('in', Key::W, 'w', $shift, false),
        new StagedKeyPressed('in', Key::W, 'w', $shift, true),
        new StagedTextCommitted('in', 'W'),
        new StagedKeyReleased('in', Key::W, $shift),
    ]);
});

it('posts an editing key as its edit command after the key', function (): void {
    $window = inputWindow('in')->watchInput();
    $chord = PHP_OS_FAMILY === 'Darwin' ? SDL_KMOD_LGUI : SDL_KMOD_LCTRL;

    $edits = array_values(array_filter(
        feed(keyEvent($window, SDL_SCANCODE_LEFT, true), keyEvent($window, SDL_SCANCODE_V, true, $chord)),
        fn (object $mail): bool => $mail instanceof StagedEditCommanded,
    ));

    expect($edits)->toEqual([
        new StagedEditCommanded('in', EditCommand::MoveLeft),
        new StagedEditCommanded('in', EditCommand::Paste),
    ]);
});

it('posts no key character for a key with no text', function (): void {
    $window = inputWindow('in')->watchInput();

    $mail = feed(keyEvent($window, SDL_SCANCODE_LEFT, true));

    expect($mail[0]->key)->toBe(Key::LEFT)
        ->and($mail[0]->character)->toBe('');
});

it('posts nothing while the window does not watch input, and not for another window', function (): void {
    $quiet = inputWindow('quiet');
    $other = inputWindow('other')->watchInput();
    $quiet_id = SDL_GetWindowID($quiet->native());

    $mail = feed(buttonEvent($quiet, SDL_BUTTON_LEFT, true), keyEvent($quiet, SDL_SCANCODE_A, true), buttonEvent($other, SDL_BUTTON_LEFT, true), keyEvent($other, SDL_SCANCODE_A, true));

    expect(array_map(fn ($m) => $m->window(), $mail))->toBe(['other', 'other'])
        ->and($quiet_id)->not->toBe(SDL_GetWindowID($other->native()));
});
