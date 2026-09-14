<?php

declare(strict_types=1);

use Jovian\Venusian\Sdl3\Providers\VenusianSdl3ServiceProvider;
use Jovian\Venusian\Sdl3\Sdl3GpuEngine;
use Jovian\Venusian\Sdl3\Sdl3GpuExecutor;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Contracts\Drawing\SurfaceKind;

it('is the sdl3 engine on a HOST_WINDOW surface', function () {
    expect((new Sdl3GpuEngine)->engine())->toBe(GPUEngine::SDL3)
        ->and((new Sdl3GpuEngine)->surfaceKind())->toBe(SurfaceKind::HOST_WINDOW);
});

it('declares blending, instancing and readback; no depth', function () {
    $caps = Sdl3GpuExecutor::declaredCapabilities();

    expect([$caps->blending, $caps->depth, $caps->instancing, $caps->readback, $caps->max_texture_size])
        ->toBe([true, false, true, true, 8192]);
});

it('is published behind the gpu.sdl3 alias', function () {
    $app = new class
    {
        /** @var list<string> */
        public array $singletons = [];

        /** @var array<string, string> */
        public array $aliases = [];

        public function singleton(string $abstract): void
        {
            $this->singletons[] = $abstract;
        }

        public function alias(string $abstract, string $alias): void
        {
            $this->aliases[$alias] = $abstract;
        }
    };

    (new VenusianSdl3ServiceProvider($app))->register();

    expect($app->singletons)->toContain(Sdl3GpuEngine::class)
        ->and($app->aliases['gpu.sdl3'] ?? null)->toBe(Sdl3GpuEngine::class);
});
