#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// A small Gaussian along an axis: integer weights for -R..R that sum to 256, (sum + 128) >> 8. The 64 weights are 16
// ivec4s: std140 pads an int array to 16 bytes an element.
layout(set = 2, binding = 0) uniform sampler2D src;
layout(set = 3, binding = 0) uniform Kernel { ivec2 size; uint horizontal; uint radius; uint edge; uint pad0; uint pad1; uint pad2; ivec4 weights[16]; } k;

void main()
{
    ivec2 p = pixel();
    ivec2 stride = k.horizontal != 0u ? ivec2(1, 0) : ivec2(0, 1);
    uvec4 sum = uvec4(0u);
    for (int i = 0; i <= int(2u * k.radius); i++) {
        ivec2 q = p + stride * (i - int(k.radius));
        int place = k.horizontal != 0u ? q.x : q.y;
        int extent = k.horizontal != 0u ? k.size.x : k.size.y;
        if (place < 0 || place >= extent) {
            if (k.edge == 0u) {
                continue;
            }
        }
        sum += uint(k.weights[i >> 2][i & 3]) * fetchOf(src, q, k.size, 1u);
    }
    color = unbyte((sum + 128u) >> 8);
}
