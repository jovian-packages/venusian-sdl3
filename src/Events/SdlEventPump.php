<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Events;

use Closure;
use Jovian\Bindings\Sdl3\Enums\SDLEventType;
use Jovian\Bindings\Sdl3\Events\SDLEvents;

/**
 * SDL has one event queue; the stage host and the input engine both live off
 * it. Whoever ticks first drains it: quit and window events go to the stage
 * router, input events are decoded and buffered for the input engine, the rest
 * is freed. A second drain in the same tick finds the queue empty. A reader
 * frees the event it decodes, so every ptr is read once or freed once.
 */
final class SdlEventPump
{
    /** @var Closure(): ?array */
    private readonly Closure $poll;

    /** @var Closure(int, string): array */
    private readonly Closure $read;

    /** @var Closure(int): void */
    private readonly Closure $free;

    /** @var null|Closure(int, int): void */
    private ?Closure $window_router = null;

    private bool $wants_input = false;

    /** @var list<array<string, mixed>> */
    private array $input = [];

    /** @var Closure(int): ?array */
    private readonly Closure $wait;

    public function __construct(?Closure $poll = null, ?Closure $read = null, ?Closure $free = null, ?Closure $wait = null)
    {
        $this->poll = $poll ?? SDLEvents::SDLPollEvent(...);
        $this->read = $read ?? SDLEvents::SDLReadEvent(...);
        $this->free = $free ?? SDLEvents::SDLFreeEvent(...);
        $this->wait = $wait ?? SDLEvents::SDLWaitEventTimeout(...);
    }

    public function routeWindowsTo(Closure $router): void
    {
        $this->window_router = $router;
    }

    public function wantInput(bool $wanted): void
    {
        $this->wants_input = $wanted;

        if (! $wanted) {
            $this->input = [];
        }
    }

    /**
     * Route everything queued. With a wait, block up to $wait_ms for the
     * first event (waking at once on input) — only the host that owns the
     * native pump is handed one.
     */
    public function drain(int $wait_ms = 0): int
    {
        $count = 0;

        if ($wait_ms > 0 && ! is_null($event = ($this->wait)($wait_ms))) {
            $count++;
            $this->route((int) $event['ptr'], (int) $event['event_type']);
        }

        while (! is_null($event = ($this->poll)())) {
            $count++;
            $this->route((int) $event['ptr'], (int) $event['event_type']);
        }

        return $count;
    }

    public function takeInput(): array
    {
        $taken = $this->input;
        $this->input = [];

        return $taken;
    }

    private function route(int $ptr, int $type): void
    {
        $is_window = $type === SDLEventType::QUIT->value
            || ($type >= SDLEventType::WINDOW_SHOWN->value && $type <= SDLEventType::WINDOW_HDR_STATE_CHANGED->value);

        if ($is_window && ! is_null($this->window_router)) {
            ($this->window_router)($ptr, $type);

            return;
        }

        $kind = $this->wants_input ? $this->inputKind($type) : null;

        if ($kind === 'gamepads') {
            ($this->free)($ptr);
            $this->input[] = ['kind' => 'gamepads'];

            return;
        }

        if (! is_null($kind)) {
            $this->input[] = ['kind' => $kind] + ($this->read)($ptr, $kind);

            return;
        }

        ($this->free)($ptr);
    }

    private function inputKind(int $type): ?string
    {
        return match ($type) {
            SDLEventType::KEY_DOWN->value, SDLEventType::KEY_UP->value => 'key',
            SDLEventType::TEXT_INPUT->value => 'text',
            SDLEventType::MOUSE_MOTION->value => 'motion',
            SDLEventType::MOUSE_BUTTON_DOWN->value, SDLEventType::MOUSE_BUTTON_UP->value => 'button',
            SDLEventType::MOUSE_WHEEL->value => 'wheel',
            SDLEventType::GAMEPAD_ADDED->value, SDLEventType::GAMEPAD_REMOVED->value => 'gamepads',
            default => null,
        };
    }
}
