#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// Four lookup tables of 256 bytes, red, green, blue, alpha (an identity where the step left one out), on the straight
// colour; the alpha's table first, the colours premultiplied by the new alpha. The 1024 bytes are 64 uvec4s.
layout(set = 2, binding = 0) uniform sampler2D src;
layout(set = 3, binding = 0) uniform Tables { uvec4 words[64]; } t;

uint entry(uint i)
{
    return (t.words[i >> 4][(i >> 2) & 3u] >> (8u * (i & 3u))) & 0xFFu;
}

void main()
{
    uvec4 c = byte4(texelFetch(src, pixel(), 0));
    uint na = entry(768u + c.a);
    color = unbyte(uvec4(premultiplyOf(entry(straightOf(c.r, c.a)), na), premultiplyOf(entry(256u + straightOf(c.g, c.a)), na), premultiplyOf(entry(512u + straightOf(c.b, c.a)), na), na));
}
