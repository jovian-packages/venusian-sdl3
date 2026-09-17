<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Input;

use Surface\Contracts\HumanInput\Key;

/** SDL scancodes (USB HID usage order) → Surface keys. Layout independent; anything unmapped is Key::UNKNOWN. */
final class ScancodeMap
{
    public static function key(int $scancode): Key
    {
        return match (true) {
            $scancode >= 4 && $scancode <= 29 => Key::from(chr(ord('a') + $scancode - 4)),
            $scancode >= 30 && $scancode <= 38 => Key::from('digit_'.($scancode - 29)),
            $scancode >= 58 && $scancode <= 69 => Key::from('f'.($scancode - 57)),
            $scancode >= 89 && $scancode <= 97 => Key::from('numpad_'.($scancode - 88)),
            default => match ($scancode) {
                39 => Key::DIGIT_0,
                40 => Key::ENTER,
                41 => Key::ESCAPE,
                42 => Key::BACKSPACE,
                43 => Key::TAB,
                44 => Key::SPACE,
                45 => Key::MINUS,
                46 => Key::EQUALS,
                47 => Key::LEFT_BRACKET,
                48 => Key::RIGHT_BRACKET,
                49 => Key::BACKSLASH,
                51 => Key::SEMICOLON,
                52 => Key::APOSTROPHE,
                53 => Key::GRAVE,
                54 => Key::COMMA,
                55 => Key::PERIOD,
                56 => Key::SLASH,
                57 => Key::CAPS_LOCK,
                70 => Key::PRINT_SCREEN,
                71 => Key::SCROLL_LOCK,
                72 => Key::PAUSE,
                73 => Key::INSERT,
                74 => Key::HOME,
                75 => Key::PAGE_UP,
                76 => Key::DELETE,
                77 => Key::END,
                78 => Key::PAGE_DOWN,
                79 => Key::RIGHT,
                80 => Key::LEFT,
                81 => Key::DOWN,
                82 => Key::UP,
                83 => Key::NUM_LOCK,
                84 => Key::NUMPAD_DIVIDE,
                85 => Key::NUMPAD_MULTIPLY,
                86 => Key::NUMPAD_MINUS,
                87 => Key::NUMPAD_PLUS,
                88 => Key::NUMPAD_ENTER,
                98 => Key::NUMPAD_0,
                99 => Key::NUMPAD_PERIOD,
                101 => Key::MENU,
                103 => Key::NUMPAD_EQUALS,
                156 => Key::CLEAR,
                224 => Key::LEFT_CTRL,
                225 => Key::LEFT_SHIFT,
                226 => Key::LEFT_ALT,
                227 => Key::LEFT_META,
                228 => Key::RIGHT_CTRL,
                229 => Key::RIGHT_SHIFT,
                230 => Key::RIGHT_ALT,
                231 => Key::RIGHT_META,
                default => Key::UNKNOWN,
            },
        };
    }
}
