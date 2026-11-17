#version 450
#extension GL_GOOGLE_include_directive : require
#include "draw.glsl"

// ELLIPSE and RING: the colour; cx, cy, rx, ry; stroke, ring (1), 0, 0
layout(set = 3, binding = 0) uniform Oval { uvec4 rgba; vec4 shape; vec4 band; } oval;

// A sample is in when it lies inside the outer ellipse and, for a ring, outside the inner one.
void main()
{
    float halfStroke = oval.band.x / 2.0;
    vec2 q = (at - oval.shape.xy) / (oval.shape.zw + halfStroke);
    if (dot(q, q) > 1.0) {
        discard;
    }
    if (oval.band.y > 0.5) {
        vec2 inner = oval.shape.zw - halfStroke;
        if (inner.x > 0.0 && inner.y > 0.0) {
            vec2 r = (at - oval.shape.xy) / inner;
            if (dot(r, r) <= 1.0) {
                discard;
            }
        }
    }
    color = premultiplied(oval.rgba, 1.0);
}
