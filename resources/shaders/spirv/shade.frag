#version 450
#extension GL_GOOGLE_include_directive : require
#include "draw.glsl"

// SHADOW: the colour; the box; radii (unused); 0, 0, blur, shadow radius
layout(set = 3, binding = 0) uniform RoundBox { uvec4 rgba; vec4 box; vec4 radii; vec4 band; } b;

// Once a pixel, at its centre: 1 - smoothstep(-blur, blur, d), d the signed distance to the rounded box.
void main()
{
    vec2 p = floor(at) + 0.5;
    vec2 c = (b.box.xy + b.box.zw) / 2.0;
    vec2 h = (b.box.zw - b.box.xy) / 2.0;
    float r = b.band.w;
    float blur = b.band.z;
    vec2 q = abs(p - c) - h + r;
    vec2 o = max(q, vec2(0.0));
    float d = min(max(q.x, q.y), 0.0) + length(o) - r;
    float cover;
    if (blur > 0.0) {
        float t = clamp((d + blur) / (2.0 * blur), 0.0, 1.0);
        cover = 1.0 - t * t * (3.0 - 2.0 * t);
    } else {
        cover = d < 0.0 ? 1.0 : 0.0;
    }
    if (cover <= 0.0) {
        discard;
    }
    color = premultiplied(b.rgba, cover);
}
