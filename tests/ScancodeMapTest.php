<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Input\ScancodeMap;
use Surface\Contracts\HumanInput\Key;

it('maps every Surface key but fn to exactly one SDL scancode', function (): void {
    $seen = [];
    for ($scancode = 0; $scancode < 512; $scancode++) {
        $key = ScancodeMap::key($scancode);
        if ($key !== Key::UNKNOWN) {
            expect($seen)->not->toHaveKey($key->value);
            $seen[$key->value] = $scancode;
        }
    }

    $missing = array_values(array_diff(array_map(fn (Key $key): string => $key->value, Key::cases()), array_keys($seen)));

    expect($missing)->toBe(['function', 'unknown']);
});

it('reads positions, not letters', function (): void {
    expect([ScancodeMap::key(4), ScancodeMap::key(29), ScancodeMap::key(39), ScancodeMap::key(225), ScancodeMap::key(101), ScancodeMap::key(300)])
        ->toBe([Key::A, Key::Z, Key::DIGIT_0, Key::LEFT_SHIFT, Key::MENU, Key::UNKNOWN]);
});
