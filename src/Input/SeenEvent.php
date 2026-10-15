<?php

namespace Jovian\Engines\Sdl3\Input;

use SDL_Event;

/** One SDL event, copied out of the SDL_Event the session reuses for every event. Fields a kind does not use stay at zero. */
final readonly class SeenEvent
{
    public function __construct(
        public SeenKind $kind,
        public int $window,
        public int $code = 0,
        public bool $down = false,
        public bool $repeat = false,
        public int $mod = 0,
        public string $text = '',
        public float $x = 0.0,
        public float $y = 0.0,
        public float $dx = 0.0,
        public float $dy = 0.0,
        public int $direction = 0,
    ) {}

    /** The copy of $event, or null for an event the engine does not read. $code is the scancode of a key, the SDL button of a click. */
    public static function from(SDL_Event $event): ?self
    {
        return match ($event->type) {
            SDL_EVENT_KEY_DOWN, SDL_EVENT_KEY_UP => new self(SeenKind::Key, $event->key->windowID,
                code: $event->key->scancode, down: $event->key->down, repeat: $event->key->repeat, mod: $event->key->mod),
            SDL_EVENT_TEXT_INPUT => new self(SeenKind::Text, $event->text->windowID, text: $event->text->text),
            SDL_EVENT_MOUSE_MOTION => new self(SeenKind::Motion, $event->motion->windowID,
                x: $event->motion->x, y: $event->motion->y, dx: $event->motion->xrel, dy: $event->motion->yrel),
            SDL_EVENT_MOUSE_BUTTON_DOWN, SDL_EVENT_MOUSE_BUTTON_UP => new self(SeenKind::Button, $event->button->windowID,
                code: $event->button->button, down: $event->button->down, x: $event->button->x, y: $event->button->y),
            SDL_EVENT_MOUSE_WHEEL => new self(SeenKind::Wheel, $event->wheel->windowID,
                dx: $event->wheel->x, dy: $event->wheel->y, direction: $event->wheel->direction),
            SDL_EVENT_WINDOW_FOCUS_LOST => new self(SeenKind::FocusLost, $event->window->windowID),
            SDL_EVENT_WINDOW_MOUSE_LEAVE => new self(SeenKind::MouseLeave, $event->window->windowID),
            default => null,
        };
    }
}
