#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// operator 0 over, 1 in, 2 out, 3 atop, 4 xor, 5 arithmetic (k1..k4)
layout(set = 2, binding = 0) uniform sampler2D a;
layout(set = 2, binding = 1) uniform sampler2D b;
layout(set = 3, binding = 0) uniform Compose { uint op; uint pad0; uint pad1; uint pad2; vec4 k; } c;

void main()
{
    ivec2 p = pixel();
    vec4 x = vec4(byte4(texelFetch(a, p, 0))) / 255.0;
    vec4 y = vec4(byte4(texelFetch(b, p, 0))) / 255.0;
    float aa = x.a;
    float ab = y.a;
    vec4 v;
    switch (c.op) {
        case 0u: v = x + y * (1.0 - aa); break;
        case 1u: v = x * ab; break;
        case 2u: v = x * (1.0 - ab); break;
        case 3u: v = x * ab + y * (1.0 - aa); break;
        case 4u: v = x * (1.0 - ab) + y * (1.0 - aa); break;
        default: v = c.k.x * x * y + c.k.y * x + c.k.z * y + c.k.w; break;
    }
    color = stored(v);
}
