#version 450
#extension GL_GOOGLE_include_directive : require
#include "draw.glsl"

// ROUNDRECT and ROUNDRING: the colour; the box x0 y0 x1 y1; radii tl tr br bl; inset, ring (1), 0, 0
layout(set = 3, binding = 0) uniform RoundBox { uvec4 rgba; vec4 box; vec4 radii; vec4 band; } b;

// A sample is in when it lies inside the rounded box and, for a ring, outside the box inset by band.x.
void main()
{
    if (!inside(across(b.box, b.radii, at.y), at.x)) {
        discard;
    }
    if (b.band.y > 0.5) {
        float inset = b.band.x;
        vec4 hole = vec4(b.box.xy + inset, b.box.zw - inset);
        if (hole.z > hole.x && hole.w > hole.y && inside(across(hole, max(b.radii - inset, vec4(0.0)), at.y), at.x)) {
            discard;
        }
    }
    color = premultiplied(b.rgba, 1.0);
}
