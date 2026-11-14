<?php

declare(strict_types=1);

/*
 * Runs inside a copy of venusian/topside, as venusian-pixman's tests do: clone topside, copy its
 * vendor (a symlinked vendor makes Pest load topside's own tests), add to tests/Pest.php, after
 * declare(strict_types=1),
 *
 *     (require __DIR__.'/../vendor/autoload.php')->addPsr4('Jovian\\Engines\\Sdl3\\Framebuffers\\', '<this package>/src/Framebuffers/');
 *
 * yield 'sdl3' => [new SDL3FramebufferDriver()] in tests/Datasets/FramebufferDrivers.php when
 * SDL3FramebufferDriver::available(), copy this file into tests/Framebuffers/, then
 * zhp vendor/bin/pest. No tolerance: the store is byte-exact. 2026-11-13, topside 4c02da9:
 * 3725 passed, 6 skipped, 278 of them on 'sdl3'.
 */

use Jovian\Engines\Sdl3\Framebuffers\SDL3FramebufferDriver;
use Venusian\Topside\Tests\Support\Framebuffers\FixtureRunner;

it('passes every golden fixture on sdl3, byte for byte', function (string $file): void {
    FixtureRunner::run(new SDL3FramebufferDriver(), $file);
})->with(FixtureRunner::files());
