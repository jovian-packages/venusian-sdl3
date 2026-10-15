<?php

namespace Jovian\Engines\Sdl3\Input;

use Closure;
use Jovian\Engines\Sdl3\Bridge\Sdl3BridgeDriver;
use Jovian\Engines\Sdl3\Bridge\Sdl3Session;
use Jovian\Engines\Sdl3\Windows\Sdl3StagedWindow;
use SDL_Event;
use SDL_Window;
use Surface\Contracts\HumanInput\HumanInputException;
use Surface\Contracts\HumanInput\InputEngineDriver;
use Surface\Contracts\HumanInput\InputTap;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\HumanInput\Devices\Keyboard;
use Surface\HumanInput\Devices\Mouse;
use Surface\HumanInput\InputFrame;

/**
 * HumanInput's sdl3 engine: keyboard and mouse from the SDL staged windows' session.
 * It lists no pads: SDL's pads are the 'sdl3' pad source. A tap on Sdl3Session: see()
 * copies each event out at once, because the session reuses one SDL_Event for every
 * event it pumps; poll() applies the copies. Events for windows the session does not
 * hold are ignored. Text input is started on every window the session holds, so
 * TEXT_INPUT arrives. When a window loses focus to no window of the session, every key
 * and button is released; SDL also sends the key-ups when the application loses focus,
 * and they then change nothing.
 */
final class Sdl3InputEngine implements InputEngineDriver, InputTap
{
    private bool $connected = false;

    private readonly Keyboard $keyboard;

    private readonly Mouse $mouse;

    /** The session connect() tapped, untapped by disconnect(). */
    private ?Sdl3Session $session = null;

    /** @var list<SeenEvent> copied since the last poll */
    private array $seen = [];

    /** @var array<int, SDL_Window> by SDL window id: the windows text input was started on */
    private array $typing = [];

    /** Where the pointer last was, so leaving a window keeps the position. */
    private float $x = 0.0;

    private float $y = 0.0;

    private ?string $over = null;

    /** @var Closure(): ?int the SDL id of the window with keyboard focus, or null */
    private readonly Closure $focus;

    /**
     * @param (Closure(): ?int)|null $focus the SDL id of the window with keyboard focus; SDL_GetKeyboardFocus() by default
     */
    public function __construct(InputFrame $frame, private readonly Sdl3BridgeDriver $driver, ?Closure $focus = null)
    {
        $this->keyboard = new Keyboard($frame);
        $this->mouse = new Mouse($frame);
        $this->focus = $focus ?? static function (): ?int {
            $window = SDL_GetKeyboardFocus();

            return is_null($window) ? null : SDL_GetWindowID($window);
        };
    }

    /** @throws HumanInputException The driver has no connected session to tap. */
    public function connect(): static
    {
        if ($this->connected) {
            return $this;
        }

        $session = $this->driver->session();
        if (! $session instanceof Sdl3Session || ! $session->connected()) {
            throw new HumanInputException('sdl3: no connected SDL session to read input from; HumanInput starts this engine when the bridge connects one.');
        }

        $session->tap($this);
        $this->session = $session;
        $this->connected = true;

        return $this;
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        $session = $this->session;
        $session?->untap($this);
        foreach (array_intersect_key($this->typing, $session?->windows() ?? []) as $native) {
            SDL_StopTextInput($native);
        }
        $this->typing = [];
        $this->seen = [];
        $this->session = null;
        $this->connected = false;
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function see(object $native_event): void
    {
        if ($native_event instanceof SDL_Event && ! is_null($seen = SeenEvent::from($native_event))) {
            $this->seen[] = $seen;
        }
    }

    public function settle(): void
    {
        $this->keyboard->settle();
        $this->mouse->settle();
    }

    public function poll(): void
    {
        if (! $this->connected) {
            return;
        }

        $windows = $this->session?->windows() ?? [];
        $this->type($windows);

        [$seen, $this->seen] = [$this->seen, []];
        $focus_lost = false;
        foreach ($seen as $event) {
            $window = $windows[$event->window] ?? null;
            if (is_null($window)) {
                continue;
            }
            match ($event->kind) {
                SeenKind::Key => $this->key($event),
                SeenKind::Text => $this->keyboard->appendText($event->text),
                SeenKind::Motion => $this->motion($event, $window->name()),
                SeenKind::Button => $this->button($event, $window->name()),
                SeenKind::Wheel => $this->wheel($event),
                SeenKind::FocusLost => $focus_lost = true,
                SeenKind::MouseLeave => $this->leave($window->name()),
            };
        }

        if ($focus_lost && ! isset($windows[($this->focus)() ?? 0])) {
            $this->keyboard->releaseAll();
            $this->mouse->releaseAll();
        }
    }

    public function keyboard(): Keyboard
    {
        return $this->keyboard;
    }

    public function mouse(): Mouse
    {
        return $this->mouse;
    }

    /** @param array<int, Sdl3StagedWindow> $windows */
    private function type(array $windows): void
    {
        $this->typing = array_intersect_key($this->typing, $windows);
        foreach ($windows as $id => $window) {
            if (! isset($this->typing[$id])) {
                $native = $window->native();
                SDL_StartTextInput($native);
                $this->typing[$id] = $native;
            }
        }
    }

    private function key(SeenEvent $event): void
    {
        if ($event->repeat) {
            return;
        }

        $this->keyboard->update(ScancodeMap::key($event->code), $event->down)->setModifiers(new Modifiers(
            shift: ($event->mod & SDL_KMOD_SHIFT) !== 0,
            ctrl: ($event->mod & SDL_KMOD_CTRL) !== 0,
            alt: ($event->mod & SDL_KMOD_ALT) !== 0,
            meta: ($event->mod & SDL_KMOD_GUI) !== 0,
        ));
    }

    private function motion(SeenEvent $event, string $window): void
    {
        [$this->x, $this->y, $this->over] = [$event->x, $event->y, $window];
        $this->mouse->setPosition($event->x, $event->y, $window)->addMotion($event->dx, $event->dy);
    }

    private function button(SeenEvent $event, string $window): void
    {
        $button = match ($event->code) {
            SDL_BUTTON_LEFT => MouseButton::LEFT,
            SDL_BUTTON_MIDDLE => MouseButton::MIDDLE,
            SDL_BUTTON_RIGHT => MouseButton::RIGHT,
            SDL_BUTTON_X1 => MouseButton::X1,
            SDL_BUTTON_X2 => MouseButton::X2,
            default => null,
        };
        if (is_null($button)) {
            return;
        }

        [$this->x, $this->y, $this->over] = [$event->x, $event->y, $window];
        $this->mouse->setPosition($event->x, $event->y, $window)->update($button, $event->down);
    }

    /** SDL reports a flipped ("natural") wheel negated: undone, so dy > 0 is always rolled away from the user. */
    private function wheel(SeenEvent $event): void
    {
        $sign = $event->direction === SDL_MOUSEWHEEL_FLIPPED ? -1.0 : 1.0;
        $this->mouse->addWheel($sign * $event->dx, $sign * $event->dy);
    }

    private function leave(string $window): void
    {
        if ($this->over === $window) {
            $this->over = null;
            $this->mouse->setPosition($this->x, $this->y, null);
        }
    }
}
