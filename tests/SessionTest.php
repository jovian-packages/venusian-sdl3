<?php

declare(strict_types=1);

use Jovian\Venusian\Sdl3\Enums\GLProfile;
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

it('is published behind the stage.sdl3 alias', function () {
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

    expect($app->singletons)->toContain(SdlStageSession::class)
        ->and($app->aliases['stage.sdl3'] ?? null)->toBe(SdlStageSession::class);
});
