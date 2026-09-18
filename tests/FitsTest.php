<?php

declare(strict_types=1);

use Jovian\Bindings\Sdl3\Enums\SDLRendererLogicalPresentation;
use Jovian\Venusian\Sdl3\Support\Fits;
use Surface\Contracts\Stage\StageFit;

it('maps every Surface fit to SDL logical presentation', function () {
    expect(Fits::of(StageFit::STRETCH))->toBe(SDLRendererLogicalPresentation::STRETCH)
        ->and(Fits::of(StageFit::LETTERBOX))->toBe(SDLRendererLogicalPresentation::LETTERBOX)
        ->and(Fits::of(StageFit::INTEGER_SCALE))->toBe(SDLRendererLogicalPresentation::INTEGER_SCALE)
        ->and(Fits::of(StageFit::OVERSCAN))->toBe(SDLRendererLogicalPresentation::OVERSCAN);
});

it('covers every case, so a new fit breaks the build not the picture', function () {
    foreach (StageFit::cases() as $fit) {
        expect(Fits::of($fit))->toBeInstanceOf(SDLRendererLogicalPresentation::class);
    }
});
