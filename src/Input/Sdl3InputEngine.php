<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Input;

use Closure;
use Jovian\Bindings\Sdl3\Enums\SDLGamepadAxis;
use Jovian\Bindings\Sdl3\Enums\SDLGamepadButton;
use Jovian\Bindings\Sdl3\Enums\SDLInitFlags;
use Jovian\Bindings\Sdl3\Events\SDLKeyboard;
use Jovian\Bindings\Sdl3\Events\SDLMouse;
use Jovian\Bindings\Sdl3\SDL;
use Jovian\Bindings\Sdl3\SDLError;
use Jovian\Venusian\Sdl3\Events\SdlEventPump;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3InputException;
use Jovian\Venusian\Sdl3\Sessions\SdlStageSession;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\Contracts\HumanInput\InputEngine;
use Surface\Contracts\HumanInput\InputEngineDriver;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\HumanInput\Devices\GameController;
use Surface\HumanInput\Devices\Keyboard;
use Surface\HumanInput\Devices\Mouse;

/**
 * The sdl3 input engine. Keys, text and the mouse are event-driven: the shared
 * SdlEventPump buffers them while this engine wants input, scoped to the
 * windows the sdl3 stage host owns. Gamepads are polled each tick and need no
 * SDL window; GAMEPAD_ADDED / REMOVED only trigger a rescan. Text input starts
 * per stage window as each one appears and stops on disconnect. Mouse window
 * = the stage under SDL's mouse focus, read after each poll's events. Focus
 * loss needs no handling here: SDL resets its keyboard and sends the key-ups.
 * Never calls SDL_Quit — the stage host may own video.
 */
final class Sdl3InputEngine implements InputEngineDriver
{
    private const int KMOD_SHIFT = 0x0003;
    private const int KMOD_CTRL = 0x00C0;
    private const int KMOD_ALT = 0x0300;
    private const int KMOD_GUI = 0x0C00;

    /** SDL_MOUSEWHEEL_FLIPPED (SDL_MouseWheelDirection; NORMAL = 0). jovian/sdl3 has no enum for it. */
    private const int WHEEL_FLIPPED = 1;

    /** @var Closure(): bool */
    private readonly Closure $init;

    /** @var Closure(int): bool */
    private readonly Closure $start_text;

    /** @var Closure(int): bool */
    private readonly Closure $stop_text;

    /** @var Closure(): int window handle under the mouse, 0 = none */
    private readonly Closure $mouse_focus;

    private bool $connected = false;

    private ?Keyboard $keyboard = null;

    private ?Mouse $mouse = null;

    /** @var array<int, int> gamepad instance id → handle */
    private array $handles = [];

    /** @var array<int, GameController> gamepad instance id → device */
    private array $controllers = [];

    /** @var array<int, int> SDL window id → handle, windows text input was started on */
    private array $text_started = [];

    public function __construct(
        private readonly SdlEventPump $pump,
        private readonly SdlStageSession $session,
        private readonly ReadsGamepads $gamepads = new SdlGamepadReader(),
        ?Closure $init = null,
        ?Closure $start_text = null,
        ?Closure $stop_text = null,
        ?Closure $mouse_focus = null,
    ) {
        $this->init = $init ?? static fn (): bool => SDL::SDLInitSubSystem(SDLInitFlags::GAMEPAD->value | SDLInitFlags::EVENTS->value);
        $this->start_text = $start_text ?? SDLKeyboard::SDLStartTextInput(...);
        $this->stop_text = $stop_text ?? SDLKeyboard::SDLStopTextInput(...);
        $this->mouse_focus = $mouse_focus ?? SDLMouse::SDLGetMouseFocus(...);
    }

    public function engine(): InputEngine
    {
        return InputEngine::SDL3;
    }

    public function connect(): static
    {
        if ($this->connected) {
            return $this;
        }

        if (! ($this->init)()) {
            throw Sdl3InputException::init(SDLError::SDLGetError());
        }

        $this->keyboard = new Keyboard();
        $this->mouse = new Mouse();
        $this->pump->wantInput(true);
        $this->connected = true;
        $this->syncGamepads();

        return $this;
    }

    public function disconnect(): void
    {
        foreach ($this->handles as $handle) {
            $this->gamepads->close($handle);
        }

        // Only windows still open: a closed stage's handle is destroyed.
        foreach (array_intersect_key($this->text_started, $this->session->windowHandles()) as $window) {
            ($this->stop_text)($window);
        }

        $this->handles = [];
        $this->controllers = [];
        $this->text_started = [];
        $this->keyboard = null;
        $this->mouse = null;
        $this->pump->wantInput(false);
        $this->connected = false;
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function poll(): void
    {
        if (! $this->connected) {
            return;
        }

        $this->startTextOnNewWindows();
        $this->pump->drain();

        $this->keyboard->settle();
        $this->mouse->settle();
        foreach ($this->controllers as $controller) {
            $controller->settle();
        }

        $this->apply($this->pump->takeInput());
        $this->mouse->setPosition($this->mouse->x(), $this->mouse->y(), $this->focusedStage());
        $this->readGamepads();
    }

    /**
     * @internal the pure half of poll(): fold decoded events into the devices
     *
     * @param list<array<string, mixed>> $events
     */
    public function apply(array $events): void
    {
        if (! $this->connected) {
            return;
        }

        foreach ($events as $event) {
            match ($event['kind'] ?? null) {
                'key' => $this->applyKey($event),
                'text' => $this->keyboard->appendText((string) $event['text']),
                'motion' => $this->applyMotion($event),
                'button' => $this->applyButton($event),
                'wheel' => $this->applyWheel($event),
                'gamepads' => $this->syncGamepads(),
                default => null,
            };
        }
    }

    public function keyboard(): ?Keyboard
    {
        return $this->keyboard;
    }

    public function mouse(): ?Mouse
    {
        return $this->mouse;
    }

    /** Every SDL gamepad has sticks: all of them are game controllers. */
    public function gamePads(): array
    {
        return [];
    }

    /** @return array<string, GameController> */
    public function gameControllers(): array
    {
        $keyed = [];
        foreach ($this->controllers as $controller) {
            $keyed[$controller->id()] = $controller;
        }

        return $keyed;
    }

    /** @param array<string, mixed> $event */
    private function applyKey(array $event): void
    {
        if ((bool) $event['repeat']) {
            return;
        }

        $mod = (int) $event['mod'];
        $this->keyboard
            ->update(ScancodeMap::key((int) $event['scancode']), (bool) $event['down'])
            ->setModifiers(new Modifiers(
                shift: ($mod & self::KMOD_SHIFT) !== 0,
                ctrl: ($mod & self::KMOD_CTRL) !== 0,
                alt: ($mod & self::KMOD_ALT) !== 0,
                meta: ($mod & self::KMOD_GUI) !== 0,
            ));
    }

    /** @param array<string, mixed> $event */
    private function applyMotion(array $event): void
    {
        $this->mouse
            ->setPosition((float) $event['x'], (float) $event['y'], $this->session->windowName((int) $event['window_id']))
            ->addMotion((float) $event['xrel'], (float) $event['yrel']);
    }

    /**
     * SDL reports a flipped ("natural") wheel negated; undo it so dy > 0 is always away from the user.
     *
     * @param array<string, mixed> $event
     */
    private function applyWheel(array $event): void
    {
        $sign = (int) $event['direction'] === self::WHEEL_FLIPPED ? -1.0 : 1.0;
        $this->mouse->addWheel($sign * (float) $event['x'], $sign * (float) $event['y']);
    }

    /** @param array<string, mixed> $event */
    private function applyButton(array $event): void
    {
        $button = match ((int) $event['button']) {
            1 => MouseButton::LEFT,
            2 => MouseButton::MIDDLE,
            3 => MouseButton::RIGHT,
            4 => MouseButton::X1,
            5 => MouseButton::X2,
            default => null,
        };

        if (is_null($button)) {
            return;
        }

        $this->mouse
            ->update($button, (bool) $event['down'])
            ->setPosition((float) $event['x'], (float) $event['y'], $this->session->windowName((int) $event['window_id']));
    }

    private function syncGamepads(): void
    {
        $ids = $this->gamepads->ids();

        foreach ($ids as $id) {
            if (array_key_exists($id, $this->handles)) {
                continue;
            }

            $handle = $this->gamepads->open($id);
            if ($handle === 0) {
                continue;
            }

            $this->handles[$id] = $handle;
            $this->controllers[$id] = new GameController("sdl3-{$id}", $this->gamepads->name($handle), GamepadButton::cases(), GamepadAxis::cases());
        }

        foreach (array_diff(array_keys($this->handles), $ids) as $gone) {
            $this->gamepads->close($this->handles[$gone]);
            unset($this->handles[$gone], $this->controllers[$gone]);
        }
    }

    private function readGamepads(): void
    {
        $this->gamepads->refresh();

        foreach ($this->controllers as $id => $controller) {
            $handle = $this->handles[$id];

            foreach (GamepadButton::cases() as $button) {
                $controller->update($button, $this->gamepads->button($handle, self::sdlButton($button)));
            }

            foreach (GamepadAxis::cases() as $axis) {
                $controller->setAxis($axis, $this->gamepads->axis($handle, self::sdlAxis($axis)) / 32767.0);
            }
        }
    }

    /** The stage under SDL's mouse focus; null when the focus is no stage of this session. */
    private function focusedStage(): ?string
    {
        $window = ($this->mouse_focus)();
        if ($window === 0) {
            return null;
        }

        $window_id = array_search($window, $this->session->windowHandles(), true);

        return $window_id === false ? null : $this->session->windowName($window_id);
    }

    private function startTextOnNewWindows(): void
    {
        $handles = $this->session->windowHandles();
        $this->text_started = array_intersect_key($this->text_started, $handles);

        foreach ($handles as $window_id => $window) {
            if (array_key_exists($window_id, $this->text_started)) {
                continue;
            }

            ($this->start_text)($window);
            $this->text_started[$window_id] = $window;
        }
    }

    private static function sdlButton(GamepadButton $button): SDLGamepadButton
    {
        return match ($button) {
            GamepadButton::SOUTH => SDLGamepadButton::SOUTH,
            GamepadButton::EAST => SDLGamepadButton::EAST,
            GamepadButton::WEST => SDLGamepadButton::WEST,
            GamepadButton::NORTH => SDLGamepadButton::NORTH,
            GamepadButton::BACK => SDLGamepadButton::BACK,
            GamepadButton::GUIDE => SDLGamepadButton::GUIDE,
            GamepadButton::START => SDLGamepadButton::START,
            GamepadButton::LEFT_STICK => SDLGamepadButton::LEFT_STICK,
            GamepadButton::RIGHT_STICK => SDLGamepadButton::RIGHT_STICK,
            GamepadButton::LEFT_SHOULDER => SDLGamepadButton::LEFT_SHOULDER,
            GamepadButton::RIGHT_SHOULDER => SDLGamepadButton::RIGHT_SHOULDER,
            GamepadButton::DPAD_UP => SDLGamepadButton::DPAD_UP,
            GamepadButton::DPAD_DOWN => SDLGamepadButton::DPAD_DOWN,
            GamepadButton::DPAD_LEFT => SDLGamepadButton::DPAD_LEFT,
            GamepadButton::DPAD_RIGHT => SDLGamepadButton::DPAD_RIGHT,
        };
    }

    private static function sdlAxis(GamepadAxis $axis): SDLGamepadAxis
    {
        return match ($axis) {
            GamepadAxis::LEFT_X => SDLGamepadAxis::LEFTX,
            GamepadAxis::LEFT_Y => SDLGamepadAxis::LEFTY,
            GamepadAxis::RIGHT_X => SDLGamepadAxis::RIGHTX,
            GamepadAxis::RIGHT_Y => SDLGamepadAxis::RIGHTY,
            GamepadAxis::LEFT_TRIGGER => SDLGamepadAxis::LEFT_TRIGGER,
            GamepadAxis::RIGHT_TRIGGER => SDLGamepadAxis::RIGHT_TRIGGER,
        };
    }
}
