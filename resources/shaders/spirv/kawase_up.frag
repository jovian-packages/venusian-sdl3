#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// PHPLandGlass::up(): the 8-tap tent in quarter pixels, (sum + 96) / 192, edges held.
layout(set = 2, binding = 0) uniform sampler2D src;
layout(set = 3, binding = 0) uniform Up { ivec2 size; } up;

uvec4 tap(int x4, int y4)
{
    ivec2 size = up.size;
    int ix = x4 >> 2;
    int iy = y4 >> 2;
    int fx = x4 & 3;
    int fy = y4 & 3;
    int x0 = clamp(ix, 0, size.x - 1);
    int x1 = clamp(ix + 1, 0, size.x - 1);
    int y0 = clamp(iy, 0, size.y - 1);
    int y1 = clamp(iy + 1, 0, size.y - 1);
    uvec4 a = byte4(texelFetch(src, ivec2(x0, y0), 0));
    uvec4 b = byte4(texelFetch(src, ivec2(x1, y0), 0));
    uvec4 c = byte4(texelFetch(src, ivec2(x0, y1), 0));
    uvec4 d = byte4(texelFetch(src, ivec2(x1, y1), 0));
    return uint(4 - fy) * (uint(4 - fx) * a + uint(fx) * b) + uint(fy) * (uint(4 - fx) * c + uint(fx) * d);
}

void main()
{
    ivec2 p = pixel();
    int x4 = 2 * p.x - 1;
    int y4 = 2 * p.y - 1;
    uvec4 sum = tap(x4 - 4, y4) + tap(x4 + 4, y4) + tap(x4, y4 - 4) + tap(x4, y4 + 4)
        + 2u * (tap(x4 - 2, y4 - 2) + tap(x4 + 2, y4 - 2) + tap(x4 - 2, y4 + 2) + tap(x4 + 2, y4 + 2));
    color = unbyte((sum + 96u) / 192u);
}
