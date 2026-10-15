<?php

declare(strict_types=1);

use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\HumanInput\Devices\GameController;

/*
 * SDL pads without hardware: virtual joysticks. Assertions name only the devices a
 * test attached; the Pi has a DualSense paired, and a Mac may have a pad of its own.
 */

afterEach(function (): void {
    letGoOfPadSources();
    letGoOfPads();
});

it('lists an SDL gamepad as a game controller with every button and axis, and reads it', function (): void {
    $id = virtualPad('php pad');
    $pads = sdl3Pads();
    $pads->poll();
    $pad = $pads->gameControllers()["sdl3-{$id}"];
    $stick = SDL_OpenJoystick($id);

    SDL_SetJoystickVirtualButton($stick, SDL_GAMEPAD_BUTTON_SOUTH, true);
    SDL_SetJoystickVirtualAxis($stick, SDL_GAMEPAD_AXIS_LEFTX, 32767);
    SDL_SetJoystickVirtualAxis($stick, SDL_GAMEPAD_AXIS_LEFTY, -32768);
    SDL_SetJoystickVirtualAxis($stick, SDL_GAMEPAD_AXIS_RIGHT_TRIGGER, 32767);
    $pads->poll();

    expect($pad->name())->toBe('php pad')
        ->and($pad->axes())->toBe(GamepadAxis::cases())
        ->and(array_map(fn (GamepadButton $b): bool => $pad->supports($b), GamepadButton::cases()))->not->toContain(false)
        ->and($pad->isPressed(GamepadButton::SOUTH))->toBeTrue()
        ->and($pad->leftStick())->toBe(['x' => 1.0, 'y' => -1.0])
        ->and($pad->rightTrigger())->toBe(1.0)
        ->and($pads->gamePads())->not->toHaveKey("sdl3-{$id}");

    SDL_CloseJoystick($stick);
});

it('drops a gamepad that went away', function (): void {
    $id = virtualPad('leaving');
    $pads = sdl3Pads();
    $pads->poll();
    $listed = array_key_exists("sdl3-{$id}", $pads->gameControllers());

    letGoOfPads();
    $pads->poll();

    expect($listed)->toBeTrue()
        ->and($pads->gameControllers())->not->toHaveKey("sdl3-{$id}");
});

it('reads a generic joystick under the default map, though the test holds the same SDL_Joystick', function (): void {
    $id = virtualPad('php stick', SDL_JOYSTICK_TYPE_UNKNOWN, 3, 9, 1);
    $pads = sdl3Pads();
    $pads->poll();
    $stick = SDL_OpenJoystick($id);

    SDL_SetJoystickVirtualHat($stick, 0, SDL_HAT_UP | SDL_HAT_LEFT);
    SDL_SetJoystickVirtualButton($stick, 2, true);
    SDL_SetJoystickVirtualAxis($stick, 1, -32768);
    SDL_CloseJoystick($stick);
    $pads->poll();
    $pad = $pads->gameControllers()["sdl3-js-{$id}"];

    expect($pad)->toBeInstanceOf(GameController::class)
        ->and($pad->name())->toBe('php stick')
        ->and($pad->axes())->toBe([GamepadAxis::LEFT_X, GamepadAxis::LEFT_Y, GamepadAxis::RIGHT_X])
        ->and($pad->supports(GamepadButton::START))->toBeTrue()
        ->and($pad->supports(GamepadButton::GUIDE))->toBeFalse()
        ->and($pad->isDown(GamepadButton::DPAD_UP))->toBeTrue()
        ->and($pad->isDown(GamepadButton::DPAD_LEFT))->toBeTrue()
        ->and($pad->isDown(GamepadButton::DPAD_DOWN))->toBeFalse()
        ->and($pad->isDown(GamepadButton::WEST))->toBeTrue()
        ->and($pad->leftStick()['y'])->toBe(-1.0);
});

it('lists a joystick without sticks as a game pad', function (): void {
    $id = virtualPad('php buttons', SDL_JOYSTICK_TYPE_UNKNOWN, 0, 4, 0);
    $pads = sdl3Pads();
    $pads->poll();

    expect($pads->gamePads())->toHaveKey("sdl3-js-{$id}")
        ->and($pads->gameControllers())->not->toHaveKey("sdl3-js-{$id}")
        ->and($pads->gamePads()["sdl3-js-{$id}"]->supports(GamepadButton::NORTH))->toBeTrue();
});

it('switches SDL\'s joystick and gamepad events off while it runs, so updates queue nothing', function (): void {
    $id = virtualPad('quiet');
    $pads = sdl3Pads();
    $stick = SDL_OpenJoystick($id);
    SDL_PumpEvents();
    $sink = new SDL_Event();
    while (SDL_PollEvent($sink)) {
    }

    for ($i = 0; $i < 20; $i++) {
        SDL_SetJoystickVirtualAxis($stick, 0, $i * 1000);
        $pads->poll();
    }
    $queued = 0;
    $event = new SDL_Event();
    while (SDL_PollEvent($event)) {
        $queued += $event->type >= SDL_EVENT_JOYSTICK_AXIS_MOTION && $event->type < SDL_EVENT_FINGER_DOWN ? 1 : 0;
    }
    $off = [SDL_EventEnabled(SDL_EVENT_JOYSTICK_UPDATE_COMPLETE), SDL_EventEnabled(SDL_EVENT_GAMEPAD_AXIS_MOTION)];
    SDL_CloseJoystick($stick);
    $pads->disconnect();

    expect($queued)->toBe(0)
        ->and($off)->toBe([false, false])
        ->and(SDL_EventEnabled(SDL_EVENT_JOYSTICK_UPDATE_COMPLETE))->toBeTrue();
});

it('reads pads with SDL windows open and none focused', function (): void {
    $script = tempnam(sys_get_temp_dir(), 'sdl3').'.php';
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).';
        SDL_Init(SDL_INIT_VIDEO) || exit("video: ".SDL_GetError());
        $window = SDL_CreateWindow("unfocused", 64, 64, SDL_WINDOW_HIDDEN);
        $desc = new SDL_VirtualJoystickDesc();
        $desc->type = SDL_JOYSTICK_TYPE_GAMEPAD;
        $desc->naxes = 6;
        $desc->nbuttons = 15;
        $desc->name = "background";
        $pads = new Jovian\Engines\Sdl3\Input\Sdl3PadSource(new Surface\HumanInput\InputFrame(fn (): int => 1_000_000_000));
        $pads->connect();
        $id = SDL_AttachVirtualJoystick($desc);
        $stick = SDL_OpenJoystick($id);
        SDL_SetJoystickVirtualButton($stick, SDL_GAMEPAD_BUTTON_SOUTH, true);
        $pads->poll();
        $pads->poll();
        echo json_encode([is_null(SDL_GetKeyboardFocus()), $pads->gameControllers()["sdl3-{$id}"]->isDown(Surface\Contracts\HumanInput\GamepadButton::SOUTH)]);');

    $out = shell_exec(escapeshellarg(PHP_BINARY).' -d memory_limit=128M '.escapeshellarg($script).' 2>&1');
    unlink($script);

    // Without SDL_HINT_JOYSTICK_ALLOW_BACKGROUND_EVENTS, SDL drops this press: a window exists and none has focus (measured).
    expect(json_decode((string) $out, true))->toBe([true, true]);
});

it('closes its pads on disconnect and lists none after', function (): void {
    virtualPad('closed');
    $pads = sdl3Pads();
    $pads->poll();
    $pads->disconnect();

    expect($pads->gameControllers())->toBe([])
        ->and($pads->gamePads())->toBe([]);
});

it('disconnects after SDL_Quit took its pads', function (): void {
    $script = tempnam(sys_get_temp_dir(), 'sdl3').'.php';
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).';
        $desc = new SDL_VirtualJoystickDesc();
        $desc->type = SDL_JOYSTICK_TYPE_GAMEPAD;
        $desc->naxes = 6;
        $desc->nbuttons = 15;
        $desc->name = "quit";
        $pads = new Jovian\Engines\Sdl3\Input\Sdl3PadSource(new Surface\HumanInput\InputFrame(fn (): int => 1_000_000_000));
        $pads->connect();
        $id = SDL_AttachVirtualJoystick($desc);
        $pads->poll();
        $listed = count($pads->gameControllers());
        SDL_Quit();
        $pads->disconnect();
        echo json_encode([$listed >= 1, $pads->gameControllers()]);');

    $out = shell_exec(escapeshellarg(PHP_BINARY).' -d memory_limit=128M '.escapeshellarg($script).' 2>&1');
    unlink($script);

    expect(json_decode((string) $out, true))->toBe([true, []]);
});

it('is the sdl3 pad source: connected once, listing for every toolkit', function (): void {
    $pads = sdl3Pads();

    expect($pads)->toBeInstanceOf(Surface\Contracts\HumanInput\PadSource::class)
        ->and($pads->connected())->toBeTrue()
        ->and($pads->connect())->toBe($pads);
});

it('gives a pad SDL\'s serial as its hardware id, and none to a virtual device', function (): void {
    $pad_id = virtualPad('anonymous');
    $stick_id = virtualPad('anonymous stick', SDL_JOYSTICK_TYPE_UNKNOWN, 2, 4);
    $pads = sdl3Pads();
    $pads->poll();

    expect($pads->gameControllers()["sdl3-{$pad_id}"]->hardwareId())->toBeNull()
        ->and($pads->gameControllers()["sdl3-js-{$stick_id}"]->hardwareId())->toBeNull();
});

it('lights the player index on the gamepad or joystick, and clears it with null', function (): void {
    $pad_id = virtualPad('player pad');
    $stick_id = virtualPad('player stick', SDL_JOYSTICK_TYPE_UNKNOWN, 2, 4);
    $pads = sdl3Pads();
    $pads->poll();
    $pad = $pads->gameControllers()["sdl3-{$pad_id}"];
    $stick = $pads->gameControllers()["sdl3-js-{$stick_id}"];
    $pad_handle = SDL_OpenJoystick($pad_id);
    $stick_handle = SDL_OpenJoystick($stick_id);

    $pad->setPlayerIndex(1);
    $stick->setPlayerIndex(2);
    $lit = [SDL_GetJoystickPlayerIndex($pad_handle), SDL_GetJoystickPlayerIndex($stick_handle)];
    $pad->setPlayerIndex(null);

    expect($lit)->toBe([1, 2])
        ->and(SDL_GetJoystickPlayerIndex($pad_handle))->toBe(-1);

    SDL_CloseJoystick($pad_handle);
    SDL_CloseJoystick($stick_handle);
});

it('reads pads in a process with no SDL video at all', function (): void {
    $script = tempnam(sys_get_temp_dir(), 'sdl3').'.php';
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).';
        $pads = new Jovian\Engines\Sdl3\Input\Sdl3PadSource(new Surface\HumanInput\InputFrame(fn (): int => 1_000_000_000));
        $pads->connect();
        $desc = new SDL_VirtualJoystickDesc();
        $desc->type = SDL_JOYSTICK_TYPE_GAMEPAD;
        $desc->naxes = 6;
        $desc->nbuttons = 15;
        $desc->name = "headless";
        $id = SDL_AttachVirtualJoystick($desc);
        $stick = SDL_OpenJoystick($id);
        SDL_SetJoystickVirtualButton($stick, SDL_GAMEPAD_BUTTON_EAST, true);
        $pads->poll();
        $pad = $pads->gameControllers()["sdl3-{$id}"];
        echo json_encode([SDL_GetCurrentVideoDriver(), $pad->isDown(Surface\Contracts\HumanInput\GamepadButton::EAST)]);
        SDL_CloseJoystick($stick);
        $pads->disconnect();');

    $out = shell_exec(escapeshellarg(PHP_BINARY).' -d memory_limit=128M '.escapeshellarg($script).' 2>&1');
    unlink($script);

    expect(json_decode((string) $out, true))->toBe([null, true]);
});

it('lists one device for one serial, as SDL may list a pad under two drivers on a hot-plug, the twin taking over when the first goes', function (): void {
    $first = virtualPad('twin');
    $second = virtualPad('twin');
    $other = virtualPad('other');
    $pads = sdl3Pads(fn (object $handle, int $id): ?string => match (true) {
        $id === $first => '4c-b9-9b-49-be-4a',
        $id === $second => '4C:B9:9B:49:BE:4A',
        default => null,
    });
    $pads->poll();
    $pads->poll();

    expect(array_keys($pads->gameControllers()))->toContain("sdl3-{$first}", "sdl3-{$other}")
        ->and($pads->gameControllers())->not->toHaveKey("sdl3-{$second}")
        ->and($pads->gameControllers()["sdl3-{$first}"]->hardwareId())->toBe('4c:b9:9b:49:be:4a');

    $device = $pads->gameControllers()["sdl3-{$first}"];
    $stick = SDL_OpenJoystick($second);
    SDL_SetJoystickVirtualButton($stick, SDL_GAMEPAD_BUTTON_NORTH, true);
    SDL_DetachVirtualJoystick($first);
    $pads->poll();
    $device->setPlayerIndex(3);

    expect($pads->gameControllers())->toHaveKey("sdl3-{$first}")
        ->and($pads->gameControllers())->not->toHaveKey("sdl3-{$second}")
        ->and($pads->gameControllers()["sdl3-{$first}"])->toBe($device)
        ->and($device->isDown(GamepadButton::NORTH))->toBeTrue()
        ->and(SDL_GetJoystickPlayerIndex($stick))->toBe(3);

    SDL_CloseJoystick($stick);
});

it('keeps a serial that is no Bluetooth address as SDL gives it', function (): void {
    $id = virtualPad('serial');
    $pads = sdl3Pads(fn (object $handle, int $given): ?string => $given === $id ? 'SN-0042' : null);
    $pads->poll();

    expect($pads->gameControllers()["sdl3-{$id}"]->hardwareId())->toBe('SN-0042');
});

it('reads a Linux pad\'s address from sysfs when SDL has no serial for it', function (): void {
    $sys = sys_get_temp_dir().'/sdl3-sysfs-'.getmypid();
    @mkdir("{$sys}/event4/device", 0777, true);
    file_put_contents("{$sys}/event4/device/uniq", "4c:b9:9b:49:be:4a\n");
    @mkdir("{$sys}/event9/device", 0777, true);
    file_put_contents("{$sys}/event9/device/uniq", "\n");

    try {
        expect(Jovian\Engines\Sdl3\Input\Sdl3PadSource::uniq('/dev/input/event4', $sys))->toBe('4c:b9:9b:49:be:4a')
            ->and(Jovian\Engines\Sdl3\Input\Sdl3PadSource::uniq('/dev/input/event9', $sys))->toBeNull()
            ->and(Jovian\Engines\Sdl3\Input\Sdl3PadSource::uniq('/dev/input/event7', $sys))->toBeNull()
            ->and(Jovian\Engines\Sdl3\Input\Sdl3PadSource::uniq('DevSrvsID:4294970000', $sys))->toBeNull()
            ->and(Jovian\Engines\Sdl3\Input\Sdl3PadSource::uniq(null, $sys))->toBeNull();
    } finally {
        array_map('unlink', glob("{$sys}/*/device/uniq"));
        array_map('rmdir', glob("{$sys}/*/device"));
        array_map('rmdir', glob("{$sys}/*"));
        rmdir($sys);
    }
});
