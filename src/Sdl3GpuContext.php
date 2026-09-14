<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3;

use Jovian\Bindings\Sdl3\Enums\SDLGPUBlendFactor;
use Jovian\Bindings\Sdl3\Enums\SDLGPUBlendOp;
use Jovian\Bindings\Sdl3\Enums\SDLGPUCullMode;
use Jovian\Bindings\Sdl3\Enums\SDLGPUFillMode;
use Jovian\Bindings\Sdl3\Enums\SDLGPUFilter;
use Jovian\Bindings\Sdl3\Enums\SDLGPUFrontFace;
use Jovian\Bindings\Sdl3\Enums\SDLGPUSampleCount;
use Jovian\Bindings\Sdl3\Enums\SDLGPUSamplerAddressMode;
use Jovian\Bindings\Sdl3\Enums\SDLGPUSamplerMipmapMode;
use Jovian\Bindings\Sdl3\Enums\SDLGPUShaderFormat;
use Jovian\Bindings\Sdl3\Enums\SDLGPUShaderStage;
use Jovian\Bindings\Sdl3\Enums\SDLGPUTextureFormat;
use Jovian\Bindings\Sdl3\Enums\SDLGPUTextureType;
use Jovian\Bindings\Sdl3\Enums\SDLGPUTextureUsageFlags;
use Jovian\Bindings\Sdl3\Enums\SDLGPUTransferBufferUsage;
use Jovian\Bindings\Sdl3\Enums\SDLGPUVertexElementFormat;
use Jovian\Bindings\Sdl3\Enums\SDLGPUVertexInputRate;
use Jovian\Bindings\Sdl3\Gpu\SDLGPU;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3DrawingException;
use Jovian\Venusian\Sdl3\Shaders\Spirv;

/**
 * One SDL_GPUDevice per engine instance — the provider's gpu.sdl3 singleton
 * keeps an app to one per process — with its painter shaders, sampler, a
 * 1×1 white placeholder, and a pipeline per (swapchain format, primitive),
 * built on first use. Cached on Sdl3GpuEngine; never destroyed.
 */
final class Sdl3GpuContext
{
    /** @var array<int, array<int, int>> format => primitive => pipeline */
    private array $pipelines = [];

    private function __construct(
        public readonly int $device,
        public readonly int $vertexShader,
        public readonly int $fragmentShader,
        public readonly int $sampler,
        public readonly int $placeholder,
    ) {}

    public static function mslSource(): string
    {
        /** @var string $source */
        $source = require __DIR__.'/Shaders/painter.msl.php';

        return $source;
    }

    public static function boot(): self
    {
        try {
            $device = SDLGPU::SDLCreateGPUDevice(SDLGPUShaderFormat::MSL->value | SDLGPUShaderFormat::SPIRV->value, false);
        } catch (\RuntimeException $e) {
            throw Sdl3DrawingException::noGpuDriver($e->getMessage());
        }

        try {
            $msl = (SDLGPU::SDLGetGPUShaderFormats($device) & SDLGPUShaderFormat::MSL->value) !== 0;
            $vertex = SDLGPU::SDLCreateGPUShader($device, [
                'code' => $msl ? self::mslSource() : Spirv::load('painter.vert.spv'),
                'entrypoint' => $msl ? 'painter_vertex' : 'main',
                'format' => $msl ? SDLGPUShaderFormat::MSL->value : SDLGPUShaderFormat::SPIRV->value,
                'stage' => SDLGPUShaderStage::VERTEX->value,
                'num_samplers' => 0,
                'num_storage_textures' => 0,
                'num_storage_buffers' => 0,
                'num_uniform_buffers' => 1,
            ]);
            $fragment = SDLGPU::SDLCreateGPUShader($device, [
                'code' => $msl ? self::mslSource() : Spirv::load('painter.frag.spv'),
                'entrypoint' => $msl ? 'painter_fragment' : 'main',
                'format' => $msl ? SDLGPUShaderFormat::MSL->value : SDLGPUShaderFormat::SPIRV->value,
                'stage' => SDLGPUShaderStage::FRAGMENT->value,
                'num_samplers' => 1,
                'num_storage_textures' => 0,
                'num_storage_buffers' => 0,
                'num_uniform_buffers' => 0,
            ]);
            $sampler = SDLGPU::SDLCreateGPUSampler($device, [
                'min_filter' => SDLGPUFilter::LINEAR->value,
                'mag_filter' => SDLGPUFilter::LINEAR->value,
                'mipmap_mode' => SDLGPUSamplerMipmapMode::NEAREST->value,
                'address_mode_u' => SDLGPUSamplerAddressMode::CLAMP_TO_EDGE->value,
                'address_mode_v' => SDLGPUSamplerAddressMode::CLAMP_TO_EDGE->value,
                'address_mode_w' => SDLGPUSamplerAddressMode::CLAMP_TO_EDGE->value,
            ]);

            return new self($device, $vertex, $fragment, $sampler, self::upload($device, "\xFF\xFF\xFF\xFF", 1, 1));
        } catch (\RuntimeException $e) {
            throw Sdl3DrawingException::from($e);
        }
    }

    /** A sampled RGBA8 texture filled on a one-shot command buffer. Submission order keeps it ahead of every later frame. */
    public function uploadTexture(string $rgba8, int $width, int $height): int
    {
        return self::upload($this->device, $rgba8, $width, $height);
    }

    public function pipeline(int $format, int $primitive): int
    {
        return $this->pipelines[$format][$primitive] ??= SDLGPU::SDLCreateGPUGraphicsPipeline($this->device, [
            'vertex_shader' => $this->vertexShader,
            'fragment_shader' => $this->fragmentShader,
            'primitive_type' => $primitive,
            'vertex_input_state' => [
                'vertex_buffer_descriptions' => [['slot' => 0, 'pitch' => 36, 'input_rate' => SDLGPUVertexInputRate::VERTEX->value, 'instance_step_rate' => 0]],
                'vertex_attributes' => [
                    ['location' => 0, 'buffer_slot' => 0, 'format' => SDLGPUVertexElementFormat::FLOAT3->value, 'offset' => 0],
                    ['location' => 1, 'buffer_slot' => 0, 'format' => SDLGPUVertexElementFormat::FLOAT4->value, 'offset' => 12],
                    ['location' => 2, 'buffer_slot' => 0, 'format' => SDLGPUVertexElementFormat::FLOAT2->value, 'offset' => 28],
                ],
            ],
            'rasterizer_state' => [
                'fill_mode' => SDLGPUFillMode::FILL->value,
                'cull_mode' => SDLGPUCullMode::NONE->value,
                'front_face' => SDLGPUFrontFace::COUNTER_CLOCKWISE->value,
            ],
            'target_info' => [
                'color_target_descriptions' => [[
                    'format' => $format,
                    'blend_state' => [
                        'enable_blend' => true,
                        'src_color_blendfactor' => SDLGPUBlendFactor::SRC_ALPHA->value,
                        'dst_color_blendfactor' => SDLGPUBlendFactor::ONE_MINUS_SRC_ALPHA->value,
                        'color_blend_op' => SDLGPUBlendOp::ADD->value,
                        'src_alpha_blendfactor' => SDLGPUBlendFactor::ONE->value,
                        'dst_alpha_blendfactor' => SDLGPUBlendFactor::ONE_MINUS_SRC_ALPHA->value,
                        'alpha_blend_op' => SDLGPUBlendOp::ADD->value,
                        'enable_color_write_mask' => false,
                    ],
                ]],
            ],
        ]);
    }

    private static function upload(int $device, string $rgba8, int $width, int $height): int
    {
        $bytes = $width * $height * 4;
        if (strlen($rgba8) !== $bytes) {
            throw Sdl3DrawingException::textureBytes($width, $height, strlen($rgba8));
        }

        $texture = SDLGPU::SDLCreateGPUTexture($device, [
            'type' => SDLGPUTextureType::GPU_TEXTURETYPE_2D->value,
            'format' => SDLGPUTextureFormat::R8G8B8A8_UNORM->value,
            'usage' => SDLGPUTextureUsageFlags::SAMPLER->value,
            'width' => $width,
            'height' => $height,
            'layer_count_or_depth' => 1,
            'num_levels' => 1,
            'sample_count' => SDLGPUSampleCount::GPU_SAMPLECOUNT_1->value,
        ]);
        $transfer = SDLGPU::SDLCreateGPUTransferBuffer($device, ['usage' => SDLGPUTransferBufferUsage::UPLOAD->value, 'size' => $bytes]);
        SDLGPU::writeToGPUTransferBuffer($device, $transfer, $rgba8, false, 0);

        $cb = SDLGPU::SDLAcquireGPUCommandBuffer($device);
        $copy = SDLGPU::SDLBeginGPUCopyPass($cb);
        SDLGPU::SDLUploadToGPUTexture(
            $copy,
            ['transfer_buffer' => $transfer, 'offset' => 0, 'pixels_per_row' => $width, 'rows_per_layer' => $height],
            ['texture' => $texture, 'mip_level' => 0, 'layer' => 0, 'x' => 0, 'y' => 0, 'z' => 0, 'w' => $width, 'h' => $height, 'd' => 1],
            false,
        );
        SDLGPU::SDLEndGPUCopyPass($copy);
        SDLGPU::SDLSubmitGPUCommandBuffer($cb);
        SDLGPU::SDLReleaseGPUTransferBuffer($device, $transfer);   // release is deferred until the GPU is done

        return $texture;
    }
}
