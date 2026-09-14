<?php

declare(strict_types=1);

/*
| Pest bootstrap for jovian/venusian-sdl3. Feature suites skip without
| ext-sdl3 and without a video device. A skipped test is not evidence: run
| them on a GUI session (Mac) or the Pi seat.
*/

function sdl3ExtensionLoaded(): bool
{
    return extension_loaded('sdl3');
}
