<?php

namespace Jovian\Engines\Sdl3;

use SDL_FColor;
use SDL_GPUBuffer;
use SDL_GPUBufferBinding;
use SDL_GPUColorTargetInfo;
use SDL_GPUCommandBuffer;
use SDL_GPUDepthStencilTargetInfo;
use SDL_GPURenderPass;
use SDL_GPUTextureLocation;
use SDL_GPUTextureSamplerBinding;
use SDL_Rect;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\NutsAndBolts\Color;

/**
 * The sdl3 engine's framebuffer: an RGBA8 texture on the GPU holding premultiplied colour, and a four-sample twin
 * that antialiased shapes are drawn into and resolved from. pixels() gives straight colour: the GPU converts on the
 * way out.
 *
 * A write to the texture leaves the twin behind; it is filled from the texture again before the next antialiased
 * draw. Drawing is handed to the GPU and not waited for: SDL runs it in order, so whatever is drawn or read next sees
 * it. Only pixels() waits, for the bytes it gives back.
 *
 * A transient framebuffer (a layer's sheet) is drawn from transparent black every time: its twin is never filled
 * from it.
 */
class SDLGPUFramebuffer implements PaintTarget
{
    /** Samples a pixel when edges are antialiased. */
    public const int SAMPLES = 4;

    protected readonly SDLGPUTexture $texture;

    protected readonly SDLGPUTexture $samples;

    protected readonly SDLGPUTexture $stencil;

    protected readonly SDLGPUTexture $hardStencil;

    /** Straight colour on its way out: made the first time it is needed. */
    protected ?SDLGPUTexture $staging = null;

    /** Whether the four-sample twin is behind the texture: written to since it was last resolved. */
    protected bool $stale = false;

    /** @throws SDL3Exception */
    public function __construct(
        protected readonly SDLGPUDevice $device,
        protected readonly int $width,
        protected readonly int $height,
        protected readonly bool $transient = false,
    ) {
        $rgba = SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM;
        $this->texture = $device->texture($rgba, $width, $height, 1, SDL_GPU_TEXTUREUSAGE_SAMPLER | SDL_GPU_TEXTUREUSAGE_COLOR_TARGET);
        $this->samples = $device->texture($rgba, $width, $height, self::SAMPLES, SDL_GPU_TEXTUREUSAGE_COLOR_TARGET);
        $this->stencil = $device->texture($device->stencilFormat(), $width, $height, self::SAMPLES, SDL_GPU_TEXTUREUSAGE_DEPTH_STENCIL_TARGET);
        $this->hardStencil = $device->texture($device->stencilFormat(), $width, $height, 1, SDL_GPU_TEXTUREUSAGE_DEPTH_STENCIL_TARGET);

        if (! $transient) {
            // defined from the start: transparent black, as every new framebuffer holds
            $this->clear(Color::rgba(0, 0, 0, 0.0));
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function format(): FormatSpec
    {
        return FormatSpec::rgba8();
    }

    /** @throws SDL3Exception */
    public function clear(Color $color): static
    {
        // the colour as its bytes, then premultiplied, as the texture holds it
        $alpha = round($color->alpha * 255) / 255;
        $byte = fn (float $channel): float => round($channel * 255) / 255 * $alpha;
        // the twin and the texture both: the twin cleared and resolved into the texture
        $commands = $this->device->commands();
        $target = $this->target(true, SDL_GPU_LOADOP_CLEAR);
        $target->clear_color = new SDL_FColor($byte($color->red), $byte($color->green), $byte($color->blue), $alpha);
        $pass = SDL_BeginGPURenderPass($commands, [$target], 1, null) ?? throw SDL3Exception::fromSdl('no render pass could be begun');
        SDL_EndGPURenderPass($pass);
        $this->device->submit($commands);
        $this->stale = false;

        return $this;
    }

    /**
     * The texture: premultiplied colour. What was drawn is in it for anything submitted afterwards.
     *
     * @internal
     */
    public function texture(): SDLGPUTexture
    {
        return $this->texture;
    }

    // PaintTarget: what a painter needs to know of what it draws on

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    /** The texture keeps what was drawn until it is drawn over. */
    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    /**
     * Moves the pixels of $region by ($dx, $dy) on the GPU: what moves past the region's edge is dropped, and the
     * strip left behind keeps its old pixels. The move goes through a copy, so a region that overlaps itself moves
     * whole.
     *
     * @throws SDL3Exception
     */
    public function scroll(Region $region, int $dx, int $dy): void
    {
        $area = Region::wholeSurface($this->width, $this->height)->intersect($region);
        $from = $area?->intersect(new Region($area->x - $dx, $area->y - $dy, $area->width, $area->height));
        if (is_null($from) || $from->isEmpty() || ($dx === 0 && $dy === 0)) {
            return;
        }
        $copy = $this->device->texture(SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM, $from->width, $from->height, 1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
        $location = function (SDLGPUTexture $texture, int $x, int $y): SDL_GPUTextureLocation {
            $at = new SDL_GPUTextureLocation();
            [$at->texture, $at->x, $at->y] = [$texture->texture, $x, $y];

            return $at;
        };

        $commands = $this->device->commands();
        $pass = SDL_BeginGPUCopyPass($commands) ?? throw SDL3Exception::fromSdl('no copy pass could be begun');
        SDL_CopyGPUTextureToTexture($pass, $location($this->texture, $from->x, $from->y), $location($copy, 0, 0), $from->width, $from->height, 1, false);
        SDL_CopyGPUTextureToTexture($pass, $location($copy, 0, 0), $location($this->texture, $from->x + $dx, $from->y + $dy), $from->width, $from->height, 1, false);
        SDL_EndGPUCopyPass($pass);
        $this->device->submit($commands);
        $this->stale = true;
    }

    /**
     * Opens a render pass for drawing: $vertices at slot 0, and the framebuffer's size and where on the surface it
     * starts ($ox, $oy: vertices are in surface pixels) as the vertex uniform. Over the four-sample twin when
     * $antialiased, resolved into the texture at the end; otherwise over the texture. With $clear it starts from
     * transparent black; otherwise from what it holds, the twin filled from the texture first when it is behind.
     * The stencil starts at 0.
     *
     * @internal
     *
     * @throws SDL3Exception
     */
    public function begin(SDL_GPUCommandBuffer $commands, bool $antialiased, SDL_GPUBuffer $vertices, int $ox = 0, int $oy = 0, bool $clear = false): SDL_GPURenderPass
    {
        if ($clear) {
            $target = $this->target($antialiased, SDL_GPU_LOADOP_CLEAR);
            $target->clear_color = new SDL_FColor(0.0, 0.0, 0.0, 0.0);
            $this->stale = $antialiased ? false : $this->stale;
        } else {
            if ($this->transient) {
                throw SDL3Exception::because('a transient framebuffer is drawn from transparent black every time.');
            }
            if ($antialiased && $this->stale) {
                $this->restore($commands);
            }
            $target = $this->target($antialiased, SDL_GPU_LOADOP_LOAD);
        }

        return $this->open($commands, $target, $antialiased ? $this->stencil : $this->hardStencil, $vertices, $ox, $oy);
    }

    /**
     * After a draw: a draw straight into the texture leaves the twin behind.
     *
     * @internal
     */
    public function drew(bool $antialiased): void
    {
        if (! $antialiased) {
            $this->stale = true;
        }
    }

    /**
     * One pass of $fragment (a 'cover' fragment: one triangle over the framebuffer) into the texture over $region,
     * replacing what is there: $textures at 0, 1, …, $bytes the fragment's uniform. The twin is left behind.
     *
     * @internal
     *
     * @param list<SDLGPUTexture> $textures
     *
     * @throws SDL3Exception
     */
    public function cover(SDL_GPUCommandBuffer $commands, Region $region, string $fragment, array $textures, string $bytes): void
    {
        $pipeline = $this->device->pipeline($fragment, 'cover', blend: false);
        $pass = SDL_BeginGPURenderPass($commands, [$this->target(false, SDL_GPU_LOADOP_LOAD)], 1, null) ?? throw SDL3Exception::fromSdl('no render pass could be begun');
        SDL_SetGPUScissor($pass, new SDL_Rect($region->x, $region->y, $region->width, $region->height));
        SDL_BindGPUGraphicsPipeline($pass, $pipeline);
        $this->bindTextures($pass, $textures);
        SDL_PushGPUFragmentUniformData($commands, 0, $bytes, strlen($bytes));
        SDL_DrawGPUPrimitives($pass, 3, 1, 0, 0);
        SDL_EndGPURenderPass($pass);
        $this->stale = true;
    }

    /**
     * Binds $textures to the fragment stage at 0, 1, …: SDL pairs each with a sampler, which these shaders do not use.
     *
     * @internal
     *
     * @param list<SDLGPUTexture> $textures
     */
    public function bindTextures(SDL_GPURenderPass $pass, array $textures): void
    {
        if ($textures === []) {
            return;
        }
        $bindings = array_map(function (SDLGPUTexture $texture): SDL_GPUTextureSamplerBinding {
            $binding = new SDL_GPUTextureSamplerBinding();
            $binding->texture = $texture->texture;
            $binding->sampler = $this->device->sampler();

            return $binding;
        }, array_values($textures));
        SDL_BindGPUFragmentSamplers($pass, 0, $bindings, count($bindings));
    }

    /** The device it is on: a framebuffer works only with that device's painters and casters. */
    public function device(): SDLGPUDevice
    {
        return $this->device;
    }

    /**
     * $region's pixels as straight RGBA8, rows from the top. Waits for what the GPU is drawing.
     *
     * @throws SDL3Exception
     */
    public function pixels(Region $region): string
    {
        // the texture's premultiplied colour, straight, into the staging texture; then its rows out
        $this->staging ??= $this->device->texture(SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM, $this->width, $this->height, 1, SDL_GPU_TEXTUREUSAGE_SAMPLER | SDL_GPU_TEXTUREUSAGE_COLOR_TARGET);
        $commands = $this->device->commands();
        $pass = SDL_BeginGPURenderPass($commands, [$this->targetOn($this->staging, SDL_GPU_LOADOP_DONT_CARE)], 1, null) ?? throw SDL3Exception::fromSdl('no render pass could be begun');
        SDL_SetGPUScissor($pass, new SDL_Rect($region->x, $region->y, $region->width, $region->height));
        SDL_BindGPUGraphicsPipeline($pass, $this->device->pipeline('unpremultiply_whole', 'cover', blend: false));
        $this->bindTextures($pass, [$this->texture]);
        SDL_DrawGPUPrimitives($pass, 3, 1, 0, 0);
        SDL_EndGPURenderPass($pass);

        return $this->device->read($commands, $this->staging, $region);
    }

    /** One pass that draws the texture into every sample of the twin. @throws SDL3Exception */
    protected function restore(SDL_GPUCommandBuffer $commands): void
    {
        $target = new SDL_GPUColorTargetInfo();
        $target->texture = $this->samples->texture;
        $target->load_op = SDL_GPU_LOADOP_DONT_CARE;
        $target->store_op = SDL_GPU_STOREOP_STORE;
        $pipeline = $this->device->pipeline('copy_whole', 'cover', self::SAMPLES, blend: false);
        $pass = SDL_BeginGPURenderPass($commands, [$target], 1, null) ?? throw SDL3Exception::fromSdl('no render pass could be begun');
        SDL_BindGPUGraphicsPipeline($pass, $pipeline);
        $this->bindTextures($pass, [$this->texture]);
        SDL_DrawGPUPrimitives($pass, 3, 1, 0, 0);
        SDL_EndGPURenderPass($pass);
        $this->stale = false;
    }

    /** @throws SDL3Exception */
    protected function open(SDL_GPUCommandBuffer $commands, SDL_GPUColorTargetInfo $target, SDLGPUTexture $stencil, SDL_GPUBuffer $vertices, int $ox, int $oy): SDL_GPURenderPass
    {
        $depth = new SDL_GPUDepthStencilTargetInfo();
        $depth->texture = $stencil->texture;
        $depth->load_op = SDL_GPU_LOADOP_DONT_CARE;
        $depth->store_op = SDL_GPU_STOREOP_DONT_CARE;
        $depth->stencil_load_op = SDL_GPU_LOADOP_CLEAR;
        $depth->stencil_store_op = SDL_GPU_STOREOP_DONT_CARE;
        $depth->clear_stencil = 0;

        $pass = SDL_BeginGPURenderPass($commands, [$target], 1, $depth) ?? throw SDL3Exception::fromSdl('no render pass could be begun');
        $binding = new SDL_GPUBufferBinding();
        $binding->buffer = $vertices;
        SDL_BindGPUVertexBuffers($pass, 0, [$binding], 1);
        SDL_PushGPUVertexUniformData($commands, 0, pack('g4', (float) $this->width, (float) $this->height, (float) $ox, (float) $oy), 16);

        return $pass;
    }

    /** The colour target of a pass over the texture, or over the twin resolved into it ($antialiased). */
    protected function target(bool $antialiased, int $load): SDL_GPUColorTargetInfo
    {
        if (! $antialiased) {
            return $this->targetOn($this->texture, $load);
        }
        $target = new SDL_GPUColorTargetInfo();
        $target->texture = $this->samples->texture;
        $target->resolve_texture = $this->texture->texture;
        $target->load_op = $load;
        // a transient's samples are resolved and dropped
        $target->store_op = $this->transient ? SDL_GPU_STOREOP_RESOLVE : SDL_GPU_STOREOP_RESOLVE_AND_STORE;

        return $target;
    }

    protected function targetOn(SDLGPUTexture $texture, int $load): SDL_GPUColorTargetInfo
    {
        $target = new SDL_GPUColorTargetInfo();
        $target->texture = $texture->texture;
        $target->load_op = $load;
        $target->store_op = SDL_GPU_STOREOP_STORE;

        return $target;
    }
}
