<?php

declare(strict_types=1);

use Jovian\Venusian\Sdl3\Exceptions\Sdl3StageException;
use Jovian\Venusian\Sdl3\Sessions\SdlStageSession;

/*
| Pest bootstrap for jovian/venusian-sdl3. Feature suites skip without
| ext-sdl3 and without a video device. A skipped test is not evidence: run
| them on a GUI session (Mac) or the Pi seat.
*/

function sdl3ExtensionLoaded(): bool
{
    return extension_loaded('sdl3');
}

function connectedSdlSession(): SdlStageSession
{
    if (! sdl3ExtensionLoaded()) {
        test()->markTestSkipped('ext-sdl3 is not loaded');
    }

    $session = new SdlStageSession();
    try {
        $session->connect();
    } catch (Sdl3StageException $e) {
        test()->markTestSkipped($e->getMessage());
    }

    return $session;
}
