<?php

declare(strict_types=1);

use Jovian\Venusian\Sdl3\Enums\GLProfile;
use Jovian\Venusian\Sdl3\Events\SdlEventPump;
use Jovian\Venusian\Sdl3\Input\Sdl3InputEngine;
use Jovian\Venusian\Sdl3\Providers\VenusianSdl3ServiceProvider;
use Jovian\Venusian\Sdl3\Sessions\SdlStageSession;
use Surface\Contracts\Stage\StageHost;

it('is the sdl3 host, with its own pump, idle until connected', function () {
    $session = new SdlStageSession();

    expect($session->host())->toBe(StageHost::SDL3)
        ->and($session->sharesNativePump())->toBeFalse()
        ->and($session->connected())->toBeFalse()
        ->and($session->pump())->toBe(0);
});

it('GL profile values match SDL_video.h', function () {
    expect([GLProfile::CORE->value, GLProfile::ES->value])->toBe([1, 4]);
});

it('is published behind the stage.sdl3 alias, its input engine behind input.sdl3', function () {
    $app = new class
    {
        /** @var list<string> */
        public array $singletons = [];

        /** @var array<string, string> */
        public array $aliases = [];

        public function singleton(string $abstract, ?Closure $concrete = null): void
        {
            $this->singletons[] = $abstract;
        }

        public function alias(string $abstract, string $alias): void
        {
            $this->aliases[$alias] = $abstract;
        }

        public function make(string $abstract): mixed
        {
            return new $abstract();
        }
    };

    (new VenusianSdl3ServiceProvider($app))->register();

    expect($app->singletons)->toContain(SdlStageSession::class)
        ->and($app->singletons)->toContain(SdlEventPump::class)
        ->and($app->aliases['stage.sdl3'] ?? null)->toBe(SdlStageSession::class)
        ->and($app->singletons)->toContain(Sdl3InputEngine::class)
        ->and($app->aliases['input.sdl3'] ?? null)->toBe(Sdl3InputEngine::class);
});

it('shares the event pump it is given and knows no window until one opens', function () {
    $session = new SdlStageSession(new SdlEventPump());

    expect($session->windowName(1))->toBeNull()->and($session->windowHandles())->toBe([]);
});

it('owns the native pump on macOS only', function () {
    expect((new SdlStageSession())->ownsNativePump())->toBe(PHP_OS_FAMILY === 'Darwin');
});
