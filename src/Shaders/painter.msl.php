<?php

declare(strict_types=1);

/**
 * The Surface painter in MSL on SDL_GPU's binding layout (SDL_gpu.h,
 * SDL_CreateGPUShader): vertex input through [[stage_in]] (SDL binds vertex
 * buffer 0 at [[buffer(14)]] itself), vertex uniform buffer 0 at
 * [[buffer(0)]], fragment texture/sampler 0. [[point_size]] is required for
 * a point-list pipeline. SDL_GPU clip space is y-up: no flip.
 */
return <<<'MSL'
#include <metal_stdlib>
using namespace metal;

struct VertexIn {
    float3 position [[attribute(0)]];
    float4 color [[attribute(1)]];
    float2 uv [[attribute(2)]];
};

struct VertexOut {
    float4 position [[position]];
    float4 color;
    float2 uv;
    float point_size [[point_size]];
};

vertex VertexOut painter_vertex(VertexIn in [[stage_in]], constant float4x4 &projection [[buffer(0)]])
{
    VertexOut out;
    out.position = projection * float4(in.position.xy, 0.0, 1.0);
    out.color = in.color;
    out.uv = in.uv;
    out.point_size = 1.0;
    return out;
}

fragment float4 painter_fragment(VertexOut in [[stage_in]], texture2d<float> tex [[texture(0)]], sampler samp [[sampler(0)]])
{
    return in.color * tex.sample(samp, in.uv);
}
MSL;
