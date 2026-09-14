<?php

declare(strict_types=1);

use Jovian\Bindings\Sdl3\Enums\SDLGPUPrimitiveType;
use Jovian\Venusian\Sdl3\Support\Topologies;
use Surface\Contracts\Drawing\Topology;

function vertexRow(float $x): string
{
    return pack('g9', $x, 0.0, 0.0, 1.0, 1.0, 1.0, 1.0, 0.0, 0.0);
}

it('maps Surface topologies to SDL_GPU primitive types', function () {
    expect(Topologies::primitiveFor(Topology::TRIANGLES))->toBe(SDLGPUPrimitiveType::TRIANGLELIST->value)
        ->and(Topologies::primitiveFor(Topology::TRIANGLE_STRIP))->toBe(SDLGPUPrimitiveType::TRIANGLESTRIP->value)
        ->and(Topologies::primitiveFor(Topology::LINES))->toBe(SDLGPUPrimitiveType::LINELIST->value)
        ->and(Topologies::primitiveFor(Topology::LINE_STRIP))->toBe(SDLGPUPrimitiveType::LINELIST->value)
        ->and(Topologies::primitiveFor(Topology::POINTS))->toBe(SDLGPUPrimitiveType::POINTLIST->value);
});

it('expands a line strip into a line list', function () {
    [$vertices, $count] = Topologies::expandLineStrip(vertexRow(0.0).vertexRow(1.0).vertexRow(2.0), 3);
    $xs = array_map(fn (int $i) => unpack('g', substr($vertices, $i * 36, 4))[1], range(0, $count - 1));

    expect($count)->toBe(4)
        ->and($xs)->toBe([0.0, 1.0, 1.0, 2.0]);
});

it('expands indexed line strips the same way', function () {
    [$indices, $count] = Topologies::expandLineStripIndices(pack('v*', 5, 6, 7), 3);

    expect($count)->toBe(4)
        ->and(array_values(unpack('v*', $indices)))->toBe([5, 6, 6, 7]);
});

it('a strip of fewer than two points draws nothing', function () {
    expect(Topologies::expandLineStrip(vertexRow(0.0), 1))->toBe(['', 0]);
});
