<?php

declare(strict_types=1);

use Jovian\Venusian\Sdl3\Events\SdlEventPump;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3StageException;
use Jovian\Venusian\Sdl3\Input\Sdl3InputEngine;
use Jovian\Venusian\Sdl3\Sessions\SdlStageSession;
use Venusian\Tests\Support\FakeGamepadReader;
use Venusian\Tests\Support\StubEngine;

/**
 * A live SDL session and an input engine on its pump. Text input calls are logged, not made; the mouse focus is whatever $focus holds.
 *
 * @param list<string> $text_log "start:<handle>" / "stop:<handle>", in order
 *
 * @return array{SdlStageSession, Sdl3InputEngine}
 */
function liveInput(array &$text_log, int &$focus): array
{
    if (! sdl3ExtensionLoaded()) {
        test()->markTestSkipped('ext-sdl3 is not loaded');
    }

    $pump = new SdlEventPump();
    $session = new SdlStageSession($pump);
    try {
        $session->connect();
    } catch (Sdl3StageException $e) {
        test()->markTestSkipped($e->getMessage());
    }

    $engine = new Sdl3InputEngine(
        $pump,
        $session,
        new FakeGamepadReader(),
        init: fn (): bool => true,
        start_text: function (int $window) use (&$text_log): bool {
            $text_log[] = "start:{$window}";

            return true;
        },
        stop_text: function (int $window) use (&$text_log): bool {
            $text_log[] = "stop:{$window}";

            return true;
        },
        mouse_focus: function () use (&$focus): int {
            return $focus;
        },
    );

    return [$session, $engine];
}

it('names the stage under the mouse focus as the mouse window, and none once focus leaves', function () {
    $text_log = [];
    $focus = 0;
    [$session, $engine] = liveInput($text_log, $focus);
    $stage = $session->open('focused', new StubEngine(), 160, 120);
    $engine->connect();

    $focus = $stage->window;
    $engine->poll();
    expect($engine->mouse()->window())->toBe('focused');

    $focus = 0;
    $engine->poll();
    expect($engine->mouse()->window())->toBeNull();

    $engine->disconnect();
    $stage->close();
    $session->disconnect();
});

it('stops text input on every open window it started it on at disconnect', function () {
    $text_log = [];
    $focus = 0;
    [$session, $engine] = liveInput($text_log, $focus);
    $kept = $session->open('kept', new StubEngine(), 160, 120);
    $gone = $session->open('gone', new StubEngine(), 160, 120);
    $engine->connect()->poll();

    $gone->close();   // its handle is destroyed: never stopped
    $engine->disconnect();

    expect($text_log)->toBe(["start:{$kept->window}", "start:{$gone->window}", "stop:{$kept->window}"]);

    $kept->close();
    $session->disconnect();
});
