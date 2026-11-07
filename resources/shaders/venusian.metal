#include <metal_stdlib>
using namespace metal;

struct PointIn { float2 point [[attribute(0)]]; float2 texel [[attribute(1)]]; };
struct VOut { float4 position [[position]]; float2 at; float2 atlas_at; };
struct Frame { float4 size; };
struct Paint { uint4 mode_rgba[2]; float4 shape; float4 band; float4 abcd; float4 efwh; float4 radii; };

constant uint SOLID = 0;
constant uint FILL = 1;
constant uint OVAL = 2;
constant uint IMAGE = 3;
constant uint COPY = 4;
constant uint ROUND = 5;
constant uint SHADE = 6;
constant uint GLYPH = 7;

vertex VOut place(PointIn in [[stage_in]], constant Frame &frame [[buffer(0)]])
{
    VOut out;
    out.position = float4(in.point.x / frame.size.x * 2.0 - 1.0, 1.0 - in.point.y / frame.size.y * 2.0, 0.0, 1.0);
    out.at = in.point;
    out.atlas_at = in.texel;
    return out;
}

static float shaped(uint alpha, float coverage)
{
    uint c = uint(round(clamp(coverage, 0.0, 1.0) * 255.0));
    return float((alpha * c + 127) / 255) / 255.0;
}

static float ellipse(float2 p, float2 centre, float2 radii, bool hard)
{
    float2 q = (p - centre) / radii;
    float d = (length(q) - 1.0) * min(radii.x, radii.y);
    return hard ? (d <= 0.0 ? 1.0 : 0.0) : clamp(0.5 - d, 0.0, 1.0);
}

// As venusian.frag: Rasterize's roundAcross(), and a rounded box's coverage at a pixel.
static float corner(float cx, float cy, float r, float y, float side)
{
    float t = (y - cy) / r;
    float h = r * sqrt(max(0.0, 1.0 - t * t));
    return side < 0.0 ? cx - h : cx + h;
}

static float2 across(float4 box, float4 r, float y)
{
    if (y < box.y || y >= box.w) {
        return float2(0.0);
    }
    float left = box.x;
    if (y < box.y + r.x) {
        left = corner(box.x + r.x, box.y + r.x, r.x, y, -1.0);
    } else if (y > box.w - r.w) {
        left = corner(box.x + r.w, box.w - r.w, r.w, y, -1.0);
    }
    float right = box.z;
    if (y < box.y + r.y) {
        right = corner(box.z - r.y, box.y + r.y, r.y, y, 1.0);
    } else if (y > box.w - r.z) {
        right = corner(box.z - r.z, box.w - r.z, r.z, y, 1.0);
    }
    return float2(left, right);
}

static float roundbox(float2 p, float4 box, float4 r, bool hard)
{
    if (hard) {
        float2 ends = across(box, r, p.y);
        return ends.y > ends.x && p.x >= ends.x && p.x < ends.y ? 1.0 : 0.0;
    }
    float2 c = (box.xy + box.zw) / 2.0;
    float2 h = (box.zw - box.xy) / 2.0;
    float k = p.x < c.x ? (p.y < c.y ? r.x : r.w) : (p.y < c.y ? r.y : r.z);
    float2 q = abs(p - c) - h + k;
    float d = min(max(q.x, q.y), 0.0) + length(max(q, float2(0.0))) - k;
    return clamp(0.5 - d, 0.0, 1.0);
}

static float3 linear_light(float3 c)
{
    return select(pow((c + 0.055) / 1.055, 2.4), c / 12.92, c <= 0.04045);
}

// As venusian.frag: flags bit 1 an HDR target (sRGB decoded to linear light,
// SDR white 1.0), bit 2 a half-float source (linear already).
fragment float4 paint(VOut in [[stage_in]], constant Paint &p [[buffer(0)]], texture2d<float> source [[texture(0)]], sampler s0 [[sampler(0)]])
{
    uint mode = p.mode_rgba[0].x;
    bool hdr = (p.mode_rgba[0].w & 1u) != 0u;
    float4 rgba = float4(p.mode_rgba[1]) / 255.0;
    float2 centre = floor(in.at) + 0.5;
    float4 c;

    if (mode == COPY) {
        return source.read(uint2(centre));
    }
    if (mode == SOLID || mode == FILL) {
        c = rgba;
    } else if (mode == ROUND) {
        bool hard = p.band.z < 2.0;
        float coverage = roundbox(centre, p.shape, p.radii, hard);
        if (p.band.y > 0.5) {
            float inset = p.band.x;
            float4 hole = float4(p.shape.xy + inset, p.shape.zw - inset);
            if (hole.z > hole.x && hole.w > hole.y) {
                coverage -= roundbox(centre, hole, max(p.radii - inset, float4(0.0)), hard);
            }
        }
        if (coverage <= 0.0) {
            discard_fragment();
        }
        c = float4(rgba.rgb, shaped(p.mode_rgba[1].w, coverage));
    } else if (mode == SHADE) {
        float2 bc = (p.shape.xy + p.shape.zw) / 2.0;
        float2 bh = (p.shape.zw - p.shape.xy) / 2.0;
        float r = p.band.y;
        float blur = p.band.x;
        float2 q = abs(centre - bc) - bh + r;
        float2 o = max(q, float2(0.0));
        float d = min(max(q.x, q.y), 0.0) + sqrt(o.x * o.x + o.y * o.y) - r;
        float cover;
        if (blur > 0.0) {
            float t = clamp((d + blur) / (2.0 * blur), 0.0, 1.0);
            cover = 1.0 - t * t * (3.0 - 2.0 * t);
        } else {
            cover = d < 0.0 ? 1.0 : 0.0;
        }
        uint v = uint(floor(cover * 255.0 + 0.5));
        if (p.band.z < 2.0) {
            v = v >= 128u ? 255u : 0u;
        }
        uint a = (p.mode_rgba[1].w * v + 127u) / 255u;
        if (a == 0u) {
            discard_fragment();
        }
        c = float4(rgba.rgb, float(a) / 255.0);
    } else if (mode == GLYPH) {
        uint cv = uint(round(source.read(uint2(floor(in.atlas_at))).r * 255.0));
        uint a = (p.mode_rgba[1].w * cv + 127u) / 255u;
        if (a == 0u) {
            discard_fragment();
        }
        c = float4(rgba.rgb, float(a) / 255.0);
    } else if (mode == OVAL) {
        bool hard = p.band.z < 2.0;
        float half_stroke = p.band.x / 2.0;
        float coverage = ellipse(centre, p.shape.xy, p.shape.zw + half_stroke, hard);
        if (p.band.y > 0.5) {
            float2 inner = p.shape.zw - half_stroke;
            if (inner.x > 0.0 && inner.y > 0.0) {
                coverage -= ellipse(centre, p.shape.xy, inner, hard);
            }
        }
        if (coverage <= 0.0) {
            discard_fragment();
        }
        c = float4(rgba.rgb, shaped(p.mode_rgba[1].w, coverage));
    } else {
        float u = p.abcd.x * centre.x + p.abcd.z * centre.y + p.efwh.x;
        float v = p.abcd.y * centre.x + p.abcd.w * centre.y + p.efwh.y;
        float w = p.efwh.z;
        float h = p.efwh.w;
        if (!(u >= 0.0 && u < w && v >= 0.0 && v < h)) {
            discard_fragment();
        }
        if (hdr) {
            float4 f;
            if (p.mode_rgba[0].z == 0) {
                f = source.read(uint2(uint(floor(u)), uint(floor(v))));
            } else {
                float2 at = float2(u, v) - 0.5;
                float2 o = floor(at);
                float2 t = at - o;
                float2 last = float2(w, h) - 1.0;
                uint2 lo = uint2(clamp(o, float2(0.0), last));
                uint2 hi = uint2(clamp(o + 1.0, float2(0.0), last));
                float4 p00 = source.read(lo);
                float4 p10 = source.read(uint2(hi.x, lo.y));
                float4 p01 = source.read(uint2(lo.x, hi.y));
                float4 p11 = source.read(hi);
                float4 m = mix(mix(float4(p00.rgb * p00.a, p00.a), float4(p10.rgb * p10.a, p10.a), t.x),
                               mix(float4(p01.rgb * p01.a, p01.a), float4(p11.rgb * p11.a, p11.a), t.x), t.y);
                if (m.a <= 0.0) {
                    discard_fragment();
                }
                f = float4(m.rgb / m.a, m.a);
            }
            float3 light = (p.mode_rgba[0].w & 2u) != 0u ? f.rgb : linear_light(f.rgb);
            return float4(light, f.a * float(p.mode_rgba[0].y) / 255.0);
        }
        uint4 s;
        if (p.mode_rgba[0].z == 0) {
            s = uint4(round(source.read(uint2(uint(floor(u)), uint(floor(v)))) * 255.0));
        } else {
            float fx = u - 0.5;
            float x0 = floor(fx);
            uint tx = uint(floor((fx - x0) * 256.0));
            float fy = v - 0.5;
            float y0 = floor(fy);
            uint ty = uint(floor((fy - y0) * 256.0));
            int last_x = int(w) - 1;
            int last_y = int(h) - 1;
            uint xa = uint(clamp(int(x0), 0, last_x));
            uint xb = uint(clamp(int(x0) + 1, 0, last_x));
            uint ya = uint(clamp(int(y0), 0, last_y));
            uint yb = uint(clamp(int(y0) + 1, 0, last_y));
            uint weights[4] = { (256 - tx) * (256 - ty), tx * (256 - ty), (256 - tx) * ty, tx * ty };
            uint2 taps[4] = { uint2(xa, ya), uint2(xb, ya), uint2(xa, yb), uint2(xb, yb) };
            uint sum = 0;
            uint3 rgb = uint3(0);
            for (int i = 0; i < 4; i++) {
                uint4 t = uint4(round(source.read(taps[i]) * 255.0));
                uint weighed = weights[i] * t.w;
                sum += weighed;
                rgb += weighed * t.xyz;
            }
            if (sum == 0) {
                discard_fragment();
            }
            uint half_sum = sum / 2;
            s = uint4((rgb + half_sum) / sum, (sum + 32768) >> 16);
        }
        uint a = (s.w * p.mode_rgba[0].y + 127) / 255;
        c = float4(float3(s.xyz) / 255.0, float(a) / 255.0);
    }
    if (hdr) {
        c.rgb = linear_light(c.rgb);
    }
    return c;
}

// The present, as show.vert / show.frag.
struct ShowOut { float4 position [[position]]; };
struct Show { float4 rect; float4 size_light; uint4 modes; };

vertex ShowOut show_place(uint vid [[vertex_id]])
{
    float2 p = float2(float((vid << 1) & 2), float(vid & 2));
    ShowOut out;
    out.position = float4(p * 2.0 - 1.0, 0.0, 1.0);
    return out;
}

static float3 show_pq(float3 nits)
{
    const float3x3 to2020 = float3x3(float3(0.6274040, 0.0690970, 0.0163916), float3(0.3292820, 0.9195400, 0.0880132), float3(0.0433136, 0.0113612, 0.8955950));
    float3 y = clamp(to2020 * nits / 10000.0, 0.0, 1.0);
    float3 p = pow(y, 0.1593017578125);
    return pow((0.8359375 + 18.8515625 * p) / (1.0 + 18.6875 * p), 78.84375);
}

fragment float4 show(ShowOut in [[stage_in]], constant Show &s [[buffer(0)]], texture2d<float> target [[texture(0)]], sampler s0 [[sampler(0)]])
{
    float2 at = in.position.xy;
    if (at.x < s.rect.x || at.y < s.rect.y || at.x >= s.rect.x + s.rect.z || at.y >= s.rect.y + s.rect.w) {
        return float4(0.0, 0.0, 0.0, 1.0);
    }
    float2 size = s.size_light.xy;
    float2 p = (at - s.rect.xy) / s.rect.zw * size;
    float2 last = size - 1.0;
    float4 c;
    if (s.modes.x == 0) {
        c = target.read(uint2(clamp(floor(p), float2(0.0), last)));
    } else {
        float2 f = p - 0.5;
        float2 o = floor(f);
        float2 t = f - o;
        uint2 a = uint2(clamp(o, float2(0.0), last));
        uint2 b = uint2(clamp(o + 1.0, float2(0.0), last));
        c = mix(mix(target.read(a), target.read(uint2(b.x, a.y)), t.x), mix(target.read(uint2(a.x, b.y)), target.read(b), t.x), t.y);
    }
    if (s.modes.y == 1) {
        c.rgb *= s.size_light.z;
    } else if (s.modes.y == 2) {
        c = float4(show_pq(c.rgb * s.size_light.w), 1.0);
    }
    return c;
}
