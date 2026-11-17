#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// Reading: premultiplied colour as straight colour.
layout(set = 2, binding = 0) uniform sampler2D frame;

void main()
{
    vec4 c = texelFetch(frame, pixel(), 0);
    color = c.a > 0.0 ? vec4(c.rgb / c.a, c.a) : vec4(0.0);
}
