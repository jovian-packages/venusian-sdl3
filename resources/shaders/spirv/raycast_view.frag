#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// Where the view is on the surface and its size; the ceiling and floor 0xRRGGBBAA; the shade; rays a pair of rows of
// the hits texture; 1 to write straight colour (a view read out as bytes), 0 premultiplied (painted on a framebuffer).
// Walls: one texel a wall id, x the kind (0 none, 1 a colour, 2 a texture), y the colour, z where its texture is in
// the atlas (x | y << 16), w its size (width | height << 16). The atlas holds textures as their straight bytes.
layout(set = 2, binding = 0) uniform sampler2D hits;
layout(set = 2, binding = 1) uniform usampler2D walls;
layout(set = 2, binding = 2) uniform usampler2D atlas;
layout(set = 3, binding = 0) uniform View { ivec2 origin; ivec2 size; uint ceiling; uint floorColor; uint shade; uint perRow; uint straightOut; uint pad0; uint pad1; uint pad2; } v;

// Velvet's tallest wall line, before it is cut to the view.
const float MAX_LINE = 16777216.0;

uint dimmed(uint rgba, uint dim)
{
    uint r = ((rgba >> 24) & 0xFFu) * dim / 255u;
    uint g = ((rgba >> 16) & 0xFFu) * dim / 255u;
    uint b = ((rgba >> 8) & 0xFFu) * dim / 255u;

    return (r << 24) | (g << 16) | (b << 8) | (rgba & 0xFFu);
}

// a * b / c in 64 bits, as Velvet works it out, without 64-bit integers in the shader: a row runs to a wall's whole
// line, a texture is up to 65535 tall. The quotient is below the texture's height, so it fits 32 bits.
uint mulDiv(uint a, uint b, uint c)
{
    uint high;
    uint low;
    umulExtended(a, b, high, low);
    if (high == 0u) {
        return low / c;
    }

    uint quotient = 0u;
    uint remainder = 0u;
    for (int i = 63; i >= 0; i--) {
        uint bit = i >= 32 ? (high >> uint(i - 32)) & 1u : (low >> uint(i)) & 1u;
        remainder = (remainder << 1) | bit;
        quotient <<= 1;
        if (remainder >= c) {
            remainder -= c;
            quotient |= 1u;
        }
    }

    return quotient;
}

void main()
{
    ivec2 p = ivec2(gl_FragCoord.xy) - v.origin;
    int column = p.x;
    int row = p.y;
    int height = v.size.y;
    int horizon = height / 2;

    uint rgba = row < horizon ? v.ceiling : v.floorColor;

    ivec2 place = ivec2(uint(column) % v.perRow, (uint(column) / v.perRow) * 2u);
    vec4 first = texelFetch(hits, place, 0);
    vec4 second = texelFetch(hits, place + ivec2(0, 1), 0);
    uint wall = uint(second.x);

    if (wall != 0u) {
        float distance = first.x;
        float tall = distance > 0.0 ? float(height) / distance : MAX_LINE;
        if (!(tall < MAX_LINE)) {
            tall = MAX_LINE;
        }
        int line = int(tall);
        int start = horizon - line / 2;
        int from = max(start, 0);
        int to = min(start + line, height);

        if (row >= from && row < to) {
            uint face = uint(second.y);
            uint dim = face >= 2u ? v.shade : 255u;
            uvec4 entry = texelFetch(walls, ivec2(int(wall), 0), 0);

            if (entry.x == 1u) {
                rgba = dimmed(entry.y, dim);
            } else if (entry.x == 2u) {
                int textureWidth = int(entry.w & 0xFFFFu);
                int textureHeight = int(entry.w >> 16);
                int texelX = int(first.y * float(textureWidth));
                if (texelX > textureWidth - 1) {
                    texelX = textureWidth - 1;
                }
                if (face == 1u || face == 2u) {
                    texelX = textureWidth - 1 - texelX;
                }
                int texelY = int(mulDiv(uint(row - start), uint(textureHeight), uint(line)));
                uvec4 texel = texelFetch(atlas, ivec2(int(entry.z & 0xFFFFu), int(entry.z >> 16)) + ivec2(texelX, texelY), 0);
                rgba = dimmed((texel.r << 24) | (texel.g << 16) | (texel.b << 8) | texel.a, dim);
            }
        }
    }

    vec4 c = vec4(float((rgba >> 24) & 0xFFu), float((rgba >> 16) & 0xFFu), float((rgba >> 8) & 0xFFu), float(rgba & 0xFFu)) / 255.0;

    color = v.straightOut != 0u ? c : vec4(c.rgb * c.a, c.a);
}
