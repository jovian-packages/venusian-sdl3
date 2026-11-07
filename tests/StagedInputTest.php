<?php

declare(strict_types=1);

use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Mail\Staged\StagedEntered;
use Surface\Contracts\Windows\Mail\Staged\StagedKeyPressed;
use Surface\Contracts\Windows\Mail\Staged\StagedKeyReleased;
use Surface\Contracts\Windows\Mail\Staged\StagedLeft;
use Surface\Contracts\Windows\Mail\Staged\StagedTextCommitted;

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

    $mail = feed(keyEvent($quiet, SDL_SCANCODE_A, true), keyEvent($other, SDL_SCANCODE_A, true));

    expect(array_map(fn ($m) => $m->window(), $mail))->toBe(['other'])
        ->and($quiet_id)->not->toBe(SDL_GetWindowID($other->native()));
});
