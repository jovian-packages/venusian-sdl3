#version 450
#extension GL_GOOGLE_include_directive : require
#include "draw.glsl"

// The stencil pass: nothing is written to colour (the pipeline's write mask is none).
void main()
{
    color = vec4(0.0);
}
