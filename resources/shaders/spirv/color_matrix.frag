#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// The 4 x 5 matrix row by row (20 floats, five vec4s), on the straight colour; premultiplied again by the new alpha.
layout(set = 2, binding = 0) uniform sampler2D src;
layout(set = 3, binding = 0) uniform Matrix { vec4 m[5]; } mx;

float at(int i)
{
    return mx.m[i >> 2][i & 3];
}

void main()
{
    uvec4 c = byte4(texelFetch(src, pixel(), 0));
    float r = float(straightOf(c.r, c.a)) / 255.0;
    float g = float(straightOf(c.g, c.a)) / 255.0;
    float b = float(straightOf(c.b, c.a)) / 255.0;
    float a = float(c.a) / 255.0;
    float nr = clamp(at(0) * r + at(1) * g + at(2) * b + at(3) * a + at(4), 0.0, 1.0);
    float ng = clamp(at(5) * r + at(6) * g + at(7) * b + at(8) * a + at(9), 0.0, 1.0);
    float nb = clamp(at(10) * r + at(11) * g + at(12) * b + at(13) * a + at(14), 0.0, 1.0);
    float na = clamp(at(15) * r + at(16) * g + at(17) * b + at(18) * a + at(19), 0.0, 1.0);
    color = unbyte(uvec4(floor(nr * na * 255.0 + 0.5), floor(ng * na * 255.0 + 0.5), floor(nb * na * 255.0 + 0.5), floor(na * 255.0 + 0.5)));
}
