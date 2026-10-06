<?php

declare(strict_types=1);

use Surface\Drawing\Gpu\GpuRenderingEngine;
use Venusian\Surface\Tests\Support\GpuParity\GpuParity;

it('boots with Surface\'s GPU engine, the parity suite and SDL video in reach', function (): void {
    video();

    expect(class_exists(GpuRenderingEngine::class))->toBeTrue()
        ->and(class_exists(GpuParity::class))->toBeTrue()
        ->and(SDL_GetVersion())->toBeGreaterThanOrEqual(3002000);
});
