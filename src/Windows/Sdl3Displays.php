<?php

namespace Jovian\Engines\Sdl3\Windows;

use SDL_DisplayMode;
use SDL_Rect;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\DisplayMode;
use Surface\Contracts\Windows\Hdr;
use Surface\Contracts\Windows\WindowException;

/** Displays as Surface sees them, from SDL's: ids are SDL_DisplayIDs, bounds in SDL's desktop points. */
final class Sdl3Displays
{
    /** @return list<Display> the primary first */
    public static function all(): array
    {
        $ids = SDL_GetDisplays() ?? throw new WindowException('SDL lists no displays: '.SDL_GetError());
        $primary = SDL_GetPrimaryDisplay();
        usort($ids, fn (int $a, int $b): int => ($b === $primary) <=> ($a === $primary));

        return array_map(fn (int $id): Display => self::describe($id), $ids);
    }

    public static function primary(): Display
    {
        return self::describe(SDL_GetPrimaryDisplay());
    }

    public static function describe(int $id): Display
    {
        $bounds = new SDL_Rect();
        $usable = new SDL_Rect();
        if (! SDL_GetDisplayBounds($id, $bounds) || ! SDL_GetDisplayUsableBounds($id, $usable)) {
            throw new WindowException("SDL describes no display {$id}: ".SDL_GetError());
        }
        $current = SDL_GetCurrentDisplayMode($id) ?? throw new WindowException("Display {$id} reports no mode: ".SDL_GetError());
        $desktop = SDL_GetDesktopDisplayMode($id) ?? $current;

        return new Display(
            id: $id,
            name: SDL_GetDisplayName($id) ?? "Display {$id}",
            bounds: new Region($bounds->x, $bounds->y, $bounds->w, $bounds->h),
            usable: new Region($usable->x, $usable->y, $usable->w, $usable->h),
            scale: SDL_GetDisplayContentScale($id),
            current: self::mode($current),
            desktop: self::mode($desktop),
            hdr: new Hdr(SDL_GetBooleanProperty(SDL_GetDisplayProperties($id), SDL_PROP_DISPLAY_HDR_ENABLED_BOOLEAN, false)),
        );
    }

    /** @return list<DisplayMode> what exclusive fullscreen can run at on $id */
    public static function modes(int $id): array
    {
        return array_map(fn (SDL_DisplayMode $mode): DisplayMode => self::mode($mode), SDL_GetFullscreenDisplayModes($id) ?? []);
    }

    /** SDL's own mode a DisplayMode names; null when the display offers none like it. */
    public static function nativeMode(DisplayMode $mode): ?SDL_DisplayMode
    {
        foreach (SDL_GetFullscreenDisplayModes($mode->displayId) ?? [] as $native) {
            if (self::mode($native)->equals($mode)) {
                return $native;
            }
        }

        return null;
    }

    public static function mode(SDL_DisplayMode $mode): DisplayMode
    {
        return new DisplayMode($mode->displayID, $mode->w, $mode->h, $mode->pixel_density, $mode->refresh_rate);
    }
}
