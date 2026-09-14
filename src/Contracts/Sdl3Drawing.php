<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Contracts;

/** The bespoke half of the sdl3 engine: raw SDL_GPU handles for a sketch that encodes its own work. */
interface Sdl3Drawing
{
    public function device(): int;

    public function window(): int;

    /** The frame's command buffer. Throws outside a frame. */
    public function commandBuffer(): int;

    /** The offscreen colour target every draw lands in before the blit. */
    public function offscreen(): int;
}
