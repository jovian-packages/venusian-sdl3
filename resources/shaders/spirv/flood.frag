#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

layout(set = 3, binding = 0) uniform Flood { uvec4 pixel; } f;

void main()
{
    color = unbyte(f.pixel);
}
