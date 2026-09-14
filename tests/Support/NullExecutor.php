<?php

declare(strict_types=1);

namespace Venusian\Tests\Support;

use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\ExecutorCapabilities;
use Surface\Contracts\Drawing\TextureHandle;
use Surface\Contracts\Drawing\Topology;
use Surface\Contracts\Drawing\Transform;
use Surface\Contracts\NativeWindows\Views\Color;

/** An executor that draws nothing and remembers whether it was released. */
final class NullExecutor implements Executor
{
    public bool $released = false;

    /** @var array{int, int} */
    public array $size = [1, 1];

    /** Thrown from release() after it marks itself released, when set. */
    public ?\Throwable $release_throws = null;

    public function capabilities(): ExecutorCapabilities
    {
        return new ExecutorCapabilities(blending: false, depth: false, instancing: false, readback: false, max_texture_size: 1);
    }

    public function resize(int $width, int $height): void
    {
        $this->size = [$width, $height];
    }

    public function drawableSize(): array
    {
        return $this->size;
    }

    public function beginFrame(Color $clear): bool
    {
        return false;
    }

    public function viewport(int $x, int $y, int $width, int $height): void {}

    public function scissor(int $x, int $y, int $width, int $height): void {}

    public function unscissor(): void {}

    public function texture(string $rgba8, int $width, int $height): TextureHandle
    {
        return new TextureHandle(1, $width, $height);
    }

    public function releaseTexture(TextureHandle $texture): void {}

    public function draw(Topology $topology, string $vertices, int $vertex_count, Transform $transform, ?TextureHandle $texture = null, int $instances = 1): void {}

    public function drawIndexed(Topology $topology, string $vertices, int $vertex_count, string $indices, int $index_count, Transform $transform, ?TextureHandle $texture = null, int $instances = 1): void {}

    public function readPixels(): string
    {
        return '';
    }

    public function endFrame(): void {}

    public function release(): void
    {
        $this->released = true;

        if (! is_null($this->release_throws)) {
            throw $this->release_throws;
        }
    }
}
