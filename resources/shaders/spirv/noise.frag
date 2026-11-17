#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// type 0 turbulence, 1 fractal, 2 hash. stitch: width, height, wrapX, wrapY of the first octave; stitched: 1 or 0.
// gradients: 514 x 4, a row a channel, each texel a gradient (x, y). The selector: 514 uint16s, eight to a uvec4.
layout(set = 2, binding = 0) uniform sampler2D gradients;
layout(set = 3, binding = 0) uniform Noise { ivec2 origin; int seed; uint type; vec2 frequency; uint octaves; uint stitched; ivec4 stitch; } n;
layout(set = 3, binding = 1) uniform Selector { uvec4 words[65]; } sel;

int selector(int i)
{
    uint word = sel.words[i >> 3][(i >> 1) & 3];
    return int((word >> (16u * uint(i & 1))) & 0xFFFFu);
}

uint hashOf(int x, int y, int seed)
{
    uint h = uint(x) * 374761393u + uint(y) * 668265263u + uint(seed) * 2246822519u;
    h = (h ^ (h >> 13)) * 1274126177u;
    return h ^ (h >> 16);
}

float noise2(int channel, float x, float y, bool stitched, ivec4 stitch)
{
    float t = x + 4096.0;
    int bx0 = int(t) & 0xFF;
    int bx1 = (bx0 + 1) & 0xFF;
    float rx0 = t - float(int(t));
    float rx1 = rx0 - 1.0;
    t = y + 4096.0;
    int by0 = int(t) & 0xFF;
    int by1 = (by0 + 1) & 0xFF;
    float ry0 = t - float(int(t));
    float ry1 = ry0 - 1.0;
    if (stitched) {
        if (bx0 >= stitch.z) { bx0 -= stitch.x; }
        if (bx1 >= stitch.z) { bx1 -= stitch.x; }
        if (by0 >= stitch.w) { by0 -= stitch.y; }
        if (by1 >= stitch.w) { by1 -= stitch.y; }
    }
    bx0 &= 0xFF;
    bx1 &= 0xFF;
    by0 &= 0xFF;
    by1 &= 0xFF;
    int i = selector(bx0);
    int j = selector(bx1);
    int b00 = selector(i + by0);
    int b10 = selector(j + by0);
    int b01 = selector(i + by1);
    int b11 = selector(j + by1);
    float sx = rx0 * rx0 * (3.0 - 2.0 * rx0);
    float sy = ry0 * ry0 * (3.0 - 2.0 * ry0);
    vec2 g00 = texelFetch(gradients, ivec2(b00, channel), 0).xy;
    vec2 g10 = texelFetch(gradients, ivec2(b10, channel), 0).xy;
    vec2 g01 = texelFetch(gradients, ivec2(b01, channel), 0).xy;
    vec2 g11 = texelFetch(gradients, ivec2(b11, channel), 0).xy;
    float u = rx0 * g00.x + ry0 * g00.y;
    float v = rx1 * g10.x + ry0 * g10.y;
    float a = u + sx * (v - u);
    u = rx0 * g01.x + ry1 * g01.y;
    v = rx1 * g11.x + ry1 * g11.y;
    float b = u + sx * (v - u);
    return a + sy * (b - a);
}

void main()
{
    ivec2 p = pixel() + n.origin;
    if (n.type == 2u) {
        uint h = hashOf(p.x, p.y, n.seed) & 0xFFu;
        color = unbyte(uvec4(h, h, h, 255u));
        return;
    }
    uvec4 s;
    for (int channel = 0; channel < 4; channel++) {
        float sum = 0.0;
        float vx = float(p.x) * n.frequency.x;
        float vy = float(p.y) * n.frequency.y;
        float ratio = 1.0;
        ivec4 stitch = n.stitch;
        for (uint octave = 0u; octave < n.octaves; octave++) {
            float v = noise2(channel, vx, vy, n.stitched != 0u, stitch);
            sum += (n.type == 1u ? v : abs(v)) / ratio;
            vx *= 2.0;
            vy *= 2.0;
            ratio *= 2.0;
            stitch = ivec4(stitch.x * 2, stitch.y * 2, 2 * stitch.z - 4096, 2 * stitch.w - 4096);
        }
        float value = n.type == 1u ? (sum * 255.0 + 255.0) / 2.0 : sum * 255.0;
        s[channel] = uint(clamp(floor(value + 0.5), 0.0, 255.0));
    }
    color = unbyte(uvec4(premultiplyOf(s.r, s.a), premultiplyOf(s.g, s.a), premultiplyOf(s.b, s.a), s.a));
}
