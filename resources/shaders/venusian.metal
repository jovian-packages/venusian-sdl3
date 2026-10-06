#include <metal_stdlib>
using namespace metal;

struct PointIn { float2 point [[attribute(0)]]; };
struct VOut { float4 position [[position]]; float2 at; };
struct Frame { float4 size; };
struct Paint { uint4 mode_rgba[2]; float4 shape; float4 band; float4 abcd; float4 efwh; };

constant uint SOLID = 0;
constant uint FILL = 1;
constant uint OVAL = 2;
constant uint IMAGE = 3;
constant uint COPY = 4;

vertex VOut place(PointIn in [[stage_in]], constant Frame &frame [[buffer(0)]])
{
    VOut out;
    out.position = float4(in.point.x / frame.size.x * 2.0 - 1.0, 1.0 - in.point.y / frame.size.y * 2.0, 0.0, 1.0);
    out.at = in.point;
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

fragment float4 paint(VOut in [[stage_in]], constant Paint &p [[buffer(0)]], texture2d<float> source [[texture(0)]], sampler s0 [[sampler(0)]])
{
    uint mode = p.mode_rgba[0].x;
    float4 rgba = float4(p.mode_rgba[1]) / 255.0;
    float2 centre = floor(in.at) + 0.5;

    if (mode == SOLID || mode == FILL) {
        return rgba;
    }
    if (mode == COPY) {
        return source.read(uint2(centre));
    }
    if (mode == OVAL) {
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
        return float4(rgba.rgb, shaped(p.mode_rgba[1].w, coverage));
    }

    float u = p.abcd.x * centre.x + p.abcd.z * centre.y + p.efwh.x;
    float v = p.abcd.y * centre.x + p.abcd.w * centre.y + p.efwh.y;
    float w = p.efwh.z;
    float h = p.efwh.w;
    if (!(u >= 0.0 && u < w && v >= 0.0 && v < h)) {
        discard_fragment();
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
    return float4(float3(s.xyz) / 255.0, float(a) / 255.0);
}
