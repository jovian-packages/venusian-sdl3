#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// Points on a golden-angle spiral, each read bilinearly, averaged. The offsets are pairs of floats, two to a vec4.
layout(set = 2, binding = 0) uniform sampler2D src;
layout(set = 3, binding = 0) uniform Spiral { ivec2 size; uint samples; uint edge; } s;
layout(set = 3, binding = 1) uniform Offsets { vec4 pairs[256]; } offsets;

void main()
{
    ivec2 p = pixel();
    uvec4 sum = uvec4(0u);
    for (uint i = 0u; i < s.samples; i++) {
        vec4 pair = offsets.pairs[i >> 1];
        vec2 o = (i & 1u) == 0u ? pair.xy : pair.zw;
        sum += bilinear(src, s.size, float(p.x) + 0.5 + o.x, float(p.y) + 0.5 + o.y, s.edge);
    }
    color = unbyte((sum + s.samples / 2u) / s.samples);
}
