#version 450
#extension GL_GOOGLE_include_directive : require
#include "draw.glsl"

// A layer laid down: its own texture (premultiplied colour, starting at place.xy of the surface) at the pixel, inside
// the rounded box, at the layer's opacity (place.z, 0..1).
layout(set = 2, binding = 0) uniform sampler2D source;
layout(set = 3, binding = 0) uniform Sheet { vec4 box; vec4 radii; vec4 place; } s;

void main()
{
    if (!inside(across(s.box, s.radii, at.y), at.x)) {
        discard;
    }
    vec2 local = floor(at) - s.place.xy;
    ivec2 size = textureSize(source, 0);
    if (local.x < 0.0 || local.y < 0.0 || local.x >= float(size.x) || local.y >= float(size.y)) {
        discard;
    }
    color = texelFetch(source, ivec2(local), 0) * s.place.z;
}
