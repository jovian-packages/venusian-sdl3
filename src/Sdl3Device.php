<?php

namespace Jovian\Engines\Sdl3;

use Closure;
use SDL_FColor;
use SDL_GPUBlitInfo;
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
final class Sdl3Device implements GpuDevice, SwitchesVsync, QueuesFrames, TargetsFormats
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

    private ?SDL_GPUTexture $blank = null;

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
        $info->num_samplers = $fragment ? 1 : 0;

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
            $pass = SDL_BeginGPURenderPass($commands, [$this->colorTarget(SDL_GPU_LOADOP_CLEAR)], $this->stencilTarget())
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
            $io = SDL_IOFromConstMem($address, $size);
            $bytes = SDL_ReadIO($io, $size);
            SDL_CloseIO($io);
            SDL_UnmapGPUTransferBuffer($this->gpu, $download);

            return $bytes;
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
            SDL_WaitForGPUFences($this->gpu, true, $this->pending);
        }
    }

    public function draw(DrawList $list): void
    {
        $target = $this->framebuffer ?? throw new DrawingException('sdl3: target() comes before draw().');
        $width = $target->width();
        $height = $target->height();

        // The list's vertices, then one quad over the whole target for CLEAR and the restore.
        $whole = $list->vertexCount();
        $vertices = $list->vertices.self::quad(0.0, 0.0, (float) $width, (float) $height);
        $buffer = $this->vertexBuffer($vertices);
        /** @var array<int, SDL_GPUTexture> $textures */
        $textures = [];
        try {
            $this->encode(function (SDL_GPUCommandBuffer $commands) use ($list, $buffer, $vertices, $whole, $width, $height, &$textures): void {
                $this->uploadVertices($commands, $buffer, $vertices);
                foreach ($list->operations as $operation) {
                    if ($operation[0] === Op::UPLOAD) {
                        $textures[$operation[1]] = $this->upload($commands, $operation[2]);
                    }
                }
                if ($this->stale) {
                    $this->restore($commands, $buffer, $whole, $width, $height);
                }

                $pass = SDL_BeginGPURenderPass($commands, [$this->colorTarget(SDL_GPU_LOADOP_LOAD)], $this->stencilTarget())
                    ?? throw new DrawingException('sdl3: no render pass could begin: '.SDL_GetError());
                $this->prepare($pass, $commands, $buffer, $width, $height);
                $scissor = self::rect(0, 0, $width, $height);

                foreach ($list->operations as $operation) {
                    switch ($operation[0]) {
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
                            break;

                        case Op::IMAGE:
                            [, $first, $texture, $inverse, $opacity, $filter] = $operation;
                            SDL_BindGPUGraphicsPipeline($pass, $this->pipeline('fill'));
                            $this->bindTexture($pass, $textures[$texture]);
                            [$w, $h] = $this->sizes[spl_object_id($textures[$texture])];
                            $this->uniforms($commands, self::paintBytes(self::IMAGE, 0, opacity: $opacity, linear: $filter === Filter::LINEAR ? 1 : 0,
                                abcd: [$inverse->a, $inverse->b, $inverse->c, $inverse->d], efwh: [$inverse->e, $inverse->f, (float) $w, (float) $h], flags: isset($this->floatTextures[spl_object_id($textures[$texture])]) ? 2 : 0));
                            SDL_DrawGPUPrimitives($pass, 6, 1, $first, 0);
                            break;
                    }
                }

                SDL_EndGPURenderPass($pass);
            });
        } finally {
            foreach ($textures as $texture) {
                unset($this->sizes[spl_object_id($texture)]);
                unset($this->floatTextures[spl_object_id($texture)]);
                SDL_ReleaseGPUTexture($this->gpu, $texture);
            }
            SDL_ReleaseGPUBuffer($this->gpu, $buffer);
        }
    }

    public function adopt(LentSurface $surface): void
    {
        $window = SDL_Window::fromPointer($surface->handle('window'));
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
        $pass = SDL_BeginGPURenderPass($commands, [$color], null)
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
        SDL_BindGPUVertexBuffers($pass, 0, [$binding]);
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
        $pass = SDL_BeginGPURenderPass($commands, [$color], $this->stencilTarget())
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

    private function bindTexture(SDL_GPURenderPass $pass, SDL_GPUTexture $texture): void
    {
        $binding = new SDL_GPUTextureSamplerBinding();
        $binding->texture = $texture;
        $binding->sampler = $this->sampler();
        SDL_BindGPUFragmentSamplers($pass, 0, [$binding]);
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

    private function vertexBuffer(string $vertices): SDL_GPUBuffer
    {
        $info = new SDL_GPUBufferCreateInfo();
        $info->usage = SDL_GPU_BUFFERUSAGE_VERTEX;
        $info->size = strlen($vertices);

        return SDL_CreateGPUBuffer($this->gpu, $info) ?? throw new DrawingException('sdl3: no vertex buffer could be made: '.SDL_GetError());
    }

    /** The frame's vertices into the buffer, in a copy pass at the front of the command buffer. */
    private function uploadVertices(SDL_GPUCommandBuffer $commands, SDL_GPUBuffer $buffer, string $vertices): void
    {
        $upload = $this->transferBuffer(strlen($vertices), SDL_GPU_TRANSFERBUFFERUSAGE_UPLOAD);
        try {
            $this->fillTransfer($upload, $vertices);
            $copy = SDL_BeginGPUCopyPass($commands) ?? throw new DrawingException('sdl3: no copy pass could begin: '.SDL_GetError());
            $source = new SDL_GPUTransferBufferLocation();
            $source->transfer_buffer = $upload;
            $destination = new SDL_GPUBufferRegion();
            $destination->buffer = $buffer;
            $destination->size = strlen($vertices);
            SDL_UploadToGPUBuffer($copy, $source, $destination, false);
            SDL_EndGPUCopyPass($copy);
        } finally {
            SDL_ReleaseGPUTransferBuffer($this->gpu, $upload);
        }
    }

    /** An image source as a sampled RGBA8 texture, uploaded in a copy pass of this command buffer. */
    private function upload(SDL_GPUCommandBuffer $commands, Framebuffer $source): SDL_GPUTexture
    {
        $width = $source->viewportWidth();
        $height = $source->viewportHeight();
        $float = $source instanceof HdrImage && $this->space->isHdr();
        $texture = $this->texture($float ? SDL_GPU_TEXTUREFORMAT_R16G16B16A16_FLOAT : SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM, $width, $height, SDL_GPU_SAMPLECOUNT_1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
        $this->sizes[spl_object_id($texture)] = [$width, $height];
        if ($float) {
            $this->floatTextures[spl_object_id($texture)] = true;
        }
        $staging = null;
        try {
            $texels = $float ? $source->rgba16f() : $source->toRgba8();
            $staging = $this->transferBuffer(strlen($texels), SDL_GPU_TRANSFERBUFFERUSAGE_UPLOAD);
            $this->fillTransfer($staging, $texels);
            $copy = SDL_BeginGPUCopyPass($commands) ?? throw new DrawingException('sdl3: no copy pass could begin: '.SDL_GetError());
            $from = new SDL_GPUTextureTransferInfo();
            $from->transfer_buffer = $staging;
            $from->pixels_per_row = $width;
            $from->rows_per_layer = $height;
            SDL_UploadToGPUTexture($copy, $from, self::region($texture, Region::wholeSurface($width, $height)), false);
            SDL_EndGPUCopyPass($copy);
        } catch (Throwable $failure) {
            unset($this->sizes[spl_object_id($texture)]);
            unset($this->floatTextures[spl_object_id($texture)]);
            SDL_ReleaseGPUTexture($this->gpu, $texture);

            throw $failure;
        } finally {
            if (! is_null($staging)) {
                SDL_ReleaseGPUTransferBuffer($this->gpu, $staging);
            }
        }

        return $texture;
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
        $info->vertex_input_state->vertex_buffer_descriptions = [$description];
        $info->vertex_input_state->vertex_attributes = [$attribute];

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
     * The Paint block, 96 bytes: mode_rgba[0] = mode, opacity, linear, flags;
     * mode_rgba[1] = r, g, b, a; shape; band; abcd; efwh.
     *
     * @param  array{float, float, float, float}  $shape
     * @param  array{float, float, float, float}  $band
     * @param  array{float, float, float, float}  $abcd
     * @param  array{float, float, float, float}  $efwh
     */
    private static function paintBytes(int $mode, int $rgba, int $opacity = 255, int $linear = 0, array $shape = [0.0, 0.0, 0.0, 0.0], array $band = [0.0, 0.0, 0.0, 0.0], array $abcd = [0.0, 0.0, 0.0, 0.0], array $efwh = [0.0, 0.0, 0.0, 0.0], int $flags = 0): string
    {
        return pack('V4', $mode, $opacity, $linear, $flags)
            .pack('V4', ($rgba >> 24) & 0xFF, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF)
            .pack('g4', ...$shape).pack('g4', ...$band).pack('g4', ...$abcd).pack('g4', ...$efwh);
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
    private function fillTransfer(SDL_GPUTransferBuffer $buffer, string $bytes): void
    {
        $address = SDL_MapGPUTransferBuffer($this->gpu, $buffer, false);
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
    private function colorTarget(int $load): SDL_GPUColorTargetInfo
    {
        $color = new SDL_GPUColorTargetInfo();
        $color->load_op = $load;
        [$color->clear_color->r, $color->clear_color->g, $color->clear_color->b, $color->clear_color->a] = [0.0, 0.0, 0.0, 0.0];
        if (is_null($this->multisampled)) {
            $color->texture = $this->resolved;
            $color->store_op = SDL_GPU_STOREOP_STORE;
        } else {
            $color->texture = $this->multisampled;
            $color->resolve_texture = $this->resolved;
            $color->store_op = SDL_GPU_STOREOP_RESOLVE_AND_STORE;
        }

        return $color;
    }

    /** The stencil, cleared and dropped every pass. */
    private function stencilTarget(): SDL_GPUDepthStencilTargetInfo
    {
        $stencil = new SDL_GPUDepthStencilTargetInfo();
        $stencil->texture = $this->stencil;
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
