<?php

namespace Jovian\Engines\Sdl3;

use Closure;
use SDL_FColor;
use SDL_GPUBuffer;
use SDL_GPUBufferCreateInfo;
use SDL_GPUBufferRegion;
use SDL_GPUColorTargetDescription;
use SDL_GPUCommandBuffer;
use SDL_GPUDevice;
use SDL_GPUFence;
use SDL_GPUGraphicsPipeline;
use SDL_GPUGraphicsPipelineCreateInfo;
use SDL_GPUSampler;
use SDL_GPUSamplerCreateInfo;
use SDL_GPUShader;
use SDL_GPUShaderCreateInfo;
use SDL_GPUTextureCreateInfo;
use SDL_GPUTextureRegion;
use SDL_GPUTextureTransferInfo;
use SDL_GPUTransferBuffer;
use SDL_GPUTransferBufferCreateInfo;
use SDL_GPUTransferBufferLocation;
use SDL_GPUVertexAttribute;
use SDL_GPUVertexBufferDescription;
use Surface\Contracts\Graphics\ActorStage;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\CastsRays;
use Surface\Contracts\Graphics\Feature;
use Surface\Contracts\Graphics\GraphicsException;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Recording\Recorder;

/**
 * The sdl3 engine on the GPU: one SDL_GPU device, and what it makes. SDL picks the GPU API the platform has (Metal
 * on macOS, Vulkan on Linux) and this engine hands it shaders in that API's language. Every surface and raycaster it
 * hands out works on that device.
 *
 * It has every Feature: gradients, the effects of layer() and glass(), scrolling, and a raycaster that walks the grid
 * on the GPU.
 *
 * SDL_GPU folds what Metal keeps apart into one pipeline: the shaders, the blend, the stencil test and the formats of
 * the targets. So a pipeline here is kept by all of them together.
 */
class SDLGPUDevice extends SDL3Device implements CastsRays
{
    /** On Metal: compiled from one source, in this order, each entry point a shader of its own. */
    protected const array MSL = [__DIR__.'/../resources/shaders/engine.metal', __DIR__.'/../resources/shaders/filters.metal', __DIR__.'/../resources/shaders/raycast.metal'];

    /** On Vulkan: each entry point its own GLSL source here, compiled ahead to <entry>.<stage>.spv beside it. */
    protected const string SPIRV = __DIR__.'/../resources/shaders/spirv';

    /** How many submissions the GPU may be working through before the CPU waits for the oldest. */
    protected const int IN_FLIGHT = 3;

    protected readonly SDL_GPUDevice $gpu;

    /** The shader language SDL's driver takes: SDL_GPU_SHADERFORMAT_MSL (Metal) or SDL_GPU_SHADERFORMAT_SPIRV (Vulkan). */
    protected readonly int $language;

    /** The MSL source, on Metal. */
    protected readonly string $source;

    /** The texture format of a framebuffer's stencil: D24S8 where the GPU has it, D32S8 where not (Apple's). */
    protected readonly int $stencilFormat;

    /** Nearest, clamped: SDL pairs a sampler with every texture a shader reads, and these read texels by index. */
    protected readonly SDL_GPUSampler $sampler;

    /** @var array<string, SDL_GPUShader> by entry point */
    protected array $shaders = [];

    /** @var array<string, SDL_GPUGraphicsPipeline> by everything the pipeline folds in */
    protected array $pipelines = [];

    /** @var list<SDL_GPUFence> submitted and not yet known to be finished, oldest first */
    protected array $inFlight = [];

    /**
     * @param Closure(): Recorder|null $recorder makes the recorder each painter records with; null: Rendering's default
     *
     * @throws SDL3Exception When SDL has no GPU device here, or none that takes MSL.
     * @throws GraphicsException When asked for the CPU backend.
     */
    public function __construct(Backend $backend = Backend::GPU, protected readonly ?Closure $recorder = null)
    {
        if ($backend !== Backend::GPU) {
            throw GraphicsException::backendNotOffered('sdl3', $backend, [Backend::GPU]);
        }
        parent::__construct($backend);

        if (! SDL_WasInit(SDL_INIT_VIDEO) && ! SDL_InitSubSystem(SDL_INIT_VIDEO)) {
            throw SDL3Exception::fromSdl('SDL video did not start');
        }
        $this->gpu = SDL_CreateGPUDevice(SDL_GPU_SHADERFORMAT_MSL | SDL_GPU_SHADERFORMAT_SPIRV, false, null)
            ?? throw SDL3Exception::fromSdl('no GPU device that takes MSL or SPIR-V could be made');
        $this->language = (SDL_GetGPUShaderFormats($this->gpu) & SDL_GPU_SHADERFORMAT_MSL) !== 0 ? SDL_GPU_SHADERFORMAT_MSL : SDL_GPU_SHADERFORMAT_SPIRV;
        $this->source = $this->language === SDL_GPU_SHADERFORMAT_MSL ? implode("\n", array_map('file_get_contents', self::MSL)) : '';

        $this->stencilFormat = SDL_GPUTextureSupportsFormat($this->gpu, SDL_GPU_TEXTUREFORMAT_D24_UNORM_S8_UINT, SDL_GPU_TEXTURETYPE_2D, SDL_GPU_TEXTUREUSAGE_DEPTH_STENCIL_TARGET)
            ? SDL_GPU_TEXTUREFORMAT_D24_UNORM_S8_UINT
            : SDL_GPU_TEXTUREFORMAT_D32_FLOAT_S8_UINT;

        $sampler = new SDL_GPUSamplerCreateInfo();
        $sampler->min_filter = SDL_GPU_FILTER_NEAREST;
        $sampler->mag_filter = SDL_GPU_FILTER_NEAREST;
        $sampler->mipmap_mode = SDL_GPU_SAMPLERMIPMAPMODE_NEAREST;
        $sampler->address_mode_u = SDL_GPU_SAMPLERADDRESSMODE_CLAMP_TO_EDGE;
        $sampler->address_mode_v = SDL_GPU_SAMPLERADDRESSMODE_CLAMP_TO_EDGE;
        $sampler->address_mode_w = SDL_GPU_SAMPLERADDRESSMODE_CLAMP_TO_EDGE;
        $this->sampler = SDL_CreateGPUSampler($this->gpu, $sampler) ?? throw SDL3Exception::fromSdl('no sampler could be made');
    }

    public function __destruct()
    {
        $this->release();
    }

    /** What SDL drives underneath: its GPU API. */
    public function modules(): array
    {
        return ['gpu' => (string) SDL_GetGPUDeviceDriver($this->gpu)];
    }

    public function has(Feature $feature): bool
    {
        return true;
    }

    /**
     * A surface of the stage's size, shown as pixels through the stage.
     *
     * @throws GraphicsException When the stage has no size yet.
     * @throws SDL3Exception When $format is not RGBA8: the only format it holds.
     */
    public function surface(ActorStage $stage, ?FormatSpec $format = null): SDLGPUSurface
    {
        [$width, $height] = $stage->pixelSize();
        if ($width < 1 || $height < 1) {
            throw GraphicsException::stageHasNoSize();
        }

        return $this->make($width, $height, $format, $stage);
    }

    /**
     * A surface with no stage: drawn into and read back, or drawn onto another as an image.
     *
     * @throws GraphicsException When a side is less than 1.
     * @throws SDL3Exception When $format is not RGBA8: the only format it holds.
     */
    public function offscreen(int $width, int $height, ?FormatSpec $format = null): SDLGPUSurface
    {
        if ($width < 1 || $height < 1) {
            throw GraphicsException::badSize($width, $height);
        }

        return $this->make($width, $height, $format, null);
    }

    /**
     * A raycaster over $grid: its cells are held on the GPU.
     *
     * @throws SDL3Exception When the grid is wider or taller than the GPU holds a texture (16384).
     */
    public function raycaster(Grid $grid): SDLGPURaycaster
    {
        return new SDLGPURaycaster($this, new SDLGPUCaster($this, $grid));
    }

    /** Waits for what the GPU is drawing. The device itself goes with the engine. */
    public function release(): void
    {
        if (isset($this->gpu)) {
            $this->sync();
        }
    }

    /** @throws SDL3Exception */
    protected function make(int $width, int $height, ?FormatSpec $format, ?ActorStage $stage): SDLGPUSurface
    {
        if (! is_null($format) && ! $format->equals(FormatSpec::rgba8())) {
            throw SDL3Exception::because('a surface holds RGBA8 only.');
        }
        $framebuffer = new SDLGPUFramebuffer($this, $width, $height);
        $painter = new SDLGPUPainter($this, $framebuffer, recorder: is_null($this->recorder) ? null : ($this->recorder)());

        return new SDLGPUSurface($this, $framebuffer, $painter, $stage);
    }

    /** @internal */
    public function gpu(): SDL_GPUDevice
    {
        return $this->gpu;
    }

    /** @internal */
    public function sampler(): SDL_GPUSampler
    {
        return $this->sampler;
    }

    /** @internal */
    public function stencilFormat(): int
    {
        return $this->stencilFormat;
    }

    /**
     * A 2D texture on this device.
     *
     * @internal
     *
     * @throws SDL3Exception
     */
    public function texture(int $format, int $width, int $height, int $samples, int $usage): SDLGPUTexture
    {
        $info = new SDL_GPUTextureCreateInfo();
        $info->type = SDL_GPU_TEXTURETYPE_2D;
        $info->format = $format;
        $info->usage = $usage;
        $info->width = $width;
        $info->height = $height;
        $info->layer_count_or_depth = 1;
        $info->num_levels = 1;
        $info->sample_count = $samples === 4 ? SDL_GPU_SAMPLECOUNT_4 : SDL_GPU_SAMPLECOUNT_1;
        $texture = SDL_CreateGPUTexture($this->gpu, $info)
            ?? throw SDL3Exception::fromSdl("no {$width}x{$height} texture (format {$format}, {$samples} samples) could be made");

        return new SDLGPUTexture($this->gpu, $texture, $format, $width, $height, $samples);
    }

    /**
     * Writes $bytes ($rowBytes a row, rows from the top) into $area of $texture, on a command buffer of its own that
     * goes to the GPU ahead of anything submitted later. With $cycle, a texture a submission the GPU has not
     * finished still reads keeps what it had for that one: SDL writes into fresh memory.
     *
     * @internal
     *
     * @throws SDL3Exception
     */
    public function write(SDLGPUTexture $texture, string $bytes, Region $area, bool $cycle = true): void
    {
        $transfer = $this->transfer($bytes);
        $commands = $this->commands();
        $copy = SDL_BeginGPUCopyPass($commands) ?? throw SDL3Exception::fromSdl('no copy pass could be begun');
        $from = new SDL_GPUTextureTransferInfo();
        $from->transfer_buffer = $transfer;
        $into = new SDL_GPUTextureRegion();
        $into->texture = $texture->texture;
        [$into->x, $into->y, $into->w, $into->h, $into->d] = [$area->x, $area->y, $area->width, $area->height, 1];
        SDL_UploadToGPUTexture($copy, $from, $into, $cycle);
        SDL_EndGPUCopyPass($copy);
        $this->submit($commands);
        SDL_ReleaseGPUTransferBuffer($this->gpu, $transfer);
    }

    /**
     * $bytes in a vertex buffer, uploaded on $commands. Must be called outside a render pass. The buffer is let go
     * when the caller drops it: SDL frees it once the GPU is done with it.
     *
     * @internal
     *
     * @throws SDL3Exception
     */
    public function vertices(SDL_GPUCommandBuffer $commands, string $bytes): SDL_GPUBuffer
    {
        $info = new SDL_GPUBufferCreateInfo();
        $info->usage = SDL_GPU_BUFFERUSAGE_VERTEX;
        $info->size = strlen($bytes);
        $buffer = SDL_CreateGPUBuffer($this->gpu, $info) ?? throw SDL3Exception::fromSdl('no vertex buffer could be made');
        $transfer = $this->transfer($bytes);

        $copy = SDL_BeginGPUCopyPass($commands) ?? throw SDL3Exception::fromSdl('no copy pass could be begun');
        $from = new SDL_GPUTransferBufferLocation();
        $from->transfer_buffer = $transfer;
        $into = new SDL_GPUBufferRegion();
        $into->buffer = $buffer;
        $into->size = strlen($bytes);
        SDL_UploadToGPUBuffer($copy, $from, $into, false);
        SDL_EndGPUCopyPass($copy);
        SDL_ReleaseGPUTransferBuffer($this->gpu, $transfer);

        return $buffer;
    }

    /** @internal */
    public function dropBuffer(SDL_GPUBuffer $buffer): void
    {
        SDL_ReleaseGPUBuffer($this->gpu, $buffer);
    }

    /**
     * $area of $texture as bytes, $bytesPerPixel a pixel, rows from the top. Encodes the download on $commands,
     * submits them and waits for the GPU.
     *
     * @internal
     *
     * @throws SDL3Exception
     */
    public function read(SDL_GPUCommandBuffer $commands, SDLGPUTexture $texture, Region $area, int $bytesPerPixel = 4): string
    {
        $size = $area->width * $area->height * $bytesPerPixel;
        $info = new SDL_GPUTransferBufferCreateInfo();
        $info->usage = SDL_GPU_TRANSFERBUFFERUSAGE_DOWNLOAD;
        $info->size = $size;
        $transfer = SDL_CreateGPUTransferBuffer($this->gpu, $info) ?? throw SDL3Exception::fromSdl('no transfer buffer could be made');

        $copy = SDL_BeginGPUCopyPass($commands) ?? throw SDL3Exception::fromSdl('no copy pass could be begun');
        $from = new SDL_GPUTextureRegion();
        $from->texture = $texture->texture;
        [$from->x, $from->y, $from->w, $from->h, $from->d] = [$area->x, $area->y, $area->width, $area->height, 1];
        $into = new SDL_GPUTextureTransferInfo();
        $into->transfer_buffer = $transfer;
        SDL_DownloadFromGPUTexture($copy, $from, $into);
        SDL_EndGPUCopyPass($copy);
        $this->settle($commands);

        try {
            $address = SDL_MapGPUTransferBuffer($this->gpu, $transfer, false);
            if ($address === 0) {
                throw SDL3Exception::fromSdl('the read-back could not be mapped');
            }
            $bytes = self::copyOut($address, $size);
            SDL_UnmapGPUTransferBuffer($this->gpu, $transfer);
        } finally {
            SDL_ReleaseGPUTransferBuffer($this->gpu, $transfer);
        }

        return $bytes;
    }

    /** @internal @throws SDL3Exception */
    public function commands(): SDL_GPUCommandBuffer
    {
        return SDL_AcquireGPUCommandBuffer($this->gpu) ?? throw SDL3Exception::fromSdl('no command buffer could be acquired');
    }

    /**
     * Hands $commands to the GPU and returns: the CPU goes on while the GPU draws. SDL runs submissions in order.
     * At most IN_FLIGHT are left running: past that, the oldest is waited for.
     *
     * @internal
     *
     * @throws SDL3Exception
     */
    public function submit(SDL_GPUCommandBuffer $commands): void
    {
        $this->inFlight[] = SDL_SubmitGPUCommandBufferAndAcquireFence($commands) ?? throw SDL3Exception::fromSdl('a submission was refused');
        while (count($this->inFlight) > self::IN_FLIGHT) {
            $this->finish(array_shift($this->inFlight));
        }
    }

    /**
     * Waits until the GPU has finished everything submitted.
     *
     * @internal
     */
    public function sync(): void
    {
        while ($this->inFlight !== []) {
            $this->finish(array_shift($this->inFlight));
        }
    }

    /** submit() then sync(): for commands whose result the CPU reads straight away. @internal @throws SDL3Exception */
    public function settle(SDL_GPUCommandBuffer $commands): void
    {
        $this->submit($commands);
        $this->sync();
    }

    /**
     * The pipeline of one fragment: with $vertex 'place', the painter's (the surface's points through the vertex
     * buffer, $samples, a stencil of $stencil kind); with 'cover', a pass over a whole image (one triangle, one
     * sample, no stencil). With $blend, the fragment's premultiplied colour goes over what is there (source +
     * destination × (1 − source alpha)); without, it replaces it. 'mark' writes no colour.
     *
     * Stencil kinds: plain, the stencil untouched; winding, front faces count up and back faces down (non-zero);
     * invert, every face flips it (even-odd); cover, draw where it is not zero, and zero it.
     *
     * @internal
     *
     * @throws SDL3Exception
     */
    public function pipeline(string $fragment, string $vertex = 'place', int $samples = 1, bool $blend = true, string $stencil = 'plain', int $format = SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM): SDL_GPUGraphicsPipeline
    {
        $key = "{$vertex}.{$fragment}.{$samples}.".(int) $blend.".{$stencil}.{$format}";
        if (isset($this->pipelines[$key])) {
            return $this->pipelines[$key];
        }

        $info = new SDL_GPUGraphicsPipelineCreateInfo();
        $info->vertex_shader = $this->shader($vertex, SDL_GPU_SHADERSTAGE_VERTEX);
        $info->fragment_shader = $this->shader($fragment, SDL_GPU_SHADERSTAGE_FRAGMENT);
        $info->primitive_type = SDL_GPU_PRIMITIVETYPE_TRIANGLELIST;
        $info->rasterizer_state->fill_mode = SDL_GPU_FILLMODE_FILL;
        $info->rasterizer_state->cull_mode = SDL_GPU_CULLMODE_NONE;
        $info->multisample_state->sample_count = $samples === 4 ? SDL_GPU_SAMPLECOUNT_4 : SDL_GPU_SAMPLECOUNT_1;

        $placed = $vertex === 'place';
        if ($placed) {
            $buffer = new SDL_GPUVertexBufferDescription();
            $buffer->slot = 0;
            $buffer->pitch = 8;
            $buffer->input_rate = SDL_GPU_VERTEXINPUTRATE_VERTEX;
            $point = new SDL_GPUVertexAttribute();
            $point->location = 0;
            $point->buffer_slot = 0;
            $point->format = SDL_GPU_VERTEXELEMENTFORMAT_FLOAT2;
            $info->vertex_input_state->vertex_buffer_descriptions = [$buffer];
            $info->vertex_input_state->vertex_attributes = [$point];

            $state = $info->depth_stencil_state;
            $state->enable_stencil_test = true;
            $state->compare_mask = 0xFF;
            $state->write_mask = 0xFF;
            foreach ([$state->front_stencil_state, $state->back_stencil_state] as $face => $ops) {
                $ops->fail_op = SDL_GPU_STENCILOP_KEEP;
                $ops->depth_fail_op = SDL_GPU_STENCILOP_KEEP;
                $ops->compare_op = SDL_GPU_COMPAREOP_ALWAYS;
                $ops->pass_op = SDL_GPU_STENCILOP_KEEP;
                match ($stencil) {
                    'plain' => null,
                    'winding' => $ops->pass_op = $face === 0 ? SDL_GPU_STENCILOP_INCREMENT_AND_WRAP : SDL_GPU_STENCILOP_DECREMENT_AND_WRAP,
                    'invert' => $ops->pass_op = SDL_GPU_STENCILOP_INVERT,
                    'cover' => [$ops->compare_op, $ops->pass_op] = [SDL_GPU_COMPAREOP_NOT_EQUAL, SDL_GPU_STENCILOP_ZERO],
                };
            }
            $info->target_info->has_depth_stencil_target = true;
            $info->target_info->depth_stencil_format = $this->stencilFormat;
        }

        $color = new SDL_GPUColorTargetDescription();
        $color->format = $format;
        $writes = $fragment !== 'mark';
        $color->blend_state->enable_color_write_mask = true;
        $color->blend_state->color_write_mask = $writes ? (SDL_GPU_COLORCOMPONENT_R | SDL_GPU_COLORCOMPONENT_G | SDL_GPU_COLORCOMPONENT_B | SDL_GPU_COLORCOMPONENT_A) : 0;
        $color->blend_state->enable_blend = $blend && $writes;
        $color->blend_state->color_blend_op = SDL_GPU_BLENDOP_ADD;
        $color->blend_state->alpha_blend_op = SDL_GPU_BLENDOP_ADD;
        $color->blend_state->src_color_blendfactor = SDL_GPU_BLENDFACTOR_ONE;
        $color->blend_state->src_alpha_blendfactor = SDL_GPU_BLENDFACTOR_ONE;
        $color->blend_state->dst_color_blendfactor = SDL_GPU_BLENDFACTOR_ONE_MINUS_SRC_ALPHA;
        $color->blend_state->dst_alpha_blendfactor = SDL_GPU_BLENDFACTOR_ONE_MINUS_SRC_ALPHA;
        $info->target_info->color_target_descriptions = [$color];

        return $this->pipelines[$key] = SDL_CreateGPUGraphicsPipeline($this->gpu, $info)
            ?? throw SDL3Exception::fromSdl("SDL refused the '{$fragment}' pipeline ({$vertex}, {$samples} samples, stencil {$stencil})");
    }

    /**
     * The shader of one entry point. On Metal, compiled from the device's whole MSL source; how many textures and
     * uniform buffers SDL binds for it are read from the entry point's own parameters. On Vulkan, its SPIR-V; those
     * counts are read from the declarations of its GLSL source.
     *
     * @throws SDL3Exception
     */
    protected function shader(string $entry, int $stage): SDL_GPUShader
    {
        if (isset($this->shaders[$entry])) {
            return $this->shaders[$entry];
        }
        $vertex = $stage === SDL_GPU_SHADERSTAGE_VERTEX;
        $info = new SDL_GPUShaderCreateInfo();
        $info->format = $this->language;
        $info->stage = $stage;

        if ($this->language === SDL_GPU_SHADERFORMAT_MSL) {
            $kind = $vertex ? 'vertex' : 'fragment';
            if (preg_match('/\b'.$kind.'\s+\w+\s+'.preg_quote($entry, '/').'\s*\((.*?)\)\s*\{/s', $this->source, $match) !== 1) {
                throw SDL3Exception::because("the shaders have no {$kind} function '{$entry}'.");
            }
            $info->code = $this->source;
            $info->entrypoint = $entry;
            $info->num_samplers = substr_count($match[1], '[[texture(');
            $info->num_uniform_buffers = substr_count($match[1], '[[buffer(');
        } else {
            $file = self::SPIRV.'/'.$entry.($vertex ? '.vert' : '.frag');
            if (! is_file($file) || ! is_file("{$file}.spv")) {
                throw SDL3Exception::because("the shaders have no SPIR-V for '{$entry}'.");
            }
            $glsl = file_get_contents($file);
            $info->code = file_get_contents("{$file}.spv");
            $info->entrypoint = 'main';
            $info->num_samplers = preg_match_all('/\buniform\s+[iu]?sampler2D\b/', $glsl);
            $info->num_uniform_buffers = preg_match_all('/\buniform\s+\w+\s*\{/', $glsl);
        }

        return $this->shaders[$entry] = SDL_CreateGPUShader($this->gpu, $info)
            ?? throw SDL3Exception::fromSdl("the '{$entry}' shader did not compile");
    }

    /** @throws SDL3Exception */
    protected function transfer(string $bytes): SDL_GPUTransferBuffer
    {
        $info = new SDL_GPUTransferBufferCreateInfo();
        $info->usage = SDL_GPU_TRANSFERBUFFERUSAGE_UPLOAD;
        $info->size = strlen($bytes);
        $transfer = SDL_CreateGPUTransferBuffer($this->gpu, $info) ?? throw SDL3Exception::fromSdl('no transfer buffer could be made');
        $address = SDL_MapGPUTransferBuffer($this->gpu, $transfer, false);
        if ($address === 0) {
            SDL_ReleaseGPUTransferBuffer($this->gpu, $transfer);
            throw SDL3Exception::fromSdl('a transfer buffer could not be mapped');
        }
        $io = SDL_IOFromMem($address, strlen($bytes));
        SDL_WriteIO($io, $bytes, strlen($bytes));
        SDL_CloseIO($io);
        SDL_UnmapGPUTransferBuffer($this->gpu, $transfer);

        return $transfer;
    }

    /**
     * $size bytes at $address as a PHP string, copied by SDL in C: the bytes taken as 4-byte pixels on both sides and
     * blitted as they are.
     */
    protected static function copyOut(int $address, int $size): string
    {
        $out = str_repeat("\0", $size);
        $width = intdiv($size, 4);
        $from = SDL_CreateSurfaceFrom($width, 1, SDL_PIXELFORMAT_RGBA32, $address, $size);
        $into = SDL_CreateSurfaceFrom($width, 1, SDL_PIXELFORMAT_RGBA32, $out, $size);
        SDL_SetSurfaceBlendMode($from, SDL_BLENDMODE_NONE);
        SDL_BlitSurface($from, null, $into, null);
        SDL_DestroySurface($into);
        SDL_DestroySurface($from);

        return $out;
    }

    /** @throws SDL3Exception */
    protected function finish(SDL_GPUFence $fence): void
    {
        SDL_WaitForGPUFences($this->gpu, true, [$fence], 1);
        SDL_ReleaseGPUFence($this->gpu, $fence);
    }
}
