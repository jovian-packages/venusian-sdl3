<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Contracts;

/** Something an SDL window lends an engine (a GL context, a Metal view, a Vulkan lender); released after the executor, before the window. */
interface LendsToEngine
{
    public function release(): void;
}
