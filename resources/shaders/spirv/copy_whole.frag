#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// Every sample of a four-sample texture from the single-sample one, or a texture as it is.
layout(set = 2, binding = 0) uniform sampler2D frame;

void main()
{
    color = texelFetch(frame, pixel(), 0);
}
