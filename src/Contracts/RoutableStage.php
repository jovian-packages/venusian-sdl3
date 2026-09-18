<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Contracts;

/** What the SDL pump routes an event to, whichever kind of stage it is. */
interface RoutableStage
{
    public function name(): string;

    /** The SDL window handle. */
    public function window(): int;

    /** The SDL window id events carry. */
    public function windowId(): int;

    public function isOpen(): bool;

    /** A resize / pixel-size / scale event: re-read the window. */
    public function nativeResized(): void;

    public function closeRequested(): void;
}
