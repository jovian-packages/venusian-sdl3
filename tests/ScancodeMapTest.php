<?php

declare(strict_types=1);

use Jovian\Bindings\Sdl3\Enums\SDLScancode;
use Jovian\Venusian\Sdl3\Input\ScancodeMap;
use Surface\Contracts\HumanInput\Key;

it('maps SDL scancodes to Surface keys', function (SDLScancode $code, Key $key) {
    expect(ScancodeMap::key($code->value))->toBe($key);
})->with([
    [SDLScancode::A, Key::A], [SDLScancode::Z, Key::Z], [SDLScancode::W, Key::W],
    [SDLScancode::RETURN, Key::ENTER], [SDLScancode::ESCAPE, Key::ESCAPE], [SDLScancode::SPACE, Key::SPACE],
    [SDLScancode::UP, Key::UP], [SDLScancode::LEFT, Key::LEFT], [SDLScancode::LSHIFT, Key::LEFT_SHIFT],
]);

it('maps the digit row with 0 last, the way SDL numbers it', function () {
    expect(ScancodeMap::key(30))->toBe(Key::DIGIT_1)
        ->and(ScancodeMap::key(38))->toBe(Key::DIGIT_9)
        ->and(ScancodeMap::key(39))->toBe(Key::DIGIT_0)
        ->and(ScancodeMap::key(98))->toBe(Key::NUMPAD_0)
        ->and(ScancodeMap::key(89))->toBe(Key::NUMPAD_1);
});

it('answers UNKNOWN for anything it has no key for', function () {
    expect(ScancodeMap::key(0))->toBe(Key::UNKNOWN)->and(ScancodeMap::key(9999))->toBe(Key::UNKNOWN);
});
