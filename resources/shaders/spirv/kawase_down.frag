#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// PHPLandGlass::down(): (4 x the 2 x 2 centre + the 4 x 4 block + 16) >> 5, rows and columns held to the region.
layout(set = 2, binding = 0) uniform sampler2D src;
layout(set = 3, binding = 0) uniform Down { ivec4 region; } d;

void main()
{
    ivec2 p = pixel();
    int right = d.region.x + d.region.z - 1;
    int bottom = d.region.y + d.region.w - 1;
    uvec4 all = uvec4(0u);
    uvec4 centre = uvec4(0u);
    for (int b = 0; b < 4; b++) {
        int y = clamp(d.region.y + 2 * p.y - 1 + b, d.region.y, bottom);
        for (int a = 0; a < 4; a++) {
            int x = clamp(d.region.x + 2 * p.x - 1 + a, d.region.x, right);
            uvec4 v = byte4(texelFetch(src, ivec2(x, y), 0));
            all += v;
            if ((a == 1 || a == 2) && (b == 1 || b == 2)) {
                centre += v;
            }
        }
    }
    color = unbyte((4u * centre + all + 16u) >> 5);
}
