#version 450
#extension GL_GOOGLE_include_directive : require
#include "draw.glsl"

// A glass pane, PHPLandGlass::pane() at the pixel's centre: the blurred backdrop (a pyramid level, straight colour,
// `scale` times smaller, starting at origin) bent near the edge, mixed with the sharp one by the frost, then
// saturation, tint, light, rim and grain; covered by 0.5 - d of the rounded box, at the pane's alpha. Straight in,
// premultiplied out. look: frost, refraction, bevel, saturation; look2: light, grain, alpha (0..255), scale
layout(set = 2, binding = 0) uniform sampler2D blurred;
layout(set = 2, binding = 1) uniform sampler2D sharp;
layout(set = 3, binding = 0) uniform Pane { vec4 box; vec4 radii; vec4 look; vec4 look2; uvec4 tint; uvec4 rim; vec2 origin; uint hasSharp; uint pad; ivec2 blurSize; ivec2 sharpSize; } g;

float levelSample(sampler2D level, ivec2 size, float u, float v, int k)
{
    float fu = floor(u);
    float fv = floor(v);
    float tx = u - fu;
    float ty = v - fv;
    int x0 = clamp(int(fu), 0, size.x - 1);
    int x1 = clamp(int(fu) + 1, 0, size.x - 1);
    int y0 = clamp(int(fv), 0, size.y - 1);
    int y1 = clamp(int(fv) + 1, 0, size.y - 1);
    float a = floor(texelFetch(level, ivec2(x0, y0), 0)[k] * 255.0 + 0.5);
    float b = floor(texelFetch(level, ivec2(x1, y0), 0)[k] * 255.0 + 0.5);
    float c = floor(texelFetch(level, ivec2(x0, y1), 0)[k] * 255.0 + 0.5);
    float d = floor(texelFetch(level, ivec2(x1, y1), 0)[k] * 255.0 + 0.5);
    float top = a + (b - a) * tx;
    float under = c + (d - c) * tx;
    return top + (under - top) * ty;
}

uint grainOf(int x, int y)
{
    uint h = uint(x) * 374761393u + uint(y) * 668265263u;
    h = (h ^ (h >> 13)) * 1274126177u;
    h ^= h >> 16;
    return h & 0xFFFFu;
}

void main()
{
    ivec2 pixel = ivec2(floor(at));
    float px = float(pixel.x) + 0.5;
    float py = float(pixel.y) + 0.5;
    float cx = (g.box.x + g.box.z) / 2.0;
    float cy = (g.box.y + g.box.w) / 2.0;
    float hx = (g.box.z - g.box.x) / 2.0;
    float hy = (g.box.w - g.box.y) / 2.0;
    float frost = g.look.x;
    float refraction = g.look.y;
    float bevel = g.look.z;
    float saturation = g.look.w;
    float light = g.look2.x;
    float grainAmount = g.look2.y;
    float alpha = g.look2.z;
    float scale = g.look2.w;
    float band = bevel > 0.0 ? bevel : (refraction > 0.0 ? refraction : 1.0);
    float r = px < cx ? (py < cy ? g.radii.x : g.radii.w) : (py < cy ? g.radii.y : g.radii.z);
    float dx = abs(px - cx) - (hx - r);
    float dy = abs(py - cy) - (hy - r);
    float d;
    float nx;
    float ny;
    if (dx > 0.0 && dy > 0.0) {
        float len = sqrt(dx * dx + dy * dy);
        d = len - r;
        nx = dx / len;
        ny = dy / len;
    } else if (dx > dy) {
        d = dx - r;
        nx = 1.0;
        ny = 0.0;
    } else {
        d = dy - r;
        nx = 0.0;
        ny = 1.0;
    }
    if (d >= 0.5) {
        discard;
    }
    if (px < cx) { nx = -nx; }
    if (py < cy) { ny = -ny; }
    float e = clamp(1.0 + d / band, 0.0, 1.0);
    float fe = e * e;
    float sx = px - nx * refraction * fe;
    float sy = py - ny * refraction * fe;
    float u = (sx - g.origin.x) / scale - 0.5;
    float v = (sy - g.origin.y) / scale - 0.5;
    vec3 c = vec3(levelSample(blurred, g.blurSize, u, v, 0), levelSample(blurred, g.blurSize, u, v, 1), levelSample(blurred, g.blurSize, u, v, 2));
    if (g.hasSharp != 0u && frost < 1.0) {
        float su = sx - g.origin.x - 0.5;
        float sv = sy - g.origin.y - 0.5;
        for (int k = 0; k < 3; k++) {
            float s = levelSample(sharp, g.sharpSize, su, sv, k);
            c[k] = s + (c[k] - s) * frost;
        }
    }
    float luma = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
    float lit = 0.5 + 0.5 * (nx * -0.6 + ny * -0.8);
    float grain = grainAmount * 0.04 * (float(grainOf(pixel.x, pixel.y)) / 65536.0 - 0.5) * 255.0;
    vec3 tint = vec3(g.tint.rgb);
    float tintA = float(g.tint.a) / 255.0;
    vec3 rim = vec3(g.rim.rgb);
    float rimA = float(g.rim.a) / 255.0;
    vec3 o;
    for (int k = 0; k < 3; k++) {
        float ck = luma + (c[k] - luma) * saturation;
        ck = ck + (tint[k] - ck) * tintA;
        ck = ck + light * 255.0 + rim[k] * rimA * fe * lit + grain;
        o[k] = clamp(floor(ck + 0.5), 0.0, 255.0);
    }
    float cover = min(0.5 - d, 1.0);
    float a = floor(cover * alpha + 0.5);
    if (a <= 0.0) {
        discard;
    }
    float k = a / 255.0;
    color = vec4(o / 255.0 * k, k);
}
