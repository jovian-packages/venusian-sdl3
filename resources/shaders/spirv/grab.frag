#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// A region of the target, unpremultiplied when asked: what Velvet's glass and backdrop read. Past the source, transparent.
layout(set = 2, binding = 0) uniform sampler2D src;
layout(set = 3, binding = 0) uniform Grab { ivec2 offset; ivec2 size; uint straightOut; uint pad0; uint pad1; uint pad2; } g;

void main()
{
    ivec2 p = pixel() + g.offset;
    if (p.x < 0 || p.y < 0 || p.x >= g.size.x || p.y >= g.size.y) {
        color = vec4(0.0);
        return;
    }
    uvec4 c = byte4(texelFetch(src, p, 0));
    if (g.straightOut != 0u) {
        c = uvec4(straightOf(c.r, c.a), straightOf(c.g, c.a), straightOf(c.b, c.a), c.a);
    }
    color = unbyte(c);
}
