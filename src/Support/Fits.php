<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Support;

use Jovian\Bindings\Sdl3\Enums\SDLRendererLogicalPresentation;
use Surface\Contracts\Stage\StageFit;

/** Surface's fit vocabulary in SDL's words. */
final class Fits
{
    public static function of(StageFit $fit): SDLRendererLogicalPresentation
    {
        return match ($fit) {
            StageFit::STRETCH => SDLRendererLogicalPresentation::STRETCH,
            StageFit::LETTERBOX => SDLRendererLogicalPresentation::LETTERBOX,
            StageFit::INTEGER_SCALE => SDLRendererLogicalPresentation::INTEGER_SCALE,
            StageFit::OVERSCAN => SDLRendererLogicalPresentation::OVERSCAN,
        };
    }
}
