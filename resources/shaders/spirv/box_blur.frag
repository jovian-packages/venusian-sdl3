#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// One box blur along an axis: output i is the mean of i - left .. i - left + width - 1, rounded to nearest.
layout(set = 2, binding = 0) uniform sampler2D src;
layout(set = 3, binding = 0) uniform Box { ivec2 size; uint horizontal; uint width; int left; uint edge; uvec2 pad; } b;

void main()
{
    ivec2 p = pixel();
    ivec2 stride = b.horizontal != 0u ? ivec2(1, 0) : ivec2(0, 1);
    uvec4 sum = uvec4(0u);
    for (int j = 0; j < int(b.width); j++) {
        sum += fetchOf(src, p + stride * (j - b.left), b.size, b.edge);
    }
    color = unbyte((sum + b.width / 2u) / b.width);
}
