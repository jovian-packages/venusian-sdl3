#version 450
#extension GL_GOOGLE_include_directive : require
#include "draw.glsl"

layout(set = 3, binding = 0) uniform Paint { uvec4 rgba; } paint;

// The colour, where the stencil and the scissor let it through: a path's cover, spans, a clear.
void main()
{
    color = premultiplied(paint.rgba, 1.0);
}
