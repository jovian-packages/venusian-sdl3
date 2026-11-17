#version 450
#extension GL_GOOGLE_include_directive : require
#include "draw.glsl"

// An image: another framebuffer's texture (premultiplied colour) under the inverse placement of the pixel's centre,
// nearest or a blend of the four texels around the point. abcd and efwh: the inverse placement and the source's size;
// options: opacity 0..255, linear (1) or nearest (0); the tint's bytes; the window u0 v0 u1 v1 (style.y = 1); the
// rounded box and its radii tl tr br bl (style.x = 1).
layout(set = 2, binding = 0) uniform sampler2D source;
layout(set = 3, binding = 0) uniform Picture { vec4 abcd; vec4 efwh; uvec4 options; uvec4 tint; vec4 win; vec4 box; vec4 radii; uvec4 style; } p;

void main()
{
    vec2 centre = floor(at) + 0.5;
    float u = p.abcd.x * centre.x + p.abcd.z * centre.y + p.efwh.x;
    float v = p.abcd.y * centre.x + p.abcd.w * centre.y + p.efwh.y;
    float w = p.efwh.z;
    float h = p.efwh.w;
    if (!(u >= 0.0 && u < w && v >= 0.0 && v < h)) {
        discard;
    }
    // the window crops; the texels beside it are still read by the smooth filter
    if (p.style.y != 0u && !(u >= p.win.x && u < p.win.z && v >= p.win.y && v < p.win.w)) {
        discard;
    }
    // rounded corners: a sample is in when it lies inside the rounded box
    if (p.style.x != 0u && !inside(across(p.box, p.radii, at.y), at.x)) {
        discard;
    }

    vec4 c;
    if (p.options.y == 0u) {
        c = texelFetch(source, ivec2(int(floor(u)), int(floor(v))), 0);
    } else {
        vec2 q = vec2(u, v) - 0.5;
        vec2 o = floor(q);
        vec2 t = q - o;
        vec2 last = vec2(w, h) - 1.0;
        ivec2 a = ivec2(clamp(o, vec2(0.0), last));
        ivec2 b = ivec2(clamp(o + 1.0, vec2(0.0), last));
        c = mix(mix(texelFetch(source, a, 0), texelFetch(source, ivec2(b.x, a.y), 0), t.x), mix(texelFetch(source, ivec2(a.x, b.y), 0), texelFetch(source, b, 0), t.x), t.y);
    }
    // the tint multiplies the straight colour and the alpha; then the opacity
    vec4 tint = vec4(p.tint) / 255.0;
    float k = tint.a * float(p.options.x) / 255.0;
    color = vec4(c.rgb * tint.rgb * k, c.a * k);
}
