<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3;

use Jovian\Bindings\Sdl3\Enums\SDLFlipMode;
use Jovian\Bindings\Sdl3\Enums\SDLGPUBufferUsageFlags;
use Jovian\Bindings\Sdl3\Enums\SDLGPUFilter;
use Jovian\Bindings\Sdl3\Enums\SDLGPUIndexElementSize;
use Jovian\Bindings\Sdl3\Enums\SDLGPULoadOp;
use Jovian\Bindings\Sdl3\Enums\SDLGPUSampleCount;
use Jovian\Bindings\Sdl3\Enums\SDLGPUStoreOp;
use Jovian\Bindings\Sdl3\Enums\SDLGPUTextureType;
use Jovian\Bindings\Sdl3\Enums\SDLGPUTextureUsageFlags;
use Jovian\Bindings\Sdl3\Enums\SDLGPUTransferBufferUsage;
use Jovian\Bindings\Sdl3\Gpu\SDLGPU;
use Jovian\Bindings\Sdl3\SDLError;
use Jovian\Venusian\Sdl3\Contracts\Sdl3Drawing;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3DrawingException;
use Jovian\Venusian\Sdl3\Support\Pixels;
use Jovian\Venusian\Sdl3\Support\Topologies;
use Jovian\Venusian\Sdl3\Values\RecordedDraw;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\ExecutorCapabilities;
use Surface\Contracts\Drawing\TextureHandle;
use Surface\Contracts\Drawing\Topology;
use Surface\Contracts\Drawing\Transform;
use Surface\Contracts\NativeWindows\Views\Color;

/**
 * Surface's Executor on SDL_GPU. A swapchain texture belongs to the one
 * command buffer that acquired it, so draws are recorded during the frame
 * and replayed into an offscreen colour target: at endFrame() on the frame
 * command buffer (then blitted to the swapchain and submitted), or mid-frame
 * on readPixels()'s own command buffer. The frame command buffer records
 * nothing until endFrame(), which is what keeps submission order honest.
 * A texture released mid-frame is released after the frame's submit.
 */
final class Sdl3GpuExecutor implements Executor, Sdl3Drawing
{
    /** @var array{cb: int, swapchain: int, cleared: bool, clear: Color}|null */
    private ?array $frame = null;

    /** @var list<RecordedDraw> */
    private array $recorded = [];

    /** @var array<int, int> handle id => SDL texture */
    private array $textures = [];

    /** @var list<int> SDL textures released mid-frame; a recorded draw may still bind them */
    private array $deferred = [];

    private int $nextTextureId = 1;

    private int $offscreen = 0;
    private int $offscreenWidth = 0;
    private int $offscreenHeight = 0;

    private int $vertexBuffer = 0;
    private int $vertexCapacity = 0;
    private int $indexBuffer = 0;
    private int $indexCapacity = 0;
    private int $uploadBuffer = 0;
    private int $uploadCapacity = 0;
    private int $downloadBuffer = 0;
    private int $downloadCapacity = 0;

    /** @var array{int, int, int, int} */
    private array $viewport;

    /** @var array{int, int, int, int}|null */
    private ?array $scissor = null;

    private bool $released = false;

    public function __construct(
        private readonly Sdl3GpuContext $context,
        private readonly int $windowHandle,
        private readonly int $format,
        private int $pixelWidth,
        private int $pixelHeight,
    ) {
        $this->viewport = [0, 0, $pixelWidth, $pixelHeight];
    }

    public static function declaredCapabilities(): ExecutorCapabilities
    {
        return new ExecutorCapabilities(blending: true, depth: false, instancing: true, readback: true, max_texture_size: 8192);
    }

    public function capabilities(): ExecutorCapabilities
    {
        return self::declaredCapabilities();
    }

    public function device(): int
    {
        return $this->context->device;
    }

    public function window(): int
    {
        return $this->windowHandle;
    }

    public function commandBuffer(): int
    {
        return $this->frame['cb'] ?? throw Sdl3DrawingException::outOfFrame('commandBuffer()');
    }

    public function offscreen(): int
    {
        return $this->offscreen;
    }

    /** Textures released this frame, waiting for its submit. */
    public function deferredReleases(): int
    {
        return count($this->deferred);
    }

    /** The swapchain follows the window; the offscreen target follows the swapchain at the next beginFrame(). */
    public function resize(int $width, int $height): void
    {
        $this->pixelWidth = max(1, $width);
        $this->pixelHeight = max(1, $height);
    }

    public function drawableSize(): array
    {
        return [$this->pixelWidth, $this->pixelHeight];
    }

    /** A frame still open is refused, never overwritten. A command buffer this opens is always cancelled or submitted. */
    public function beginFrame(Color $clear): bool
    {
        $this->guardLive();
        if (! is_null($this->frame)) {
            throw Sdl3DrawingException::frameOpen();
        }

        try {
            $cb = SDLGPU::SDLAcquireGPUCommandBuffer($this->context->device);
        } catch (\RuntimeException $e) {
            throw Sdl3DrawingException::from($e);
        }

        try {
            $swapchain = SDLGPU::SDLWaitAndAcquireGPUSwapchainTexture($cb, $this->windowHandle);
        } catch (\RuntimeException $e) {
            SDLGPU::SDLCancelGPUCommandBuffer($cb);   // nothing acquired: cancelling is legal
            throw Sdl3DrawingException::from($e);
        }

        $texture = (int) ($swapchain['texture'] ?? 0);
        if ($texture === 0) {
            SDLGPU::SDLCancelGPUCommandBuffer($cb);

            return false;
        }

        try {
            $this->ensureOffscreen((int) $swapchain['width'], (int) $swapchain['height']);
        } catch (\RuntimeException $e) {
            SDLGPU::SDLSubmitGPUCommandBuffer($cb);   // a swapchain texture was acquired: submit, never cancel
            throw Sdl3DrawingException::from($e);
        }

        $this->frame = ['cb' => $cb, 'swapchain' => $texture, 'cleared' => false, 'clear' => $clear];
        $this->recorded = [];
        $this->viewport = [0, 0, $this->offscreenWidth, $this->offscreenHeight];
        $this->scissor = null;

        return true;
    }

    public function viewport(int $x, int $y, int $width, int $height): void
    {
        $this->viewport = [$x, $y, $width, $height];
    }

    public function scissor(int $x, int $y, int $width, int $height): void
    {
        $this->scissor = [$x, $y, $width, $height];
    }

    public function unscissor(): void
    {
        $this->scissor = null;
    }

    public function texture(string $rgba8, int $width, int $height): TextureHandle
    {
        $this->guardLive();

        try {
            $sdl = $this->context->uploadTexture($rgba8, $width, $height);
        } catch (\RuntimeException $e) {
            throw Sdl3DrawingException::from($e);
        }

        $id = $this->nextTextureId++;
        $this->textures[$id] = $sdl;

        return new TextureHandle($id, $width, $height);
    }

    /** The handle dies now. Mid-frame a recorded draw may still bind the texture, so SDL's release waits for the frame's submit. */
    public function releaseTexture(TextureHandle $texture): void
    {
        $sdl = $this->textures[$texture->id] ?? throw Sdl3DrawingException::unknownTexture($texture->id);
        unset($this->textures[$texture->id]);

        if (is_null($this->frame)) {
            SDLGPU::SDLReleaseGPUTexture($this->context->device, $sdl);

            return;
        }

        $this->deferred[] = $sdl;
    }

    public function draw(Topology $topology, string $vertices, int $vertex_count, Transform $transform, ?TextureHandle $texture = null, int $instances = 1): void
    {
        $this->guardFrame('draw()');
        if ($topology === Topology::LINE_STRIP) {
            [$vertices, $vertex_count] = Topologies::expandLineStrip($vertices, $vertex_count);
        }

        $this->record(Topologies::primitiveFor($topology), $vertices, $vertex_count, null, 0, $transform, $texture, $instances);
    }

    public function drawIndexed(Topology $topology, string $vertices, int $vertex_count, string $indices, int $index_count, Transform $transform, ?TextureHandle $texture = null, int $instances = 1): void
    {
        $this->guardFrame('drawIndexed()');
        if ($topology === Topology::LINE_STRIP) {
            [$indices, $index_count] = Topologies::expandLineStripIndices($indices, $index_count);
        }

        $this->record(Topologies::primitiveFor($topology), $vertices, $vertex_count, $indices, $index_count, $transform, $texture, $instances);
    }

    /**
     * Mid-frame: flush pending draws on a command buffer of its own, download
     * the offscreen target, wait. RGBA8, top-left first. On failure the
     * buffer is cancelled while the draws are still recorded, submitted once
     * they have been flushed into it.
     */
    public function readPixels(): string
    {
        $this->guardFrame('readPixels()');
        $device = $this->context->device;

        try {
            $cb = SDLGPU::SDLAcquireGPUCommandBuffer($device);
        } catch (\RuntimeException $e) {
            throw Sdl3DrawingException::from($e);
        }

        $flushed = false;
        $submitted = false;
        try {
            $this->flush($cb);
            $flushed = true;

            $bytes = $this->offscreenWidth * $this->offscreenHeight * 4;
            $this->growTransfer($this->downloadBuffer, $this->downloadCapacity, $bytes, SDLGPUTransferBufferUsage::DOWNLOAD->value);
            $copy = SDLGPU::SDLBeginGPUCopyPass($cb);
            SDLGPU::SDLDownloadFromGPUTexture(
                $copy,
                ['texture' => $this->offscreen, 'mip_level' => 0, 'layer' => 0, 'x' => 0, 'y' => 0, 'z' => 0, 'w' => $this->offscreenWidth, 'h' => $this->offscreenHeight, 'd' => 1],
                ['transfer_buffer' => $this->downloadBuffer, 'offset' => 0, 'pixels_per_row' => $this->offscreenWidth, 'rows_per_layer' => $this->offscreenHeight],
            );
            SDLGPU::SDLEndGPUCopyPass($copy);

            $submitted = true;   // SDL takes the buffer whether or not a fence comes back
            $fence = SDLGPU::SDLSubmitGPUCommandBufferAndAcquireFence($cb);
            SDLGPU::SDLWaitForGPUFences($device, true, [$fence]);
            SDLGPU::SDLReleaseGPUFence($device, $fence);

            $raw = SDLGPU::readFromGPUTransferBuffer($device, $this->downloadBuffer, $bytes, 0);
        } catch (\RuntimeException $e) {
            if (! $submitted && $flushed) {
                SDLGPU::SDLSubmitGPUCommandBuffer($cb);   // the flushed draws left the record: they must land
            } elseif (! $submitted) {
                SDLGPU::SDLCancelGPUCommandBuffer($cb);   // no swapchain texture here; the draws stay recorded
            }

            throw Sdl3DrawingException::from($e);
        }

        return Pixels::isBgra($this->format) ? Pixels::swizzleBgraToRgba($raw) : $raw;
    }

    /** Flush, blit, submit. Frame state is dropped and deferred releases run whatever the flush does; a failed submit throws after that. */
    public function endFrame(): void
    {
        $this->guardFrame('endFrame()');
        $cb = $this->frame['cb'];

        try {
            try {
                $this->flush($cb);

                SDLGPU::SDLBlitGPUTexture($cb, [
                    'source' => ['texture' => $this->offscreen, 'mip_level' => 0, 'layer_or_depth_plane' => 0, 'x' => 0, 'y' => 0, 'w' => $this->offscreenWidth, 'h' => $this->offscreenHeight],
                    'destination' => ['texture' => $this->frame['swapchain'], 'mip_level' => 0, 'layer_or_depth_plane' => 0, 'x' => 0, 'y' => 0, 'w' => $this->offscreenWidth, 'h' => $this->offscreenHeight],
                    'load_op' => SDLGPULoadOp::DONT_CARE->value,
                    'filter' => SDLGPUFilter::NEAREST->value,
                    'flip_mode' => SDLFlipMode::NONE->value,
                    'cycle' => false,
                ]);
            } catch (\RuntimeException $e) {
                throw Sdl3DrawingException::from($e);
            }
        } finally {
            $submitted = SDLGPU::SDLSubmitGPUCommandBuffer($cb);   // the swapchain texture rides it: submit, never cancel
            $this->frame = null;
            $this->recorded = [];
            $this->releaseDeferred();
        }

        if (! $submitted) {
            throw Sdl3DrawingException::submitFailed(SDLError::SDLGetError());
        }
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $device = $this->context->device;
        if (! is_null($this->frame)) {
            SDLGPU::SDLSubmitGPUCommandBuffer($this->frame['cb']);   // a swapchain texture was acquired: submit, never cancel
            $this->frame = null;
        }
        $this->recorded = [];

        SDLGPU::SDLWaitForGPUIdle($device);
        $this->releaseDeferred();
        foreach ($this->textures as $texture) {
            SDLGPU::SDLReleaseGPUTexture($device, $texture);
        }
        if ($this->offscreen !== 0) {
            SDLGPU::SDLReleaseGPUTexture($device, $this->offscreen);
        }
        foreach ([$this->vertexBuffer, $this->indexBuffer] as $buffer) {
            if ($buffer !== 0) {
                SDLGPU::SDLReleaseGPUBuffer($device, $buffer);
            }
        }
        foreach ([$this->uploadBuffer, $this->downloadBuffer] as $transfer) {
            if ($transfer !== 0) {
                SDLGPU::SDLReleaseGPUTransferBuffer($device, $transfer);
            }
        }
        SDLGPU::SDLReleaseWindowFromGPUDevice($device, $this->windowHandle);

        $this->textures = [];
        $this->released = true;
    }

    /** Nothing to draw: no vertices, or an indexed draw without indices (a one-point strip expands to none). */
    private function record(int $primitive, string $vertices, int $vertexCount, ?string $indices, int $indexCount, Transform $transform, ?TextureHandle $texture, int $instances): void
    {
        if ($vertexCount === 0 || (! is_null($indices) && $indexCount === 0)) {
            return;
        }

        $sdlTexture = is_null($texture)
            ? $this->context->placeholder
            : ($this->textures[$texture->id] ?? throw Sdl3DrawingException::unknownTexture($texture->id));

        $this->recorded[] = new RecordedDraw($primitive, $vertices, $vertexCount, $indices, $indexCount, $transform->toPacked(), $sdlTexture, max(1, $instances), $this->viewport, $this->scissor);
    }

    /**
     * Upload every recorded draw's geometry, then replay the draws into the
     * offscreen target — clearing on the frame's first flush. Everything that
     * can throw runs before the render pass opens.
     */
    private function flush(int $cb): void
    {
        $frame = $this->frame;
        if ($this->recorded === [] && $frame['cleared']) {
            return;
        }

        $pipelines = [];
        $vertexBytes = '';
        $indexBytes = '';
        $offsets = [];
        foreach ($this->recorded as $draw) {
            $pipelines[] = $this->context->pipeline($this->format, $draw->primitive);
            $offsets[] = [strlen($vertexBytes), strlen($indexBytes)];
            $vertexBytes .= $draw->vertices;
            if (! is_null($draw->indices)) {
                $indexBytes .= $draw->indices;
                if (strlen($indexBytes) % 4 !== 0) {
                    $indexBytes .= "\0\0";
                }
            }
        }

        if ($vertexBytes !== '') {
            $this->uploadGeometry($cb, $vertexBytes, $indexBytes);
        }

        $clear = $frame['clear'];
        $pass = SDLGPU::SDLBeginGPURenderPass($cb, [[
            'texture' => $this->offscreen,
            'clear_color' => ['r' => $clear->red, 'g' => $clear->green, 'b' => $clear->blue, 'a' => $clear->alpha],
            'load_op' => $frame['cleared'] ? SDLGPULoadOp::LOAD->value : SDLGPULoadOp::CLEAR->value,
            'store_op' => SDLGPUStoreOp::STORE->value,
        ]]);

        foreach ($this->recorded as $i => $draw) {
            [$sx, $sy, $sw, $sh] = $this->clampScissor($draw->scissor);
            if ($sw === 0 || $sh === 0) {
                continue;   // clipped to nothing: skipped, never handed to the backend as an empty rect
            }

            [$vertexOffset, $indexOffset] = $offsets[$i];
            [$vx, $vy, $vw, $vh] = $draw->viewport;

            SDLGPU::SDLSetGPUViewport($pass, ['x' => (float) $vx, 'y' => (float) $vy, 'w' => (float) $vw, 'h' => (float) $vh, 'min_depth' => 0.0, 'max_depth' => 1.0]);
            SDLGPU::SDLSetGPUScissor($pass, ['x' => $sx, 'y' => $sy, 'w' => $sw, 'h' => $sh]);
            SDLGPU::SDLBindGPUGraphicsPipeline($pass, $pipelines[$i]);
            SDLGPU::SDLPushGPUVertexUniformData($cb, 0, $draw->transform);
            SDLGPU::SDLBindGPUVertexBuffers($pass, 0, [['buffer' => $this->vertexBuffer, 'offset' => $vertexOffset]]);
            SDLGPU::SDLBindGPUFragmentSamplers($pass, 0, [['texture' => $draw->texture, 'sampler' => $this->context->sampler]]);

            if (is_null($draw->indices)) {
                SDLGPU::SDLDrawGPUPrimitives($pass, $draw->vertexCount, $draw->instances, 0, 0);
            } else {
                SDLGPU::SDLBindGPUIndexBuffer($pass, ['buffer' => $this->indexBuffer, 'offset' => $indexOffset], SDLGPUIndexElementSize::GPU_INDEXELEMENTSIZE_16BIT);
                SDLGPU::SDLDrawGPUIndexedPrimitives($pass, $draw->indexCount, $draw->instances, 0, 0, 0);
            }
        }

        SDLGPU::SDLEndGPURenderPass($pass);
        $this->recorded = [];
        $this->frame['cleared'] = true;
    }

    /** One transfer buffer carries vertices then indices; both GPU buffers cycle so in-flight frames keep their data. */
    private function uploadGeometry(int $cb, string $vertices, string $indices): void
    {
        $device = $this->context->device;
        $this->growTransfer($this->uploadBuffer, $this->uploadCapacity, strlen($vertices) + strlen($indices), SDLGPUTransferBufferUsage::UPLOAD->value);
        $this->growBuffer($this->vertexBuffer, $this->vertexCapacity, strlen($vertices), SDLGPUBufferUsageFlags::VERTEX->value);
        if ($indices !== '') {
            $this->growBuffer($this->indexBuffer, $this->indexCapacity, strlen($indices), SDLGPUBufferUsageFlags::INDEX->value);
        }

        SDLGPU::writeToGPUTransferBuffer($device, $this->uploadBuffer, $vertices.$indices, true, 0);
        $copy = SDLGPU::SDLBeginGPUCopyPass($cb);
        SDLGPU::SDLUploadToGPUBuffer($copy, ['transfer_buffer' => $this->uploadBuffer, 'offset' => 0], ['buffer' => $this->vertexBuffer, 'offset' => 0, 'size' => strlen($vertices)], true);
        if ($indices !== '') {
            SDLGPU::SDLUploadToGPUBuffer($copy, ['transfer_buffer' => $this->uploadBuffer, 'offset' => strlen($vertices)], ['buffer' => $this->indexBuffer, 'offset' => 0, 'size' => strlen($indices)], true);
        }
        SDLGPU::SDLEndGPUCopyPass($copy);
    }

    private function growBuffer(int &$handle, int &$capacity, int $needed, int $usage): void
    {
        if ($handle !== 0 && $needed <= $capacity) {
            return;
        }

        if ($handle !== 0) {
            SDLGPU::SDLReleaseGPUBuffer($this->context->device, $handle);   // deferred until unused
        }

        $capacity = max(4096, 1 << (int) ceil(log(max(1, $needed), 2)));
        $handle = SDLGPU::SDLCreateGPUBuffer($this->context->device, ['usage' => $usage, 'size' => $capacity]);
    }

    private function growTransfer(int &$handle, int &$capacity, int $needed, int $usage): void
    {
        if ($handle !== 0 && $needed <= $capacity) {
            return;
        }

        if ($handle !== 0) {
            SDLGPU::SDLReleaseGPUTransferBuffer($this->context->device, $handle);
        }

        $capacity = max(4096, 1 << (int) ceil(log(max(1, $needed), 2)));
        $handle = SDLGPU::SDLCreateGPUTransferBuffer($this->context->device, ['usage' => $usage, 'size' => $capacity]);
    }

    private function ensureOffscreen(int $width, int $height): void
    {
        if ($this->offscreen !== 0 && $width === $this->offscreenWidth && $height === $this->offscreenHeight) {
            return;
        }

        if ($this->offscreen !== 0) {
            SDLGPU::SDLReleaseGPUTexture($this->context->device, $this->offscreen);
        }

        // Blit sources need SAMPLER usage; draws need COLOR_TARGET. Same format as the swapchain.
        $this->offscreen = SDLGPU::SDLCreateGPUTexture($this->context->device, [
            'type' => SDLGPUTextureType::GPU_TEXTURETYPE_2D->value,
            'format' => $this->format,
            'usage' => SDLGPUTextureUsageFlags::COLOR_TARGET->value | SDLGPUTextureUsageFlags::SAMPLER->value,
            'width' => $width,
            'height' => $height,
            'layer_count_or_depth' => 1,
            'num_levels' => 1,
            'sample_count' => SDLGPUSampleCount::GPU_SAMPLECOUNT_1->value,
        ]);
        $this->offscreenWidth = $width;
        $this->offscreenHeight = $height;
        $this->pixelWidth = $width;
        $this->pixelHeight = $height;
    }

    /** Runs after a submit: SDL holds each texture until the GPU is done with it. */
    private function releaseDeferred(): void
    {
        foreach ($this->deferred as $texture) {
            SDLGPU::SDLReleaseGPUTexture($this->context->device, $texture);
        }

        $this->deferred = [];
    }

    /**
     * @param array{int, int, int, int}|null $scissor
     * @return array{int, int, int, int}
     */
    private function clampScissor(?array $scissor): array
    {
        if (is_null($scissor)) {
            return [0, 0, $this->offscreenWidth, $this->offscreenHeight];
        }

        [$x, $y, $w, $h] = $scissor;
        $x0 = max(0, min($x, $this->offscreenWidth));
        $y0 = max(0, min($y, $this->offscreenHeight));

        return [$x0, $y0, max(0, min($x + $w, $this->offscreenWidth) - $x0), max(0, min($y + $h, $this->offscreenHeight) - $y0)];
    }

    private function guardLive(): void
    {
        if ($this->released) {
            throw Sdl3DrawingException::released();
        }
    }

    private function guardFrame(string $operation): void
    {
        $this->guardLive();
        if (is_null($this->frame)) {
            throw Sdl3DrawingException::outOfFrame($operation);
        }
    }
}
