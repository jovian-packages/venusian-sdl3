<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Values;

/** One draw as recorded mid-frame, replayed at flush. Texture is the SDL texture handle (never 0: the placeholder stands in). */
final readonly class RecordedDraw
{
    /**
     * @param array{int, int, int, int} $viewport
     * @param array{int, int, int, int}|null $scissor
     */
    public function __construct(
        public int $primitive,
        public string $vertices,
        public int $vertexCount,
        public ?string $indices,
        public int $indexCount,
        public string $transform,
        public int $texture,
        public int $instances,
        public array $viewport,
        public ?array $scissor,
    ) {}
}
