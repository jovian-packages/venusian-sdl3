<?php

namespace Jovian\Engines\Sdl3;

use Closure;
use FbBuffer;
use FbFormat;
use SDL_FColor;
use SDL_GPUBlitInfo;
use Surface\Drawing\Gpu\FilterPlan;
use SDL_GPUBuffer;
use SDL_GPUBufferBinding;
use SDL_GPUBufferCreateInfo;
use SDL_GPUBufferRegion;
use SDL_GPUColorTargetDescription;
use SDL_GPUColorTargetInfo;
use SDL_GPUCommandBuffer;
use SDL_GPUDepthStencilTargetInfo;
use SDL_GPUDevice;
use SDL_GPUFence;
use SDL_GPUGraphicsPipeline;
use SDL_GPUGraphicsPipelineCreateInfo;
use SDL_GPURenderPass;
use SDL_GPUSampler;
use SDL_GPUSamplerCreateInfo;
use SDL_GPUShader;
use SDL_GPUShaderCreateInfo;
use SDL_GPUTexture;
use SDL_GPUTextureCreateInfo;
use SDL_GPUTextureRegion;
use SDL_GPUTextureSamplerBinding;
use SDL_GPUTextureTransferInfo;
use SDL_GPUTransferBuffer;
use SDL_GPUTransferBufferCreateInfo;
use SDL_GPUTransferBufferLocation;
use SDL_GPUVertexAttribute;
use SDL_GPUVertexBufferDescription;
use SDL_GPUViewport;
use SDL_Rect;
use SDL_Window;
use Surface\Contracts\Drawing\ColorSpace;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\TargetFormat;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Drawing\Gpu\DrawList;
use Surface\Drawing\Gpu\DrawsGlass;
use Surface\Drawing\Gpu\GpuDevice;
use Surface\Drawing\Gpu\Op;
use Surface\Drawing\Gpu\QueuesFrames;
use Surface\Drawing\Gpu\SwitchesVsync;
use Surface\Drawing\Gpu\TargetsFormats;
use Surface\Framebuffers\HdrImage;
use Throwable;

/**
 * The sdl3 engine's device: one SDL_GPU device, a persistent target, and the
 * pipelines that draw a DrawList into it.
 *
 * The target is a single-sample RGBA8 texture ($resolved), read back and
 * uploaded to by Sdl3Framebuffer and shown by present(). With four samples a
 * multisampled texture is drawn into and resolved into it every frame; an
 * upload then leaves the samples behind, and the next draw restores them from
 * $resolved first. The stencil lives in a depth-stencil texture in the format
 * the device supports, cleared every pass: paths fill stencil-then-cover and
 * leave it at zero.
 *
 * Presenting: the show pass draws the target into the swapchain texture,
 * presentRect() at the swapchain's size sampled with the surface's filter,
 * the bars black, encoded for the swapchain. The window's vsync picks the
 * present mode (VSYNC, IMMEDIATE, MAILBOX; MAILBOX, else VSYNC, until it
 * does); SDL_GPU has no adaptive mode and reports no present times. Under
 * VSYNC, off the Metal backend, nothing is presented while the window is
 * occluded, minimised or hidden: the compositor would make the present wait
 * until it shows again. Metal hands out drawables to hidden windows.
 * The window is claimed at adopt() and given back when the window reclaims
 * its surface.
 *
 * HDR: every HDR target is an R16G16B16A16_FLOAT texture of linear light in
 * sRGB primaries, SDR white at 1.0. Both extended spaces show through the
 * HDR_EXTENDED_LINEAR composition, the show pass multiplying by the surface's
 * SDR white level; HDR10 shows through HDR10_ST2084, the show pass encoding
 * BT.2020 PQ. SDL_GPU has no P3 composition and takes no HDR metadata.
 */
final class Sdl3Device implements GpuDevice, SwitchesVsync, QueuesFrames, TargetsFormats, DrawsGlass
{
    /** The shader directory: GLSL with its compiled SPIR-V, and MSL. */
    public const string SHADERS = __DIR__.'/../resources/shaders';

    /** The widest and tallest texture made: SDL_GPU's 2D bound. */
    public const int LARGEST = 16384;

    private const int SOLID = 0;

    private const int FILL = 1;

    private const int OVAL = 2;

    private const int IMAGE = 3;

    private const int COPY = 4;

    private const int ROUND = 5;

    private const int SHADE = 6;

    private const int GLYPH = 7;

    private const int GRADIENT = 8;

    private const int GLASS = 9;

    private SDL_GPUDevice $gpu;

    private readonly bool $owns_gpu;

    private int $stencil_format;

    /** @var array<int, SDL_GPUShader> By stage. */
    private array $shaders = [];

    private ?Sdl3Framebuffer $framebuffer = null;

    private ?SDL_GPUTexture $resolved = null;

    /** @var array<int, SDL_GPUTexture> Every resolved texture an Sdl3Framebuffer still holds, by object id: release() lets go of them. */
    private array $owned = [];

    private ?SDL_GPUTexture $multisampled = null;

    private ?SDL_GPUTexture $stencil = null;

    private int $samples = 1;

    /** @var array<string, SDL_GPUGraphicsPipeline> By pipeline kind, for the current sample count. */
    private array $pipelines = [];

    private ?SDL_GPUSampler $sampler = null;

    /** @var array<int, array{resolved: SDL_GPUTexture, multisampled: ?SDL_GPUTexture, stencil: SDL_GPUTexture}> Layer targets by number (1 up): made on first use at the target's size, kept until it changes. */
    private array $slots = [];

    /** The filter program: its pipeline, made on the first chain. */
    private ?SDL_GPUGraphicsPipeline $filterPipeline = null;

    /** @var array<int, SDL_GPUShader> The filter program's shaders, by stage. */
    private array $filterShaders = [];

    private ?SDL_GPUTexture $blank = null;

    /** The glass passes' fragment shader and pipeline (the filter's vertex stage, one sampler): made on the first glass pane. */
    private ?SDL_GPUShader $glassShader = null;

    private ?SDL_GPUGraphicsPipeline $glassPipeline = null;

    /** Pyramid levels no frame holds, by size, to be taken again; over GLASS_POOL_BYTES the sizes used longest ago are released first. */
    private const int GLASS_POOL_BYTES = 134217728;

    /** @var array<string, list<SDL_GPUTexture>> Textures by "WxH". */
    private array $glassPool = [];

    private int $glassBytes = 0;

    /** @var array<int, array<string, mixed>> The last frame's glass pyramids by their place in the frame, kept while Lowering says nothing under them changed. */
    private array $glassKept = [];

    /** @var array<int, SDL_GPUTexture> Glyph atlases (R8) by number, kept across draws until remade or released. */
    private array $atlases = [];

    /**
     * Fences of the command buffers submitted, held until a later submit has
     * seen them signal. SDL 3.4's Metal backend cleans a submitted command
     * buffer (its textures, buffers and pending releases) only in a later
     * submit that finds its fence complete, and marks a buffer's current fence
     * done from its completion handler: a fence handed back sooner is pooled,
     * reset by the next submit, and either marked done early for its next
     * holder or leaves its first buffer uncleaned for good.
     *
     * @var list<SDL_GPUFence>
     */
    private array $pending = [];

    /** $resolved was uploaded to since $multisampled was last drawn. */
    private bool $stale = false;

    private ?SDL_Window $window = null;

    private ColorSpace $space = ColorSpace::Srgb;

    /** The target texture's format: half floats for every HDR colour space. */
    private int $colorFormat = SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM;

    /** @var array<int, true> Half-float image textures of the frame being drawn, by object id. */
    private array $floatTextures = [];

    /** The adopted surface, while it is lent. */
    private ?LentSurface $lent = null;

    /** The swapchain's present mode. */
    private int $presentMode = SDL_GPU_PRESENTMODE_VSYNC;

    /** @var array<int, SDL_GPUShader> The show pass's shaders, by stage. */
    private array $showShaders = [];

    /** @var array<int, SDL_GPUGraphicsPipeline> The show pipeline, by swapchain texture format. */
    private array $showPipelines = [];

    /** @var array<int, array{int, int}> Image texture sizes by object id, for the frame being drawn. */
    private array $sizes = [];

    /** release() has run: forget() does nothing, target() refuses. */
    private bool $released = false;

    /**
     * @param  SDL_GPUDevice|null  $gpu  A device made with SDL_INIT_VIDEO up; made here (SPIR-V or MSL, no debug) unless given.
     *
     * @throws DrawingException When no device can be made, or no shader format of ours is taken.
     */
    public function __construct(?SDL_GPUDevice $gpu = null)
    {
        $this->owns_gpu = is_null($gpu);
        $this->gpu = $gpu ?? SDL_CreateGPUDevice(SDL_GPU_SHADERFORMAT_SPIRV | SDL_GPU_SHADERFORMAT_MSL, false, null)
            ?? throw new DrawingException('sdl3: no GPU device could be made: '.SDL_GetError().'. SDL_Init(SDL_INIT_VIDEO) comes first.');
        $formats = SDL_GetGPUShaderFormats($this->gpu);
        if (! ($formats & (SDL_GPU_SHADERFORMAT_SPIRV | SDL_GPU_SHADERFORMAT_MSL))) {
            throw new DrawingException('sdl3: this device takes neither SPIR-V nor MSL shaders.');
        }
        $this->stencil_format = SDL_GPUTextureSupportsFormat($this->gpu, SDL_GPU_TEXTUREFORMAT_D24_UNORM_S8_UINT, SDL_GPU_TEXTURETYPE_2D, SDL_GPU_TEXTUREUSAGE_DEPTH_STENCIL_TARGET)
            ? SDL_GPU_TEXTUREFORMAT_D24_UNORM_S8_UINT
            : SDL_GPU_TEXTUREFORMAT_D32_FLOAT_S8_UINT;
    }

    public function name(): string
    {
        return 'sdl3';
    }

    public function gpu(): SDL_GPUDevice
    {
        return $this->gpu;
    }

    /** SDL's backend: 'metal', 'vulkan', 'direct3d12'. */
    public function driver(): string
    {
        return SDL_GetGPUDeviceDriver($this->gpu) ?? 'unknown';
    }

    public function stencilFormat(): int
    {
        return $this->stencil_format;
    }

    public function surfaces(): array
    {
        return [SurfaceKind::SDL_WINDOW];
    }

    public function handles(): array
    {
        return [];
    }

    /** The vertex or fragment shader, compiled once in the format the device takes: SPIR-V where it can, MSL otherwise. */
    public function shader(int $stage): SDL_GPUShader
    {
        if (isset($this->shaders[$stage])) {
            return $this->shaders[$stage];
        }

        $fragment = $stage === SDL_GPU_SHADERSTAGE_FRAGMENT;
        $info = new SDL_GPUShaderCreateInfo();
        $info->stage = $stage;
        if (SDL_GetGPUShaderFormats($this->gpu) & SDL_GPU_SHADERFORMAT_SPIRV) {
            $info->format = SDL_GPU_SHADERFORMAT_SPIRV;
            $info->code = file_get_contents(self::SHADERS.'/venusian.'.($fragment ? 'frag' : 'vert').'.spv');
            $info->entrypoint = 'main';
        } else {
            $info->format = SDL_GPU_SHADERFORMAT_MSL;
            $info->code = file_get_contents(self::SHADERS.'/venusian.metal');
            $info->entrypoint = $fragment ? 'paint' : 'place';
        }
        $info->num_uniform_buffers = 1;
        // the source, and a glass pane's sharp capture: every draw binds both
        $info->num_samplers = $fragment ? 2 : 0;

        return $this->shaders[$stage] = SDL_CreateGPUShader($this->gpu, $info)
            ?? throw new DrawingException('sdl3: the '.($fragment ? 'fragment' : 'vertex').' shader was refused: '.SDL_GetError());
    }

    private function make(int $width, int $height, int $samples, ColorSpace $space): GLFramebuffer
    {
        if ($samples !== 1 && $samples !== 4) {
            throw new DrawingException("sdl3 draws with 1 or 4 samples, got {$samples}.");
        }
        if ($width < 1 || $height < 1) {
            throw new DrawingException("sdl3 needs a target of at least 1 × 1, got {$width} × {$height}.");
        }

        $this->live();
        $this->finish();
        $this->releaseTarget();
        $this->space = $space;
        $this->colorFormat = $space->isHdr() ? SDL_GPU_TEXTUREFORMAT_R16G16B16A16_FLOAT : SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM;
        $this->swapchainParameters();
        $this->samples = $samples;
        $this->resolved = $this->texture($this->colorFormat, $width, $height, SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_COLOR_TARGET | SDL_GPU_TEXTUREUSAGE_SAMPLER);
        $this->owned[spl_object_id($this->resolved)] = $this->resolved;
        $this->framebuffer = new Sdl3Framebuffer($this, $this->resolved, $width, $height, $space->isHdr());
        $this->multisampled = $samples === 4 ? $this->texture($this->colorFormat, $width, $height, SDL_GPU_SAMPLECOUNT_4, SDL_GPU_TEXTUREUSAGE_COLOR_TARGET) : null;
        $this->stencil = $this->texture($this->stencil_format, $width, $height, $samples === 4 ? SDL_GPU_SAMPLECOUNT_4 : SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_DEPTH_STENCIL_TARGET);

        // Defined contents from the start: transparent black, as a new framebuffer holds.
        $this->encode(function (SDL_GPUCommandBuffer $commands): void {
            $pass = SDL_BeginGPURenderPass($commands, [$this->colorTarget(SDL_GPU_LOADOP_CLEAR)], 1, $this->stencilTarget())
                ?? throw new DrawingException('sdl3: no render pass could begin: '.SDL_GetError());
            SDL_EndGPURenderPass($pass);
        });

        return $this->framebuffer;
    }

    public function target(int $width, int $height, int $samples): GLFramebuffer
    {
        return $this->make($width, $height, $samples, ColorSpace::Srgb);
    }

    public function targetFormats(): array
    {
        $usage = SDL_GPU_TEXTUREUSAGE_COLOR_TARGET | SDL_GPU_TEXTUREUSAGE_SAMPLER;

        return SDL_GPUTextureSupportsFormat($this->gpu, SDL_GPU_TEXTUREFORMAT_R16G16B16A16_FLOAT, SDL_GPU_TEXTURETYPE_2D, $usage)
            ? [[TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearSrgb], [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearDisplayP3], [TargetFormat::Rgb10A2, ColorSpace::Hdr10Pq]]
            : [];
    }

    public function targetAs(int $width, int $height, int $samples, TargetFormat $format, ColorSpace $space): GLFramebuffer
    {
        if (! in_array([$format, $space], $this->targetFormats(), true)) {
            throw new DrawingException("sdl3 makes no {$format->value} {$space->value} target.");
        }
        if (! is_null($this->window) && ! SDL_WindowSupportsGPUSwapchainComposition($this->gpu, $this->window, $this->compositionFor($space))) {
            throw new DrawingException("sdl3: this window shows no {$space->value} swapchain on {$this->driver()}.");
        }

        return $this->make($width, $height, $samples, $space);
    }

    /** @internal Sdl3Framebuffer's readback: $region of $texture as RGBA8, once the last frame has finished. */
    public function read(SDL_GPUTexture $texture, Region $region, int $bytes = 4): string
    {
        $this->live();
        $this->finish();
        $size = $region->width * $region->height * $bytes;
        $download = $this->transferBuffer($size, SDL_GPU_TRANSFERBUFFERUSAGE_DOWNLOAD);
        try {
            $this->encode(function (SDL_GPUCommandBuffer $commands) use ($texture, $region, $download): void {
                $copy = SDL_BeginGPUCopyPass($commands) ?? throw new DrawingException('sdl3: no copy pass could begin: '.SDL_GetError());
                $to = new SDL_GPUTextureTransferInfo();
                $to->transfer_buffer = $download;
                $to->pixels_per_row = $region->width;
                $to->rows_per_layer = $region->height;
                SDL_DownloadFromGPUTexture($copy, self::region($texture, $region), $to);
                SDL_EndGPUCopyPass($copy);
            });
            $this->finish();

            $address = SDL_MapGPUTransferBuffer($this->gpu, $download, false);
            if ($address === 0) {
                throw new DrawingException('sdl3: the readback buffer could not be mapped: '.SDL_GetError());
            }
            // ext-sdl3's SDL_ReadIO writes to an address: an ext-fb buffer of the region's rows ($bytes/4 RGBA8 cells a texel) takes them.
            $rows = new FbBuffer(new FbFormat(FB_LAYOUT_RGBA8888, channelOrder: FB_CHANNELS_RGBA), $region->width * intdiv($bytes, 4), $region->height);
            $io = SDL_IOFromConstMem($address, $size);
            SDL_ReadIO($io, $rows->pointer(), $size);
            SDL_CloseIO($io);
            SDL_UnmapGPUTransferBuffer($this->gpu, $download);

            return $rows->bytes();
        } finally {
            SDL_ReleaseGPUTransferBuffer($this->gpu, $download);
        }
    }

    /** @internal Sdl3Framebuffer's upload: $rgba8 over $region of $texture. The multisampled target is restored from it at the next draw. */
    public function write(SDL_GPUTexture $texture, string $texels, Region $region): void
    {
        $this->live();
        $this->finish();
        $upload = $this->transferBuffer(strlen($texels), SDL_GPU_TRANSFERBUFFERUSAGE_UPLOAD);
        try {
            $this->fillTransfer($upload, $texels);
            $this->encode(function (SDL_GPUCommandBuffer $commands) use ($texture, $region, $upload): void {
                $copy = SDL_BeginGPUCopyPass($commands) ?? throw new DrawingException('sdl3: no copy pass could begin: '.SDL_GetError());
                $from = new SDL_GPUTextureTransferInfo();
                $from->transfer_buffer = $upload;
                $from->pixels_per_row = $region->width;
                $from->rows_per_layer = $region->height;
                SDL_UploadToGPUTexture($copy, $from, self::region($texture, $region), false);
                SDL_EndGPUCopyPass($copy);
            });
            $this->finish();
        } finally {
            SDL_ReleaseGPUTransferBuffer($this->gpu, $upload);
        }

        if ($texture === $this->resolved && ! is_null($this->multisampled)) {
            $this->stale = true;
        }
    }

    /** @internal Sdl3Framebuffer lets go of its texture here, when the PHP object goes. */
    public function forget(SDL_GPUTexture $texture): void
    {
        if ($this->released || ! isset($this->owned[spl_object_id($texture)])) {
            return;
        }
        unset($this->owned[spl_object_id($texture)]);
        $this->finish();
        SDL_ReleaseGPUTexture($this->gpu, $texture);
    }

    /** Wait for every command buffer submitted. The fences stay held: the next submit lets them go, once SDL has cleaned their buffers. */
    public function finish(): void
    {
        if ($this->pending !== []) {
            SDL_WaitForGPUFences($this->gpu, true, $this->pending, count($this->pending));
        }
    }

    /** The operations draw() handles. */
    private const array OPS = [Op::CLEAR, Op::SCISSOR, Op::SOLID, Op::STENCIL_FILL, Op::COVER, Op::COVER_GRADIENT, Op::RECTS, Op::ELLIPSE, Op::RING, Op::UPLOAD, Op::IMAGE, Op::ATLAS, Op::MASK, Op::GLYPHS, Op::ROUNDRECT, Op::ROUNDRING, Op::SHADOW, Op::TARGET, Op::BACKDROP, Op::FILTER, Op::LAYER_SOURCE, Op::GLASS_BACKDROP, Op::GLASS];

    /**
     * Every operation of $list is one draw() handles: checked before any GPU work, so an unknown one fails the
     * frame loudly instead of being skipped.
     *
     * @throws DrawingException On the first operation it does not handle.
     */
    private static function known(DrawList $list): void
    {
        foreach ($list->operations as $operation) {
            if (! in_array($operation[0], self::OPS, true)) {
                $op = $operation[0] instanceof Op ? $operation[0]->name : get_debug_type($operation[0]);

                throw new DrawingException("sdl3: unknown draw op {$op}");
            }
        }
    }

    public function draw(DrawList $list): void
    {
        $target = $this->framebuffer ?? throw new DrawingException('sdl3: target() comes before draw().');
        $width = $target->width();
        $height = $target->height();
        self::known($list);

        // The list's vertices, then one quad over the whole target for CLEAR and the restore.
        $whole = $list->vertexCount();
        $vertices = $list->vertices.self::quad(0.0, 0.0, (float) $width, (float) $height);
        $buffer = $this->vertexBuffer($vertices);
        /** @var array<int, SDL_GPUTexture> $textures */
        $textures = [];
        /** @var array<int, true> $layerTextures The layer targets among $textures, by object id: the device keeps them. */
        $layerTextures = [];
        /** @var list<SDL_GPUTexture> $scratch The frame's filter textures, let go with it. */
        $scratch = [];
        try {
            $this->encode(function (SDL_GPUCommandBuffer $commands) use ($list, $buffer, $vertices, $whole, $width, $height, &$textures, &$layerTextures, &$scratch): void {
                $this->uploadVertices($commands, $buffer, $vertices);
                foreach ($list->operations as $operation) {
                    if ($operation[0] === Op::UPLOAD) {
                        $textures[$operation[1]] = $this->upload($commands, $operation[2]);
                    } elseif ($operation[0] === Op::ATLAS) {
                        // A new texture's bytes are undefined: zero it. SDL keeps the old one while a frame reads it.
                        [, $atlas, $atlasWidth, $atlasHeight] = $operation;
                        $made = $this->texture(SDL_GPU_TEXTUREFORMAT_R8_UNORM, $atlasWidth, $atlasHeight, SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
                        if (isset($this->atlases[$atlas])) {
                            SDL_ReleaseGPUTexture($this->gpu, $this->atlases[$atlas]);
                        }
                        $this->atlases[$atlas] = $made;
                        $this->uploadRegion($commands, $made, Region::wholeSurface($atlasWidth, $atlasHeight), str_repeat("\0", $atlasWidth * $atlasHeight));
                    } elseif ($operation[0] === Op::MASK) {
                        // Only regions no earlier frame drew from: frames in flight read what they read before.
                        $this->uploadRegion($commands, $this->atlases[$operation[1]], $operation[2], $operation[3]);
                    }
                }
                // Layers: their targets, and for each filter chain an image a pass to render into, the pass's numbers uploaded.
                /** @var array<int, array{list<array<string, mixed>>, list<SDL_GPUTexture>, list<?SDL_GPUTexture>}> $filters By the operation's place in the list. */
                $filters = [];
                foreach ($list->operations as $index => $operation) {
                    switch ($operation[0]) {
                        case Op::TARGET:
                        case Op::FILTER:
                        case Op::GLASS_BACKDROP:
                            $this->ensureSlot($operation[1]);
                            break;
                        case Op::BACKDROP:
                            $this->ensureSlot($operation[1]);
                            $this->ensureSlot($operation[2]);
                            break;
                        case Op::LAYER_SOURCE:
                            $this->ensureSlot($operation[2]);
                            $textures[$operation[1]] = $this->slots[$operation[2]]['resolved'];
                            $layerTextures[spl_object_id($textures[$operation[1]])] = true;
                            break;
                    }
                    if ($operation[0] !== Op::FILTER) {
                        continue;
                    }
                    [, , $region, $chain, $premultiplied, $maps, $tables] = $operation;
                    $plan = FilterPlan::of($chain, $premultiplied, $maps, $tables, $region->width, $region->height);
                    $outputs = [];
                    $numbers = [];
                    foreach ($plan as $pass) {
                        $outputs[] = $scratch[] = $this->texture($this->colorFormat, $width, $height, SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_COLOR_TARGET | SDL_GPU_TEXTUREUSAGE_SAMPLER);
                        $data = null;
                        if ($pass['data'] !== []) {
                            $rows = intdiv(count($pass['data']) + 511, 512);
                            $data = $scratch[] = $this->texture(SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM, 512, $rows, SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
                            $this->uploadRegion($commands, $data, new Region(0, 0, 512, $rows), pack('g*', ...array_pad($pass['data'], $rows * 512, 0.0)));
                        }
                        $numbers[] = $data;
                    }
                    $filters[$index] = [$plan, $outputs, $numbers];
                }

                // Glass: each backdrop's pyramid, new or last frame's.
                /** @var array<int, array{array<string, mixed>, bool}> $glass By the operation's place in the list: the pyramid, and whether this frame builds it. */
                $glass = [];
                $glassUsed = [];
                foreach ($list->operations as $index => $operation) {
                    if ($operation[0] !== Op::GLASS_BACKDROP) {
                        continue;
                    }
                    [, $from, $region, $depths, $n, $keep] = $operation;
                    $glassUsed[$n] = true;
                    if ($keep && isset($this->glassKept[$n])) {
                        $glass[$index] = [$this->glassKept[$n], false];

                        continue;
                    }
                    if (isset($this->glassKept[$n])) {
                        $this->glassDiscard($this->glassKept[$n]);
                        unset($this->glassKept[$n]);
                    }
                    $this->glassKept[$n] = $pyramid = $this->glassBuild($this->slotTexture($from), $region, $depths);
                    $glass[$index] = [$pyramid, true];
                }
                foreach (array_diff_key($this->glassKept, $glassUsed) as $n => $kept) {
                    $this->glassDiscard($kept);
                    unset($this->glassKept[$n]);
                }

                if ($this->stale) {
                    $this->restore($commands, $buffer, $whole, $width, $height);
                }

                $begin = function (int $slot, int $load) use ($commands, $buffer, $width, $height): SDL_GPURenderPass {
                    $pass = SDL_BeginGPURenderPass($commands, [$this->colorTarget($load, $slot)], 1, $this->stencilTarget($slot))
                        ?? throw new DrawingException('sdl3: no render pass could begin: '.SDL_GetError());
                    $this->prepare($pass, $commands, $buffer, $width, $height);

                    return $pass;
                };
                $pass = $begin(0, SDL_GPU_LOADOP_LOAD);
                $scissor = self::rect(0, 0, $width, $height);
                $current = 0;
                $panes = null;

                foreach ($list->operations as $index => $operation) {
                    switch ($operation[0]) {
                        case Op::TARGET:
                            // draws go to another target from here: a layer's own (cleared), or back to an enclosing one
                            [, $current, $cleared] = $operation;
                            SDL_EndGPURenderPass($pass);
                            $pass = $begin($current, is_null($cleared) ? SDL_GPU_LOADOP_LOAD : SDL_GPU_LOADOP_CLEAR);
                            $scissor = self::rect(0, 0, $width, $height);
                            break;

                        case Op::BACKDROP:
                            // [, $from, $into, $region]: the region of one target copied into another, no blending
                            [, $from, $into, $region] = $operation;
                            SDL_EndGPURenderPass($pass);
                            $this->blit($commands, $this->slotTexture($from), $this->slotTexture($into), $region, $region);
                            $pass = $begin($current, SDL_GPU_LOADOP_LOAD);
                            SDL_SetGPUScissor($pass, $scissor);
                            break;

                        case Op::FILTER:
                            SDL_EndGPURenderPass($pass);
                            [$plan, $outputs, $numbers] = $filters[$index];
                            [, $into, $region] = $operation;
                            $results = [0 => $this->slotTexture($into)];
                            foreach ($outputs as $i => $output) {
                                $results[$i + 1] = $output;
                            }
                            foreach ($plan as $i => $filterPass) {
                                $color = new SDL_GPUColorTargetInfo();
                                $color->texture = $outputs[$i];
                                $color->load_op = SDL_GPU_LOADOP_DONT_CARE;
                                $color->store_op = SDL_GPU_STOREOP_STORE;
                                $fp = SDL_BeginGPURenderPass($commands, [$color], 1, null)
                                    ?? throw new DrawingException('sdl3: no render pass could begin: '.SDL_GetError());
                                SDL_BindGPUGraphicsPipeline($fp, $this->filterPipeline());
                                SDL_SetGPUScissor($fp, self::rect($region->x, $region->y, $region->width, $region->height));
                                $bindings = [];
                                foreach ([$results[$filterPass['in']], $results[$filterPass['in2']], $numbers[$i] ?? $this->blank(), $filterPass['map'] < 0 ? $this->blank() : $textures[$filterPass['map']]] as $texture) {
                                    $binding = new SDL_GPUTextureSamplerBinding();
                                    $binding->texture = $texture;
                                    $binding->sampler = $this->sampler();
                                    $bindings[] = $binding;
                                }
                                SDL_BindGPUFragmentSamplers($fp, 0, $bindings, 4);
                                $p = array_pad($filterPass['params'], 8, 0.0);
                                $uniform = pack('g16', (float) $region->x, (float) $region->y, (float) $region->right(), (float) $region->bottom(), (float) $filterPass['kind'], 0.0, 0.0, 0.0, $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7]);
                                SDL_PushGPUFragmentUniformData($commands, 0, $uniform, strlen($uniform));
                                SDL_DrawGPUPrimitives($fp, 3, 1, 0, 0);
                                SDL_EndGPURenderPass($fp);
                            }
                            // the last result's region into the target: what the target holds from here is the answer
                            $this->blit($commands, $outputs[count($plan) - 1], $this->slotTexture($into), $region, $region);
                            $pass = $begin($current, SDL_GPU_LOADOP_LOAD);
                            SDL_SetGPUScissor($pass, $scissor);
                            break;

                        case Op::LAYER_SOURCE:
                            // the target's texture is bound as the image's source by the IMAGE that follows
                            break;

                        case Op::GLASS_BACKDROP:
                            // the region read once and taken down and up the pyramid, in passes of their own; a kept pyramid is as it was
                            [$pyramid, $builds] = $glass[$index];
                            $panes = $pyramid;
                            if (! $builds) {
                                break;
                            }
                            SDL_EndGPURenderPass($pass);
                            foreach ($pyramid['passes'] as [$kind, $input, $out, $a, $b]) {
                                $color = new SDL_GPUColorTargetInfo();
                                $color->texture = $out;
                                $color->load_op = SDL_GPU_LOADOP_DONT_CARE;
                                $color->store_op = SDL_GPU_STOREOP_STORE;
                                $gp = SDL_BeginGPURenderPass($commands, [$color], 1, null)
                                    ?? throw new DrawingException('sdl3: no render pass could begin: '.SDL_GetError());
                                SDL_BindGPUGraphicsPipeline($gp, $this->glassPipeline());
                                $binding = new SDL_GPUTextureSamplerBinding();
                                $binding->texture = $input;
                                $binding->sampler = $this->sampler();
                                SDL_BindGPUFragmentSamplers($gp, 0, [$binding], 1);
                                $uniform = pack('g8', (float) $kind, 0.0, 0.0, 0.0, $a, $b, 0.0, 0.0);
                                SDL_PushGPUFragmentUniformData($commands, 0, $uniform, strlen($uniform));
                                SDL_DrawGPUPrimitives($gp, 3, 1, 0, 0);
                                SDL_EndGPURenderPass($gp);
                            }
                            $pass = $begin($current, SDL_GPU_LOADOP_LOAD);
                            SDL_SetGPUScissor($pass, $scissor);
                            break;

                        case Op::GLASS:
                            [, $first, $geometry, $look, $depth] = $operation;
                            if ($panes === null) {
                                break;
                            }
                            [$frost, $refraction, $bevel, $saturation, $glow, $grain, $tint, $rimColour, $alpha] = $look;
                            SDL_BindGPUGraphicsPipeline($pass, $this->pipeline('fill'));
                            $this->bindTexture($pass, $panes['samples'][$depth], $panes['sharp']);
                            $this->uniforms($commands, self::paintBytes(self::GLASS, $tint, opacity: $alpha, linear: $frost < 1.0 ? 1 : 0,
                                shape: [(float) $geometry[0], (float) $geometry[1], (float) $geometry[2], (float) $geometry[3]],
                                band: [(float) $frost, (float) $refraction, (float) $bevel, (float) $saturation],
                                abcd: [(float) $glow, (float) $grain, (float) $panes['x'], (float) $panes['y']],
                                radii: array_map(floatval(...), $geometry[4]),
                                win: [(float) (($rimColour >> 24) & 0xFF), (float) (($rimColour >> 16) & 0xFF), (float) (($rimColour >> 8) & 0xFF), (float) ($rimColour & 0xFF)]));
                            SDL_DrawGPUPrimitives($pass, 6, 1, $first, 0);
                            $this->bindTexture($pass, $this->blank());
                            break;

                        case Op::CLEAR:
                            SDL_SetGPUScissor($pass, self::rect(0, 0, $width, $height));
                            $this->paint($pass, $commands, 'solid', self::SOLID, $operation[1]);
                            SDL_DrawGPUPrimitives($pass, 6, 1, $whole, 0);
                            SDL_SetGPUScissor($pass, $scissor);
                            break;

                        case Op::SCISSOR:
                            $scissor = self::rect($operation[1]->x, $operation[1]->y, $operation[1]->width, $operation[1]->height);
                            SDL_SetGPUScissor($pass, $scissor);
                            break;

                        case Op::SOLID:
                            $this->paint($pass, $commands, 'solid', self::SOLID, $operation[2]);
                            SDL_DrawGPUPrimitives($pass, 6, 1, $operation[1], 0);
                            break;

                        case Op::STENCIL_FILL:
                            // SOLID never discards: every fragment of the path reaches the stencil.
                            $this->paint($pass, $commands, $operation[3] === FillRule::NON_ZERO ? 'winding' : 'invert', self::SOLID, 0);
                            SDL_DrawGPUPrimitives($pass, $operation[2], 1, $operation[1], 0);
                            break;

                        case Op::COVER:
                            $this->paint($pass, $commands, 'cover', self::FILL, $operation[2]);
                            SDL_SetGPUStencilReference($pass, 0);
                            SDL_DrawGPUPrimitives($pass, 6, 1, $operation[1], 0);
                            break;

                        case Op::COVER_GRADIENT:
                            // As COVER, in the gradient: the ramp is the bound source, the shader reads its entry for each pixel.
                            [, $first, $texture, $kind, $inverse, $spread] = $operation;
                            SDL_BindGPUGraphicsPipeline($pass, $this->pipeline('cover'));
                            $this->bindTexture($pass, $textures[$texture]);
                            $this->uniforms($commands, self::paintBytes(self::GRADIENT, 0, band: [(float) $kind, (float) $spread, 0.0, 0.0],
                                abcd: [$inverse->a, $inverse->b, $inverse->c, $inverse->d], efwh: [$inverse->e, $inverse->f, 0.0, 0.0]));
                            SDL_SetGPUStencilReference($pass, 0);
                            SDL_DrawGPUPrimitives($pass, 6, 1, $first, 0);
                            break;

                        case Op::RECTS:
                            $this->paint($pass, $commands, 'fill', self::FILL, $operation[3]);
                            SDL_DrawGPUPrimitives($pass, $operation[2], 1, $operation[1], 0);
                            break;

                        case Op::ELLIPSE:
                            [, $first, $cx, $cy, $rx, $ry, $rgba] = $operation;
                            SDL_BindGPUGraphicsPipeline($pass, $this->pipeline('fill'));
                            $this->uniforms($commands, self::paintBytes(self::OVAL, $rgba, shape: [$cx, $cy, $rx, $ry], band: [0.0, 0.0, (float) $this->samples, 0.0]));
                            SDL_DrawGPUPrimitives($pass, 6, 1, $first, 0);
                            break;

                        case Op::RING:
                            [, $first, $cx, $cy, $rx, $ry, $stroke, $rgba] = $operation;
                            SDL_BindGPUGraphicsPipeline($pass, $this->pipeline('fill'));
                            $this->uniforms($commands, self::paintBytes(self::OVAL, $rgba, shape: [$cx, $cy, $rx, $ry], band: [$stroke, 1.0, (float) $this->samples, 0.0]));
                            SDL_DrawGPUPrimitives($pass, 6, 1, $first, 0);
                            break;

                        case Op::UPLOAD:
                        case Op::ATLAS:
                        case Op::MASK:
                            break;

                        case Op::GLYPHS:
                            // The texels come from the frame's vertices again: slot 1, offset so vertex k reads $texels + k.
                            [, $first, $count, $texels, $atlas, $rgba] = $operation;
                            SDL_BindGPUGraphicsPipeline($pass, $this->pipeline('fill'));
                            $this->bindTexture($pass, $this->atlases[$atlas]);
                            $this->uniforms($commands, self::paintBytes(self::GLYPH, $rgba));
                            $texelBinding = new SDL_GPUBufferBinding();
                            $texelBinding->buffer = $buffer;
                            $texelBinding->offset = ($texels - $first) * 8;
                            SDL_BindGPUVertexBuffers($pass, 1, [$texelBinding], 1);
                            SDL_DrawGPUPrimitives($pass, $count, 1, $first, 0);
                            $texelBinding->offset = 0;
                            SDL_BindGPUVertexBuffers($pass, 1, [$texelBinding], 1);
                            $this->bindTexture($pass, $this->blank());
                            break;

                        case Op::ROUNDRECT:
                        case Op::ROUNDRING:
                            $ring = $operation[0] === Op::ROUNDRING;
                            [, $first, $x0, $y0, $x1, $y1, $r] = $operation;
                            SDL_BindGPUGraphicsPipeline($pass, $this->pipeline('fill'));
                            $this->uniforms($commands, self::paintBytes(self::ROUND, $operation[$ring ? 8 : 7], shape: [$x0, $y0, $x1, $y1],
                                band: [$ring ? (float) $operation[7] : 0.0, $ring ? 1.0 : 0.0, (float) $this->samples, 0.0],
                                radii: [(float) $r[0], (float) $r[1], (float) $r[2], (float) $r[3]]));
                            SDL_DrawGPUPrimitives($pass, 6, 1, $first, 0);
                            break;

                        case Op::SHADOW:
                            [, $first, $x0, $y0, $x1, $y1, $radius, $blur, $rgba] = $operation;
                            SDL_BindGPUGraphicsPipeline($pass, $this->pipeline('fill'));
                            $this->uniforms($commands, self::paintBytes(self::SHADE, $rgba, shape: [$x0, $y0, $x1, $y1], band: [(float) $blur, (float) $radius, (float) $this->samples, 0.0]));
                            SDL_DrawGPUPrimitives($pass, 6, 1, $first, 0);
                            break;

                        case Op::IMAGE:
                            [, $first, $texture, $inverse, $opacity, $filter, $tint, $window, $round] = $operation;
                            SDL_BindGPUGraphicsPipeline($pass, $this->pipeline('fill'));
                            $this->bindTexture($pass, $textures[$texture]);
                            [$w, $h] = $this->sizes[spl_object_id($textures[$texture])];
                            $this->uniforms($commands, self::paintBytes(self::IMAGE, $tint, opacity: $opacity, linear: $filter === Filter::LINEAR ? 1 : 0,
                                shape: $round === null ? [0.0, 0.0, 0.0, 0.0] : [(float) $round[0], (float) $round[1], (float) $round[2], (float) $round[3]],
                                band: [$round === null ? 0.0 : 1.0, $window === null ? 0.0 : 1.0, (float) $this->samples, 0.0],
                                abcd: [$inverse->a, $inverse->b, $inverse->c, $inverse->d], efwh: [$inverse->e, $inverse->f, (float) $w, (float) $h], flags: isset($this->floatTextures[spl_object_id($textures[$texture])]) ? 2 : 0,
                                radii: $round === null ? [0.0, 0.0, 0.0, 0.0] : array_map(floatval(...), $round[4]),
                                win: array_map(floatval(...), $window ?? [0.0, 0.0, 0.0, 0.0])));
                            SDL_DrawGPUPrimitives($pass, 6, 1, $first, 0);
                            break;
                    }
                }

                SDL_EndGPURenderPass($pass);
            });
        } finally {
            $taken = array_flip(array_map(spl_object_id(...), $this->uploaded));
            foreach ($textures as $texture) {
                if (isset($layerTextures[spl_object_id($texture)]) || isset($taken[spl_object_id($texture)])) {
                    continue;
                }
                unset($this->sizes[spl_object_id($texture)]);
                unset($this->floatTextures[spl_object_id($texture)]);
                SDL_ReleaseGPUTexture($this->gpu, $texture);
            }
            foreach ($scratch as $texture) {
                SDL_ReleaseGPUTexture($this->gpu, $texture);
            }
            $this->keepUploads();
        }
    }

    /**
     * A pyramid for $region of the target texture under a list's panes: levels (the capture, down to the deepest depth, and
     * up to half size for each depth) from the pool, and the passes that make them.
     *
     * @param  list<int>  $depths
     * @return array<string, mixed>
     */
    private function glassBuild(SDL_GPUTexture $target, Region $region, array $depths): array
    {
        $levels = [];
        $passes = [];
        $capture = $levels[] = $this->glassLevel($region->width, $region->height);
        $passes[] = [0, $target, $capture[0], (float) $region->x, (float) $region->y];
        $down = [$capture];
        for ($k = 1, $deepest = max($depths); $k <= $deepest; $k++) {
            [, $w, $h] = $down[$k - 1];
            $down[$k] = $levels[] = $this->glassLevel(intdiv($w + 1, 2), intdiv($h + 1, 2));
            $passes[] = [1, $down[$k - 1][0], $down[$k][0], (float) $w, (float) $h];
        }
        $samples = [];
        foreach ($depths as $depth) {
            $up = $down[$depth];
            for ($k = $depth - 1; $k >= 1; $k--) {
                $out = $levels[] = $this->glassLevel($down[$k][1], $down[$k][2]);
                $passes[] = [2, $up[0], $out[0], (float) $up[1], (float) $up[2]];
                $up = $out;
            }
            $samples[$depth] = $up[0];
        }

        return ['levels' => $levels, 'passes' => $passes, 'samples' => $samples, 'sharp' => $capture[0], 'x' => $region->x, 'y' => $region->y];
    }

    /** A pyramid let go: its levels back to the pool. */
    private function glassDiscard(array $pyramid): void
    {
        foreach ($pyramid['levels'] as $level) {
            $this->glassRelease($level);
        }
    }

    /** @return array{SDL_GPUTexture, int, int} A level of this size: a free texture from the pool, or a new one. */
    private function glassLevel(int $width, int $height): array
    {
        $key = "{$width}x{$height}";
        if (! empty($this->glassPool[$key])) {
            $this->glassBytes -= $width * $height * 4;

            return [array_pop($this->glassPool[$key]), $width, $height];
        }

        return [$this->texture($this->colorFormat, $width, $height, SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_COLOR_TARGET | SDL_GPU_TEXTUREUSAGE_SAMPLER), $width, $height];
    }

    /** @param array{SDL_GPUTexture, int, int} $level A level nothing keeps, for the next pyramid; over GLASS_POOL_BYTES the sizes used longest ago are released first. */
    private function glassRelease(array $level): void
    {
        [$texture, $width, $height] = $level;
        $key = "{$width}x{$height}";
        $list = $this->glassPool[$key] ?? [];
        $list[] = $texture;
        unset($this->glassPool[$key]);
        $this->glassPool[$key] = $list;
        $this->glassBytes += $width * $height * 4;
        while ($this->glassBytes > self::GLASS_POOL_BYTES && $this->glassPool !== []) {
            $oldest = array_key_first($this->glassPool);
            if ($this->glassPool[$oldest] === []) { // a size taken empty elsewhere holds nothing to free
                unset($this->glassPool[$oldest]);

                continue;
            }
            [$w, $h] = array_map('intval', explode('x', $oldest));
            SDL_ReleaseGPUTexture($this->gpu, array_shift($this->glassPool[$oldest]));
            $this->glassBytes -= $w * $h * 4;
            if ($this->glassPool[$oldest] === []) {
                unset($this->glassPool[$oldest]);
            }
        }
    }

    /** The glass pass pipeline: the filter's vertex stage, the glass fragment stage (one sampler, one uniform block), one colour target. */
    private function glassPipeline(): SDL_GPUGraphicsPipeline
    {
        if (! is_null($this->glassPipeline)) {
            return $this->glassPipeline;
        }
        $this->filterPipeline();
        $shader = new SDL_GPUShaderCreateInfo();
        $shader->stage = SDL_GPU_SHADERSTAGE_FRAGMENT;
        if (SDL_GetGPUShaderFormats($this->gpu) & SDL_GPU_SHADERFORMAT_SPIRV) {
            $shader->format = SDL_GPU_SHADERFORMAT_SPIRV;
            $shader->code = file_get_contents(self::SHADERS.'/glass.frag.spv');
            $shader->entrypoint = 'main';
        } else {
            $shader->format = SDL_GPU_SHADERFORMAT_MSL;
            $shader->code = file_get_contents(self::SHADERS.'/filter.metal');
            $shader->entrypoint = 'glass_paint';
        }
        $shader->num_uniform_buffers = 1;
        $shader->num_samplers = 1;
        $this->glassShader = SDL_CreateGPUShader($this->gpu, $shader)
            ?? throw new DrawingException('sdl3: the glass fragment shader was refused: '.SDL_GetError());
        $info = new SDL_GPUGraphicsPipelineCreateInfo();
        $info->vertex_shader = $this->filterShaders[SDL_GPU_SHADERSTAGE_VERTEX];
        $info->fragment_shader = $this->glassShader;
        $info->primitive_type = SDL_GPU_PRIMITIVETYPE_TRIANGLELIST;
        $info->rasterizer_state->fill_mode = SDL_GPU_FILLMODE_FILL;
        $info->rasterizer_state->cull_mode = SDL_GPU_CULLMODE_NONE;
        $info->rasterizer_state->front_face = SDL_GPU_FRONTFACE_COUNTER_CLOCKWISE;
        $info->multisample_state->sample_count = SDL_GPU_SAMPLECOUNT_1;
        $color = new SDL_GPUColorTargetDescription();
        $color->format = $this->colorFormat;
        $color->blend_state->enable_color_write_mask = true;
        $color->blend_state->color_write_mask = SDL_GPU_COLORCOMPONENT_R | SDL_GPU_COLORCOMPONENT_G | SDL_GPU_COLORCOMPONENT_B | SDL_GPU_COLORCOMPONENT_A;
        $info->target_info->color_target_descriptions = [$color];
        $info->target_info->has_depth_stencil_target = false;

        return $this->glassPipeline = SDL_CreateGPUGraphicsPipeline($this->gpu, $info)
            ?? throw new DrawingException("sdl3: the glass pipeline was refused by {$this->driver()}: ".SDL_GetError());
    }

    public function adopt(LentSurface $surface): void
    {
        $window = SDL_Window::fromPointer($surface->handle('window')) ?? throw new DrawingException('sdl3: the surface lent no window.');
        if (! SDL_ClaimWindowForGPUDevice($this->gpu, $window)) {
            throw new DrawingException('sdl3: the window could not be claimed for the device: '.SDL_GetError());
        }
        $this->window = $window;
        $this->lent = $surface;
        // MAILBOX where the window takes it until the window's vsync says otherwise: a present never waits on the display.
        $this->presentMode = SDL_WindowSupportsGPUPresentMode($this->gpu, $window, SDL_GPU_PRESENTMODE_MAILBOX) ? SDL_GPU_PRESENTMODE_MAILBOX : SDL_GPU_PRESENTMODE_VSYNC;
        $this->swapchainParameters();
        $surface->onRelease(fn () => $this->unclaim());
    }

    public function vsyncModes(): array
    {
        if (is_null($this->window)) {
            return [];
        }
        $modes = [VSync::On];
        if (SDL_WindowSupportsGPUPresentMode($this->gpu, $this->window, SDL_GPU_PRESENTMODE_IMMEDIATE)) {
            $modes[] = VSync::Off;
        }
        if (SDL_WindowSupportsGPUPresentMode($this->gpu, $this->window, SDL_GPU_PRESENTMODE_MAILBOX)) {
            $modes[] = VSync::Mailbox;
        }

        return $modes;
    }

    public function applyVsync(VSync $vsync): void
    {
        $mode = match ($vsync) {
            VSync::On => SDL_GPU_PRESENTMODE_VSYNC,
            VSync::Off => SDL_GPU_PRESENTMODE_IMMEDIATE,
            VSync::Mailbox => SDL_GPU_PRESENTMODE_MAILBOX,
            VSync::Adaptive => throw new DrawingException('sdl3 has no adaptive vsync.'),
        };
        $window = $this->window ?? throw new DrawingException('sdl3: adopt() a surface before applyVsync().');
        if (! SDL_WindowSupportsGPUPresentMode($this->gpu, $window, $mode)) {
            throw new DrawingException("sdl3: this window does not present with vsync {$vsync->value}.");
        }
        $this->presentMode = $mode;
        $this->swapchainParameters();
    }

    public function setFramesInFlight(int $frames): void
    {
        if ($frames < 1 || $frames > 3) {
            throw new DrawingException("sdl3 keeps 1 to 3 frames in flight, got {$frames}.");
        }
        if (! SDL_SetGPUAllowedFramesInFlight($this->gpu, $frames)) {
            throw new DrawingException('sdl3: the frames in flight were refused: '.SDL_GetError());
        }
    }

    /**
     * @internal The show pass's encoding for the current target: the
     * transform code (0 as stored, 1 extended linear, 2 PQ), the SDR white
     * level, and the nits an HDR10 value of 1.0 shows at.
     *
     * @return array{int, float, float}
     */
    public function transform(): array
    {
        $level = $this->lent?->hdr()?->sdrWhiteLevel ?? 1.0;
        $white = $level > 0.0 ? $level : 1.0;

        return [$this->transformCode(), $white, max(203.0, 80.0 * $white)];
    }

    /** The swapchain composition the target needs. */
    private function composition(): int
    {
        return $this->compositionFor($this->space);
    }

    /** Extended linear for both extended spaces, HDR10 ST 2084 for HDR10, SDR otherwise. */
    private function compositionFor(ColorSpace $space): int
    {
        return match ($space) {
            ColorSpace::ExtendedLinearSrgb, ColorSpace::ExtendedLinearDisplayP3 => SDL_GPU_SWAPCHAINCOMPOSITION_HDR_EXTENDED_LINEAR,
            ColorSpace::Hdr10Pq => SDL_GPU_SWAPCHAINCOMPOSITION_HDR10_ST2084,
            default => SDL_GPU_SWAPCHAINCOMPOSITION_SDR,
        };
    }

    /** The transform code: extended linear × white, HDR10 as PQ, SDR as stored. */
    private function transformCode(): int
    {
        return match ($this->space) {
            ColorSpace::ExtendedLinearSrgb, ColorSpace::ExtendedLinearDisplayP3 => 1,
            ColorSpace::Hdr10Pq => 2,
            default => 0,
        };
    }

    /** The swapchain's composition and present mode, for the claimed window. */
    private function swapchainParameters(): void
    {
        if (is_null($this->window)) {
            return;
        }
        if (! SDL_SetGPUSwapchainParameters($this->gpu, $this->window, $this->composition(), $this->presentMode)) {
            throw new DrawingException('sdl3: the swapchain refused its parameters: '.SDL_GetError());
        }
    }


    /** Give the window back: the GPU idle first, so nothing in flight still uses its swapchain. */
    private function unclaim(): void
    {
        if ($this->released || is_null($this->window)) {
            return;
        }
        $this->finish();
        SDL_WaitForGPUIdle($this->gpu);
        SDL_ReleaseWindowFromGPUDevice($this->gpu, $this->window);
        $this->window = null;
        $this->lent = null;
    }

    /** The show pass's shader of $stage, compiled once in the format the device takes: SPIR-V where it can, MSL otherwise. */
    public function showShader(int $stage): SDL_GPUShader
    {
        if (isset($this->showShaders[$stage])) {
            return $this->showShaders[$stage];
        }

        $fragment = $stage === SDL_GPU_SHADERSTAGE_FRAGMENT;
        $info = new SDL_GPUShaderCreateInfo();
        $info->stage = $stage;
        if (SDL_GetGPUShaderFormats($this->gpu) & SDL_GPU_SHADERFORMAT_SPIRV) {
            $info->format = SDL_GPU_SHADERFORMAT_SPIRV;
            $info->code = file_get_contents(self::SHADERS.'/show.'.($fragment ? 'frag' : 'vert').'.spv');
            $info->entrypoint = 'main';
        } else {
            $info->format = SDL_GPU_SHADERFORMAT_MSL;
            $info->code = file_get_contents(self::SHADERS.'/venusian.metal');
            $info->entrypoint = $fragment ? 'show' : 'show_place';
        }
        $info->num_uniform_buffers = $fragment ? 1 : 0;
        $info->num_samplers = $fragment ? 1 : 0;

        return $this->showShaders[$stage] = SDL_CreateGPUShader($this->gpu, $info)
            ?? throw new DrawingException('sdl3: the show '.($fragment ? 'fragment' : 'vertex').' shader was refused: '.SDL_GetError());
    }

    /** The show pipeline for a swapchain texture of $format: one triangle, no vertex input, no blending, one sample, no stencil. */
    private function showPipeline(int $format): SDL_GPUGraphicsPipeline
    {
        if (isset($this->showPipelines[$format])) {
            return $this->showPipelines[$format];
        }
        $info = new SDL_GPUGraphicsPipelineCreateInfo();
        $info->vertex_shader = $this->showShader(SDL_GPU_SHADERSTAGE_VERTEX);
        $info->fragment_shader = $this->showShader(SDL_GPU_SHADERSTAGE_FRAGMENT);
        $info->primitive_type = SDL_GPU_PRIMITIVETYPE_TRIANGLELIST;
        $info->rasterizer_state->fill_mode = SDL_GPU_FILLMODE_FILL;
        $info->rasterizer_state->cull_mode = SDL_GPU_CULLMODE_NONE;
        $info->rasterizer_state->front_face = SDL_GPU_FRONTFACE_COUNTER_CLOCKWISE;
        $info->multisample_state->sample_count = SDL_GPU_SAMPLECOUNT_1;
        $color = new SDL_GPUColorTargetDescription();
        $color->format = $format;
        $color->blend_state->enable_color_write_mask = true;
        $color->blend_state->color_write_mask = SDL_GPU_COLORCOMPONENT_R | SDL_GPU_COLORCOMPONENT_G | SDL_GPU_COLORCOMPONENT_B | SDL_GPU_COLORCOMPONENT_A;
        $color->blend_state->enable_blend = false;
        $info->target_info->color_target_descriptions = [$color];
        $info->target_info->has_depth_stencil_target = false;

        return $this->showPipelines[$format] = SDL_CreateGPUGraphicsPipeline($this->gpu, $info)
            ?? throw new DrawingException("sdl3: the show pipeline was refused by {$this->driver()}: ".SDL_GetError());
    }

    /**
     * Draw the resolved texture into the window's next swapchain texture with
     * the show pass (presentRect() at the swapchain's size, the surface's
     * filter, the bars black, encoded for the swapchain) and present. No
     * wait: under VSYNC off Metal nothing is presented while the window is
     * occluded, minimised or hidden; when the swapchain has no texture ready, the
     * command buffer is cancelled and nothing is drawn.
     */
    public function present(LentSurface $surface): bool
    {
        if ($surface->released()) {
            return false;
        }
        $window = $this->window ?? throw new DrawingException('sdl3: adopt() a surface before present().');
        $target = $this->framebuffer ?? throw new DrawingException('sdl3: target() comes before present().');
        // Metal hands out drawables to hidden windows (measured); elsewhere VSYNC waits on the compositor.
        if ($this->presentMode === SDL_GPU_PRESENTMODE_VSYNC && $this->driver() !== 'metal' && (SDL_GetWindowFlags($window) & (SDL_WINDOW_OCCLUDED | SDL_WINDOW_MINIMIZED | SDL_WINDOW_HIDDEN)) !== 0) {
            return false;
        }

        $commands = $this->commands();
        $swapchain = null;
        $width = null;
        $height = null;
        if (! SDL_AcquireGPUSwapchainTexture($commands, $window, $swapchain, $width, $height) || is_null($swapchain)) {
            SDL_CancelGPUCommandBuffer($commands);

            return false;
        }
        [$filter, $fit] = $surface->scaling();
        $placed = $fit->rect($width, $height, $target->width(), $target->height());
        [$transform, $white, $nits] = $this->transform();

        $color = new SDL_GPUColorTargetInfo();
        $color->texture = $swapchain;
        $color->load_op = SDL_GPU_LOADOP_DONT_CARE;
        $color->store_op = SDL_GPU_STOREOP_STORE;
        $pass = SDL_BeginGPURenderPass($commands, [$color], 1, null)
            ?? throw new DrawingException('sdl3: the show pass could not begin: '.SDL_GetError());
        SDL_BindGPUGraphicsPipeline($pass, $this->showPipeline(SDL_GetGPUSwapchainTextureFormat($this->gpu, $window)));
        $this->bindTexture($pass, $this->resolved);
        $show = pack('g4', (float) $placed->x, (float) $placed->y, (float) $placed->width, (float) $placed->height)
            .pack('g4', (float) $target->width(), (float) $target->height(), $white, $nits)
            .pack('V4', $filter === ScaleFilter::Linear ? 1 : 0, $transform, 0, 0);
        SDL_PushGPUFragmentUniformData($commands, 0, $show, strlen($show));
        SDL_DrawGPUPrimitives($pass, 3, 1, 0, 0);
        SDL_EndGPURenderPass($pass);
        $this->submit($commands);

        return true;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->finish();
        // Idle cleans every submitted command buffer; only then are the fences let go.
        SDL_WaitForGPUIdle($this->gpu);
        foreach ($this->pending as $fence) {
            SDL_ReleaseGPUFence($this->gpu, $fence);
        }
        $this->pending = [];
        $this->releaseTarget();
        foreach ($this->owned as $texture) {
            SDL_ReleaseGPUTexture($this->gpu, $texture);
        }
        $this->owned = [];
        foreach ($this->atlases as $texture) {
            SDL_ReleaseGPUTexture($this->gpu, $texture);
        }
        $this->atlases = [];
        $this->framebuffer = null;
        if (! is_null($this->sampler)) {
            SDL_ReleaseGPUSampler($this->gpu, $this->sampler);
            $this->sampler = null;
        }
        if (! is_null($this->blank)) {
            SDL_ReleaseGPUTexture($this->gpu, $this->blank);
            $this->blank = null;
        }
        foreach ($this->shaders as $shader) {
            SDL_ReleaseGPUShader($this->gpu, $shader);
        }
        $this->shaders = [];
        foreach ($this->glassKept as $kept) {
            foreach ($kept['levels'] as [$texture]) {
                SDL_ReleaseGPUTexture($this->gpu, $texture);
            }
        }
        $this->glassKept = [];
        foreach ($this->glassPool as $textures) {
            foreach ($textures as $texture) {
                SDL_ReleaseGPUTexture($this->gpu, $texture);
            }
        }
        $this->glassPool = [];
        foreach ($this->sourcePool as $textures) {
            array_map($this->releaseSource(...), $textures);
        }
        $this->sourcePool = [];
        foreach ($this->stagings as $staging) {
            SDL_ReleaseGPUTransferBuffer($this->gpu, $staging);
        }
        $this->stagings = [];
        is_null($this->vertexGpu) || SDL_ReleaseGPUBuffer($this->gpu, $this->vertexGpu);
        is_null($this->vertexTransfer) || SDL_ReleaseGPUTransferBuffer($this->gpu, $this->vertexTransfer);
        $this->vertexGpu = $this->vertexTransfer = null;
        $this->vertexGpuSize = $this->vertexTransferSize = 0;
        if (! is_null($this->glassPipeline)) {
            SDL_ReleaseGPUGraphicsPipeline($this->gpu, $this->glassPipeline);
            SDL_ReleaseGPUShader($this->gpu, $this->glassShader);
            $this->glassPipeline = $this->glassShader = null;
        }
        if (! is_null($this->filterPipeline)) {
            SDL_ReleaseGPUGraphicsPipeline($this->gpu, $this->filterPipeline);
            $this->filterPipeline = null;
        }
        foreach ($this->filterShaders as $shader) {
            SDL_ReleaseGPUShader($this->gpu, $shader);
        }
        $this->filterShaders = [];
        if (! is_null($this->window)) {
            SDL_ReleaseWindowFromGPUDevice($this->gpu, $this->window);
            $this->window = null;
            $this->lent = null;
        }
        foreach ($this->showPipelines as $pipeline) {
            SDL_ReleaseGPUGraphicsPipeline($this->gpu, $pipeline);
        }
        foreach ($this->showShaders as $shader) {
            SDL_ReleaseGPUShader($this->gpu, $shader);
        }
        $this->showPipelines = $this->showShaders = [];
        if ($this->owns_gpu) {
            SDL_DestroyGPUDevice($this->gpu);
        }
        $this->released = true;
    }

    /** Viewport, the vertex buffer and the target size every pass over the target starts with. */
    private function prepare(SDL_GPURenderPass $pass, SDL_GPUCommandBuffer $commands, SDL_GPUBuffer $buffer, int $width, int $height): void
    {
        $viewport = new SDL_GPUViewport();
        [$viewport->x, $viewport->y, $viewport->w, $viewport->h, $viewport->min_depth, $viewport->max_depth] = [0.0, 0.0, (float) $width, (float) $height, 0.0, 1.0];
        SDL_SetGPUViewport($pass, $viewport);
        $binding = new SDL_GPUBufferBinding();
        $binding->buffer = $buffer;
        // Slot 1 carries GLYPHS' texels; every other draw leaves it at offset 0 and reads nothing from it.
        SDL_BindGPUVertexBuffers($pass, 0, [$binding, $binding], 2);
        $frame = pack('g4', (float) $width, (float) $height, 0.0, 0.0);
        SDL_PushGPUVertexUniformData($commands, 0, $frame, strlen($frame));
        // The fragment program declares a sampler: every draw needs one bound, read or not.
        $this->bindTexture($pass, $this->blank());
    }

    /** A 1 × 1 texture bound for the modes that sample nothing; never read. */
    private function blank(): SDL_GPUTexture
    {
        return $this->blank ??= $this->texture(SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM, 1, 1, SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
    }

    /** One pass that draws the resolved texture back into the multisampled one, after an upload. */
    private function restore(SDL_GPUCommandBuffer $commands, SDL_GPUBuffer $buffer, int $whole, int $width, int $height): void
    {
        $color = new SDL_GPUColorTargetInfo();
        $color->texture = $this->multisampled;
        $color->load_op = SDL_GPU_LOADOP_DONT_CARE;
        $color->store_op = SDL_GPU_STOREOP_STORE;
        $pass = SDL_BeginGPURenderPass($commands, [$color], 1, $this->stencilTarget())
            ?? throw new DrawingException('sdl3: no render pass could begin: '.SDL_GetError());
        $this->prepare($pass, $commands, $buffer, $width, $height);
        SDL_BindGPUGraphicsPipeline($pass, $this->pipeline('solid'));
        $this->bindTexture($pass, $this->resolved);
        $this->uniforms($commands, self::paintBytes(self::COPY, 0));
        SDL_DrawGPUPrimitives($pass, 6, 1, $whole, 0);
        SDL_EndGPURenderPass($pass);
        $this->stale = false;
    }

    /** A flat-colour draw: the pipeline and the Paint. */
    private function paint(SDL_GPURenderPass $pass, SDL_GPUCommandBuffer $commands, string $pipeline, int $mode, int $rgba): void
    {
        SDL_BindGPUGraphicsPipeline($pass, $this->pipeline($pipeline));
        $this->uniforms($commands, self::paintBytes($mode, $rgba));
    }

    /** The Paint block, with the target's HDR flag ORed into mode_rgba[0].w (byte 12). */
    private function uniforms(SDL_GPUCommandBuffer $commands, string $bytes): void
    {
        $flags = unpack('V', $bytes, 12)[1] | ($this->space->isHdr() ? 1 : 0);
        $bytes = substr_replace($bytes, pack('V', $flags), 12, 4);
        SDL_PushGPUFragmentUniformData($commands, 0, $bytes, strlen($bytes));
    }

    private function bindTexture(SDL_GPURenderPass $pass, SDL_GPUTexture $texture, ?SDL_GPUTexture $sharp = null): void
    {
        $bindings = [];
        foreach ([$texture, $sharp ?? $this->blank()] as $each) {
            $binding = new SDL_GPUTextureSamplerBinding();
            $binding->texture = $each;
            $binding->sampler = $this->sampler();
            $bindings[] = $binding;
        }
        SDL_BindGPUFragmentSamplers($pass, 0, $bindings, 2);
    }

    /** A nearest, clamp-to-edge sampler: the shader fetches texels itself and never filters. */
    private function sampler(): SDL_GPUSampler
    {
        if (! is_null($this->sampler)) {
            return $this->sampler;
        }
        $info = new SDL_GPUSamplerCreateInfo();
        $info->min_filter = SDL_GPU_FILTER_NEAREST;
        $info->mag_filter = SDL_GPU_FILTER_NEAREST;
        $info->mipmap_mode = SDL_GPU_SAMPLERMIPMAPMODE_NEAREST;
        $info->address_mode_u = SDL_GPU_SAMPLERADDRESSMODE_CLAMP_TO_EDGE;
        $info->address_mode_v = SDL_GPU_SAMPLERADDRESSMODE_CLAMP_TO_EDGE;
        $info->address_mode_w = SDL_GPU_SAMPLERADDRESSMODE_CLAMP_TO_EDGE;

        return $this->sampler = SDL_CreateGPUSampler($this->gpu, $info) ?? throw new DrawingException('sdl3: no sampler could be made: '.SDL_GetError());
    }

    /** The smallest a vertex buffer or a staging buffer is made: 256 KiB holds about 32,000 vertices. */
    private const int RING_MIN = 262144;

    /** Source textures kept for each size, and the sizes kept. */
    private const int POOL_PER_SIZE = 8;

    private const int POOL_SIZES = 24;

    /** The vertex buffer kept across frames (G3), and the transfer buffer that fills it: both written with cycle on, so SDL keeps the contents a frame in flight reads. */
    private ?SDL_GPUBuffer $vertexGpu = null;

    private int $vertexGpuSize = 0;

    private ?SDL_GPUTransferBuffer $vertexTransfer = null;

    private int $vertexTransferSize = 0;

    /** @var array<int, SDL_GPUTransferBuffer> Staging buffers for sources, by size class (powers of two, 4 KiB at least). */
    private array $stagings = [];

    /** @var array<string, list<SDL_GPUTexture>> Free source textures by "format x w x h", the size used last at the end. */
    private array $sourcePool = [];

    /** @var list<SDL_GPUTexture> The source textures this frame made or took; they go back to the pool when it ends. */
    private array $uploaded = [];

    /** A vertex buffer of at least this many bytes: the kept one, or a larger one (the old goes when no frame reads it). */
    private function vertexBuffer(string $vertices): SDL_GPUBuffer
    {
        $length = strlen($vertices);
        if (is_null($this->vertexGpu) || $this->vertexGpuSize < $length) {
            $info = new SDL_GPUBufferCreateInfo();
            $info->usage = SDL_GPU_BUFFERUSAGE_VERTEX;
            $info->size = max(self::RING_MIN, 1 << (int) ceil(log($length, 2)));
            $made = SDL_CreateGPUBuffer($this->gpu, $info) ?? throw new DrawingException('sdl3: no vertex buffer could be made: '.SDL_GetError());
            is_null($this->vertexGpu) || SDL_ReleaseGPUBuffer($this->gpu, $this->vertexGpu);
            [$this->vertexGpu, $this->vertexGpuSize] = [$made, $info->size];
        }

        return $this->vertexGpu;
    }

    /** The frame's vertices into the buffer, in a copy pass at the front of the command buffer. */
    private function uploadVertices(SDL_GPUCommandBuffer $commands, SDL_GPUBuffer $buffer, string $vertices): void
    {
        $length = strlen($vertices);
        if (is_null($this->vertexTransfer) || $this->vertexTransferSize < $length) {
            $size = max(self::RING_MIN, 1 << (int) ceil(log($length, 2)));
            $made = $this->transferBuffer($size, SDL_GPU_TRANSFERBUFFERUSAGE_UPLOAD);
            is_null($this->vertexTransfer) || SDL_ReleaseGPUTransferBuffer($this->gpu, $this->vertexTransfer);
            [$this->vertexTransfer, $this->vertexTransferSize] = [$made, $size];
        }
        $this->fillTransfer($this->vertexTransfer, $vertices, cycle: true);
        $copy = SDL_BeginGPUCopyPass($commands) ?? throw new DrawingException('sdl3: no copy pass could begin: '.SDL_GetError());
        $source = new SDL_GPUTransferBufferLocation();
        $source->transfer_buffer = $this->vertexTransfer;
        $destination = new SDL_GPUBufferRegion();
        $destination->buffer = $buffer;
        $destination->size = $length;
        SDL_UploadToGPUBuffer($copy, $source, $destination, true);
        SDL_EndGPUCopyPass($copy);
    }

    private function uploadRegion(SDL_GPUCommandBuffer $commands, SDL_GPUTexture $texture, Region $region, string $bytes): void
    {
        $staging = $this->transferBuffer(strlen($bytes), SDL_GPU_TRANSFERBUFFERUSAGE_UPLOAD);
        try {
            $this->fillTransfer($staging, $bytes);
            $copy = SDL_BeginGPUCopyPass($commands) ?? throw new DrawingException('sdl3: no copy pass could begin: '.SDL_GetError());
            $from = new SDL_GPUTextureTransferInfo();
            $from->transfer_buffer = $staging;
            $from->pixels_per_row = $region->width;
            $from->rows_per_layer = $region->height;
            SDL_UploadToGPUTexture($copy, $from, self::region($texture, $region), false);
            SDL_EndGPUCopyPass($copy);
        } finally {
            SDL_ReleaseGPUTransferBuffer($this->gpu, $staging);
        }
    }

    /** An image source as a sampled RGBA8 texture, uploaded in a copy pass of this command buffer. */
    private function upload(SDL_GPUCommandBuffer $commands, Framebuffer $source): SDL_GPUTexture
    {
        $width = $source->viewportWidth();
        $height = $source->viewportHeight();
        $float = $source instanceof HdrImage && $this->space->isHdr();
        $format = $float ? SDL_GPU_TEXTUREFORMAT_R16G16B16A16_FLOAT : SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM;
        $key = "{$format}x{$width}x{$height}";
        $texture = ! empty($this->sourcePool[$key]) ? array_pop($this->sourcePool[$key]) : $this->texture($format, $width, $height, SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
        $this->sizes[spl_object_id($texture)] = [$width, $height];
        $float && $this->floatTextures[spl_object_id($texture)] = true;
        $this->uploaded[] = $texture;
        $texels = $float ? $source->rgba16f() : $source->toRgba8();
        $class = max(4096, 1 << (int) ceil(log(max(1, strlen($texels)), 2)));
        $staging = $this->stagings[$class] ??= $this->transferBuffer($class, SDL_GPU_TRANSFERBUFFERUSAGE_UPLOAD);
        $this->fillTransfer($staging, $texels, cycle: true);
        $copy = SDL_BeginGPUCopyPass($commands) ?? throw new DrawingException('sdl3: no copy pass could begin: '.SDL_GetError());
        $from = new SDL_GPUTextureTransferInfo();
        $from->transfer_buffer = $staging;
        $from->pixels_per_row = $width;
        $from->rows_per_layer = $height;
        SDL_UploadToGPUTexture($copy, $from, self::region($texture, Region::wholeSurface($width, $height)), true);
        SDL_EndGPUCopyPass($copy);

        return $texture;
    }

    /** The frame is over: the source textures it took are free for the next, the sizes used longest ago let go first. */
    private function keepUploads(): void
    {
        foreach ($this->uploaded as $texture) {
            [$width, $height] = $this->sizes[spl_object_id($texture)];
            $key = (isset($this->floatTextures[spl_object_id($texture)]) ? SDL_GPU_TEXTUREFORMAT_R16G16B16A16_FLOAT : SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM)."x{$width}x{$height}";
            $list = $this->sourcePool[$key] ?? [];
            $list[] = $texture;
            unset($this->sourcePool[$key]);
            $this->sourcePool[$key] = $list;
            while (count($this->sourcePool[$key]) > self::POOL_PER_SIZE) {
                $this->releaseSource(array_shift($this->sourcePool[$key]));
            }
        }
        $this->uploaded = [];
        while (count($this->sourcePool) > self::POOL_SIZES) {
            $oldest = array_key_first($this->sourcePool);
            array_map($this->releaseSource(...), $this->sourcePool[$oldest]);
            unset($this->sourcePool[$oldest]);
        }
    }

    private function releaseSource(SDL_GPUTexture $texture): void
    {
        unset($this->sizes[spl_object_id($texture)], $this->floatTextures[spl_object_id($texture)]);
        SDL_ReleaseGPUTexture($this->gpu, $texture);
    }

    /**
     * The pipelines, by kind, for the current sample count. All share the two
     * shaders, RGBA8 colour, the device's stencil format and the sample count:
     *
     *   solid    blending off, colour written
     *   fill     source-over blending, colour written, stencil untouched
     *   cover    as fill, drawn where the stencil is not zero, zeroing it
     *   winding  no colour; front faces count the stencil up, back faces down (NON_ZERO)
     *   invert   no colour; every face inverts the stencil (EVEN_ODD)
     */
    private function pipeline(string $kind): SDL_GPUGraphicsPipeline
    {
        if (isset($this->pipelines[$kind])) {
            return $this->pipelines[$kind];
        }

        $info = new SDL_GPUGraphicsPipelineCreateInfo();
        $info->vertex_shader = $this->shader(SDL_GPU_SHADERSTAGE_VERTEX);
        $info->fragment_shader = $this->shader(SDL_GPU_SHADERSTAGE_FRAGMENT);
        $info->primitive_type = SDL_GPU_PRIMITIVETYPE_TRIANGLELIST;

        $description = new SDL_GPUVertexBufferDescription();
        $description->slot = 0;
        $description->pitch = 8;
        $description->input_rate = SDL_GPU_VERTEXINPUTRATE_VERTEX;
        $attribute = new SDL_GPUVertexAttribute();
        $attribute->location = 0;
        $attribute->buffer_slot = 0;
        $attribute->format = SDL_GPU_VERTEXELEMENTFORMAT_FLOAT2;
        // The same vertex buffer again in slot 1: the atlas texel of GLYPHS (location 1).
        $texels = new SDL_GPUVertexBufferDescription();
        $texels->slot = 1;
        $texels->pitch = 8;
        $texels->input_rate = SDL_GPU_VERTEXINPUTRATE_VERTEX;
        $texel = new SDL_GPUVertexAttribute();
        $texel->location = 1;
        $texel->buffer_slot = 1;
        $texel->format = SDL_GPU_VERTEXELEMENTFORMAT_FLOAT2;
        $info->vertex_input_state->vertex_buffer_descriptions = [$description, $texels];
        $info->vertex_input_state->vertex_attributes = [$attribute, $texel];

        $info->rasterizer_state->fill_mode = SDL_GPU_FILLMODE_FILL;
        $info->rasterizer_state->cull_mode = SDL_GPU_CULLMODE_NONE;
        $info->rasterizer_state->front_face = SDL_GPU_FRONTFACE_COUNTER_CLOCKWISE;
        $info->multisample_state->sample_count = $this->samples === 4 ? SDL_GPU_SAMPLECOUNT_4 : SDL_GPU_SAMPLECOUNT_1;

        $stencil = $info->depth_stencil_state;
        $stencil->enable_stencil_test = in_array($kind, ['cover', 'winding', 'invert'], true);
        $stencil->compare_mask = 0xFF;
        $stencil->write_mask = 0xFF;
        foreach (['front' => $stencil->front_stencil_state, 'back' => $stencil->back_stencil_state] as $side => $face) {
            $face->fail_op = SDL_GPU_STENCILOP_KEEP;
            $face->depth_fail_op = SDL_GPU_STENCILOP_KEEP;
            $face->compare_op = $kind === 'cover' ? SDL_GPU_COMPAREOP_NOT_EQUAL : SDL_GPU_COMPAREOP_ALWAYS;
            $face->pass_op = match ($kind) {
                'cover' => SDL_GPU_STENCILOP_ZERO,
                'winding' => $side === 'front' ? SDL_GPU_STENCILOP_INCREMENT_AND_WRAP : SDL_GPU_STENCILOP_DECREMENT_AND_WRAP,
                'invert' => SDL_GPU_STENCILOP_INVERT,
                default => SDL_GPU_STENCILOP_KEEP,
            };
        }

        $color = new SDL_GPUColorTargetDescription();
        $color->format = $this->colorFormat;
        $blend = $color->blend_state;
        $blend->enable_color_write_mask = true;
        $blend->color_write_mask = in_array($kind, ['winding', 'invert'], true)
            ? 0
            : SDL_GPU_COLORCOMPONENT_R | SDL_GPU_COLORCOMPONENT_G | SDL_GPU_COLORCOMPONENT_B | SDL_GPU_COLORCOMPONENT_A;
        $blend->enable_blend = in_array($kind, ['fill', 'cover'], true);
        $blend->src_color_blendfactor = SDL_GPU_BLENDFACTOR_SRC_ALPHA;
        $blend->dst_color_blendfactor = SDL_GPU_BLENDFACTOR_ONE_MINUS_SRC_ALPHA;
        $blend->color_blend_op = SDL_GPU_BLENDOP_ADD;
        $blend->src_alpha_blendfactor = SDL_GPU_BLENDFACTOR_ONE;
        $blend->dst_alpha_blendfactor = SDL_GPU_BLENDFACTOR_ONE_MINUS_SRC_ALPHA;
        $blend->alpha_blend_op = SDL_GPU_BLENDOP_ADD;
        $info->target_info->color_target_descriptions = [$color];
        $info->target_info->has_depth_stencil_target = true;
        $info->target_info->depth_stencil_format = $this->stencil_format;

        return $this->pipelines[$kind] = SDL_CreateGPUGraphicsPipeline($this->gpu, $info)
            ?? throw new DrawingException("sdl3: the '{$kind}' pipeline was refused by {$this->driver()}: ".SDL_GetError());
    }

    /**
     * The Paint block, 128 bytes: mode_rgba[0] = mode, opacity, linear, flags;
     * mode_rgba[1] = r, g, b, a; shape; band; abcd; efwh; radii; win.
     *
     * @param  array{float, float, float, float}  $shape
     * @param  array{float, float, float, float}  $band
     * @param  array{float, float, float, float}  $abcd
     * @param  array{float, float, float, float}  $efwh
     * @param  array{float, float, float, float}  $radii
     * @param  array{float, float, float, float}  $win
     */
    private static function paintBytes(int $mode, int $rgba, int $opacity = 255, int $linear = 0, array $shape = [0.0, 0.0, 0.0, 0.0], array $band = [0.0, 0.0, 0.0, 0.0], array $abcd = [0.0, 0.0, 0.0, 0.0], array $efwh = [0.0, 0.0, 0.0, 0.0], int $flags = 0, array $radii = [0.0, 0.0, 0.0, 0.0], array $win = [0.0, 0.0, 0.0, 0.0]): string
    {
        return pack('V4', $mode, $opacity, $linear, $flags)
            .pack('V4', ($rgba >> 24) & 0xFF, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF)
            .pack('g4', ...$shape).pack('g4', ...$band).pack('g4', ...$abcd).pack('g4', ...$efwh).pack('g4', ...$radii).pack('g4', ...$win);
    }

    /** Six vertices: x0,y0 x1,y0 x1,y1 x0,y0 x1,y1 x0,y1, as Lowering lays a quad. */
    private static function quad(float $x0, float $y0, float $x1, float $y1): string
    {
        return pack('g12', $x0, $y0, $x1, $y0, $x1, $y1, $x0, $y0, $x1, $y1, $x0, $y1);
    }

    private static function rect(int $x, int $y, int $width, int $height): SDL_Rect
    {
        return new SDL_Rect($x, $y, $width, $height);
    }

    private function texture(int $format, int $width, int $height, int $samples, int $usage): SDL_GPUTexture
    {
        // SDL_GPU's 2D bound and Metal's on Apple silicon; past it Metal aborts the process instead of answering null.
        if ($width > self::LARGEST || $height > self::LARGEST) {
            throw new DrawingException("sdl3: {$width} × {$height} is past the largest texture SDL_GPU makes, ".self::LARGEST.' × '.self::LARGEST.'.');
        }
        $info = new SDL_GPUTextureCreateInfo();
        $info->type = SDL_GPU_TEXTURETYPE_2D;
        $info->format = $format;
        $info->usage = $usage;
        $info->width = $width;
        $info->height = $height;
        $info->layer_count_or_depth = 1;
        $info->num_levels = 1;
        $info->sample_count = $samples;

        return SDL_CreateGPUTexture($this->gpu, $info)
            ?? throw new DrawingException("sdl3: no {$width} × {$height} texture could be made: ".SDL_GetError());
    }

    private function transferBuffer(int $size, int $usage): SDL_GPUTransferBuffer
    {
        $info = new SDL_GPUTransferBufferCreateInfo();
        $info->usage = $usage;
        $info->size = $size;

        return SDL_CreateGPUTransferBuffer($this->gpu, $info)
            ?? throw new DrawingException("sdl3: no {$size}-byte transfer buffer could be made: ".SDL_GetError());
    }

    /** $bytes into a mapped transfer buffer. */
    private function fillTransfer(SDL_GPUTransferBuffer $buffer, string $bytes, bool $cycle = false): void
    {
        $address = SDL_MapGPUTransferBuffer($this->gpu, $buffer, $cycle);
        if ($address === 0) {
            throw new DrawingException('sdl3: a transfer buffer could not be mapped: '.SDL_GetError());
        }
        $io = SDL_IOFromMem($address, strlen($bytes));
        $written = SDL_WriteIO($io, $bytes, strlen($bytes));
        SDL_CloseIO($io);
        SDL_UnmapGPUTransferBuffer($this->gpu, $buffer);
        if ($written !== strlen($bytes)) {
            throw new DrawingException("sdl3: {$written} of ".strlen($bytes).' bytes reached the transfer buffer.');
        }
    }

    /** The colour target of a pass over the target: loaded or cleared to transparent black, stored, resolved with four samples. */
    private function colorTarget(int $load, int $slot = 0): SDL_GPUColorTargetInfo
    {
        $color = new SDL_GPUColorTargetInfo();
        $color->load_op = $load;
        [$color->clear_color->r, $color->clear_color->g, $color->clear_color->b, $color->clear_color->a] = [0.0, 0.0, 0.0, 0.0];
        $multisampled = $slot === 0 ? $this->multisampled : $this->slots[$slot]['multisampled'];
        $resolved = $this->slotTexture($slot);
        if (is_null($multisampled)) {
            $color->texture = $resolved;
            $color->store_op = SDL_GPU_STOREOP_STORE;
        } else {
            $color->texture = $multisampled;
            $color->resolve_texture = $resolved;
            $color->store_op = SDL_GPU_STOREOP_RESOLVE_AND_STORE;
        }

        return $color;
    }

    /** The resolved texture of a target: the frame's own for 0, a layer target's otherwise. */
    private function slotTexture(int $slot): SDL_GPUTexture
    {
        return $slot === 0 ? $this->resolved : $this->slots[$slot]['resolved'];
    }

    /**
     * A layer target, made on first use: the frame's own kind of texture (colour at its samples, resolved, a stencil).
     *
     * @throws DrawingException On a target in an HDR colour space.
     */
    private function ensureSlot(int $slot): void
    {
        if ($slot === 0 || isset($this->slots[$slot])) {
            return;
        }
        if ($this->space->isHdr()) {
            throw new DrawingException('sdl3: layers are not drawn on a target in an HDR colour space yet.');
        }
        $width = $this->framebuffer->width();
        $height = $this->framebuffer->height();
        $resolved = $this->texture($this->colorFormat, $width, $height, SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_COLOR_TARGET | SDL_GPU_TEXTUREUSAGE_SAMPLER);
        $this->sizes[spl_object_id($resolved)] = [$width, $height];
        $this->slots[$slot] = [
            'resolved' => $resolved,
            'multisampled' => $this->samples === 4 ? $this->texture($this->colorFormat, $width, $height, SDL_GPU_SAMPLECOUNT_4, SDL_GPU_TEXTUREUSAGE_COLOR_TARGET) : null,
            'stencil' => $this->texture($this->stencil_format, $width, $height, $this->samples === 4 ? SDL_GPU_SAMPLECOUNT_4 : SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_DEPTH_STENCIL_TARGET),
        ];
    }

    /** $from's $source region copied into $into's $destination region, no blending, outside a pass. */
    private function blit(SDL_GPUCommandBuffer $commands, SDL_GPUTexture $from, SDL_GPUTexture $into, Region $source, Region $destination): void
    {
        $info = new SDL_GPUBlitInfo();
        [$info->source->texture, $info->source->x, $info->source->y, $info->source->w, $info->source->h] = [$from, $source->x, $source->y, $source->width, $source->height];
        [$info->destination->texture, $info->destination->x, $info->destination->y, $info->destination->w, $info->destination->h] = [$into, $destination->x, $destination->y, $destination->width, $destination->height];
        $info->load_op = SDL_GPU_LOADOP_LOAD;
        $info->filter = SDL_GPU_FILTER_NEAREST;
        SDL_BlitGPUTexture($commands, $info);
    }

    /** The filter program: a triangle and the filter fragment shader, one RGBA8 colour target and nothing else. */
    private function filterPipeline(): SDL_GPUGraphicsPipeline
    {
        if (! is_null($this->filterPipeline)) {
            return $this->filterPipeline;
        }
        foreach ([SDL_GPU_SHADERSTAGE_VERTEX, SDL_GPU_SHADERSTAGE_FRAGMENT] as $stage) {
            $fragment = $stage === SDL_GPU_SHADERSTAGE_FRAGMENT;
            $shader = new SDL_GPUShaderCreateInfo();
            $shader->stage = $stage;
            if (SDL_GetGPUShaderFormats($this->gpu) & SDL_GPU_SHADERFORMAT_SPIRV) {
                $shader->format = SDL_GPU_SHADERFORMAT_SPIRV;
                $shader->code = file_get_contents(self::SHADERS.'/filter.'.($fragment ? 'frag' : 'vert').'.spv');
                $shader->entrypoint = 'main';
            } else {
                $shader->format = SDL_GPU_SHADERFORMAT_MSL;
                $shader->code = file_get_contents(self::SHADERS.'/filter.metal');
                $shader->entrypoint = $fragment ? 'filter_paint' : 'filter_place';
            }
            $shader->num_uniform_buffers = $fragment ? 1 : 0;
            $shader->num_samplers = $fragment ? 4 : 0;
            $this->filterShaders[$stage] = SDL_CreateGPUShader($this->gpu, $shader)
                ?? throw new DrawingException('sdl3: the filter '.($fragment ? 'fragment' : 'vertex').' shader was refused: '.SDL_GetError());
        }
        $info = new SDL_GPUGraphicsPipelineCreateInfo();
        $info->vertex_shader = $this->filterShaders[SDL_GPU_SHADERSTAGE_VERTEX];
        $info->fragment_shader = $this->filterShaders[SDL_GPU_SHADERSTAGE_FRAGMENT];
        $info->primitive_type = SDL_GPU_PRIMITIVETYPE_TRIANGLELIST;
        $info->rasterizer_state->fill_mode = SDL_GPU_FILLMODE_FILL;
        $info->rasterizer_state->cull_mode = SDL_GPU_CULLMODE_NONE;
        $info->rasterizer_state->front_face = SDL_GPU_FRONTFACE_COUNTER_CLOCKWISE;
        $info->multisample_state->sample_count = SDL_GPU_SAMPLECOUNT_1;
        $color = new SDL_GPUColorTargetDescription();
        $color->format = $this->colorFormat;
        $color->blend_state->enable_color_write_mask = true;
        $color->blend_state->color_write_mask = SDL_GPU_COLORCOMPONENT_R | SDL_GPU_COLORCOMPONENT_G | SDL_GPU_COLORCOMPONENT_B | SDL_GPU_COLORCOMPONENT_A;
        $info->target_info->color_target_descriptions = [$color];
        $info->target_info->has_depth_stencil_target = false;

        return $this->filterPipeline = SDL_CreateGPUGraphicsPipeline($this->gpu, $info)
            ?? throw new DrawingException("sdl3: the filter pipeline was refused by {$this->driver()}: ".SDL_GetError());
    }

    /** The stencil, cleared and dropped every pass. */
    private function stencilTarget(int $slot = 0): SDL_GPUDepthStencilTargetInfo
    {
        $stencil = new SDL_GPUDepthStencilTargetInfo();
        $stencil->texture = $slot === 0 ? $this->stencil : $this->slots[$slot]['stencil'];
        $stencil->clear_depth = 1.0;
        $stencil->load_op = SDL_GPU_LOADOP_CLEAR;
        $stencil->store_op = SDL_GPU_STOREOP_DONT_CARE;
        $stencil->stencil_load_op = SDL_GPU_LOADOP_CLEAR;
        $stencil->stencil_store_op = SDL_GPU_STOREOP_DONT_CARE;
        $stencil->clear_stencil = 0;

        return $stencil;
    }

    private function commands(): SDL_GPUCommandBuffer
    {
        return SDL_AcquireGPUCommandBuffer($this->gpu) ?? throw new DrawingException('sdl3: no command buffer could be acquired: '.SDL_GetError());
    }

    /**
     * Acquire a command buffer, let $encode fill it, and submit it. A throw
     * from $encode cancels the buffer, so nothing is left acquired.
     *
     * @param  Closure(SDL_GPUCommandBuffer): void  $encode
     */
    private function encode(Closure $encode): void
    {
        $commands = $this->commands();
        try {
            $encode($commands);
        } catch (Throwable $failure) {
            SDL_CancelGPUCommandBuffer($commands);

            throw $failure;
        }
        $this->submit($commands);
    }

    /**
     * Submit with a fence, which finish() waits on. Fences seen to signal
     * before this submit are let go after it: this submit is the one in which
     * SDL cleans their command buffers.
     */
    private function submit(SDL_GPUCommandBuffer $commands): void
    {
        $signalled = [];
        $waiting = [];
        foreach ($this->pending as $earlier) {
            if (SDL_QueryGPUFence($this->gpu, $earlier)) {
                $signalled[] = $earlier;
            } else {
                $waiting[] = $earlier;
            }
        }
        $fence = SDL_SubmitGPUCommandBufferAndAcquireFence($commands);
        foreach ($signalled as $earlier) {
            SDL_ReleaseGPUFence($this->gpu, $earlier);
        }
        $this->pending = $waiting;
        $this->pending[] = $fence ?? throw new DrawingException('sdl3: the command buffer was not accepted: '.SDL_GetError());
    }

    /** @throws DrawingException Once release() has run. */
    private function live(): void
    {
        if ($this->released) {
            throw new DrawingException('sdl3: this device was released.');
        }
    }

    /** The multisampled and stencil textures and the pipelines; the resolved texture is its Sdl3Framebuffer's. */
    private function releaseTarget(): void
    {
        foreach ($this->slots as $slot) {
            unset($this->sizes[spl_object_id($slot['resolved'])]);
            foreach ([$slot['resolved'], $slot['multisampled'], $slot['stencil']] as $texture) {
                if (! is_null($texture)) {
                    SDL_ReleaseGPUTexture($this->gpu, $texture);
                }
            }
        }
        $this->slots = [];
        foreach ([$this->multisampled, $this->stencil] as $texture) {
            if (! is_null($texture)) {
                SDL_ReleaseGPUTexture($this->gpu, $texture);
            }
        }
        foreach ($this->pipelines as $pipeline) {
            SDL_ReleaseGPUGraphicsPipeline($this->gpu, $pipeline);
        }
        $this->resolved = $this->multisampled = $this->stencil = null;
        $this->pipelines = [];
        $this->stale = false;
    }

    private static function region(SDL_GPUTexture $texture, Region $region): SDL_GPUTextureRegion
    {
        $target = new SDL_GPUTextureRegion();
        $target->texture = $texture;
        [$target->x, $target->y, $target->w, $target->h, $target->d] = [$region->x, $region->y, $region->width, $region->height, 1];

        return $target;
    }
}
