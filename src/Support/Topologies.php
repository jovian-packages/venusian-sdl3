<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Support;

use Jovian\Bindings\Sdl3\Enums\SDLGPUPrimitiveType;
use Surface\Contracts\Drawing\Topology;

/** Surface topology → SDL_GPU primitive. SDL_GPU pipelines bake the primitive; a line strip is expanded to a list so one LINELIST pipeline serves both. */
final class Topologies
{
    public static function primitiveFor(Topology $topology): int
    {
        return match ($topology) {
            Topology::TRIANGLES => SDLGPUPrimitiveType::TRIANGLELIST->value,
            Topology::TRIANGLE_STRIP => SDLGPUPrimitiveType::TRIANGLESTRIP->value,
            Topology::LINES, Topology::LINE_STRIP => SDLGPUPrimitiveType::LINELIST->value,
            Topology::POINTS => SDLGPUPrimitiveType::POINTLIST->value,
        };
    }

    /** @return array{string, int} 36-byte vertices as a line list, and the new count */
    public static function expandLineStrip(string $vertices, int $count): array
    {
        if ($count < 2) {
            return ['', 0];
        }

        $out = '';
        for ($i = 0; $i < $count - 1; $i++) {
            $out .= substr($vertices, $i * 36, 72);
        }

        return [$out, ($count - 1) * 2];
    }

    /** @return array{string, int} uint16 indices as a line list, and the new count */
    public static function expandLineStripIndices(string $indices, int $count): array
    {
        if ($count < 2) {
            return ['', 0];
        }

        $out = '';
        for ($i = 0; $i < $count - 1; $i++) {
            $out .= substr($indices, $i * 2, 4);
        }

        return [$out, ($count - 1) * 2];
    }
}
