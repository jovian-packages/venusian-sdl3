#version 450

// The present: each swapchain pixel outside the placed rect is black; inside
// it, the target nearest or bilinear, then encoded for the swapchain:
// 0 as stored, 1 extended linear (× SDR white), 2 HDR10 (BT.2020, ST 2084,
// a value of 1.0 at `nits`).
layout(location = 0) out vec4 color;
layout(set = 2, binding = 0) uniform sampler2D target;
layout(set = 3, binding = 0) uniform Show {
    vec4 rect;         // x, y, width, height in swapchain pixels, origin top-left
    vec4 size_light;   // target width, height, SDR white, PQ white nits
    uvec4 modes;       // linear, transform, 0, 0
};

vec3 pq(vec3 nits)
{
    const mat3 to2020 = mat3(vec3(0.6274040, 0.0690970, 0.0163916), vec3(0.3292820, 0.9195400, 0.0880132), vec3(0.0433136, 0.0113612, 0.8955950));
    vec3 y = clamp(to2020 * nits / 10000.0, 0.0, 1.0);
    vec3 p = pow(y, vec3(0.1593017578125));
    return pow((0.8359375 + 18.8515625 * p) / (1.0 + 18.6875 * p), vec3(78.84375));
}

void main()
{
    vec2 at = gl_FragCoord.xy;
    if (at.x < rect.x || at.y < rect.y || at.x >= rect.x + rect.z || at.y >= rect.y + rect.w) {
        color = vec4(0.0, 0.0, 0.0, 1.0);
        return;
    }
    vec2 size = size_light.xy;
    vec2 p = (at - rect.xy) / rect.zw * size;
    vec2 last = size - 1.0;
    vec4 c;
    if (modes.x == 0u) {
        c = texelFetch(target, ivec2(clamp(floor(p), vec2(0.0), last)), 0);
    } else {
        vec2 f = p - 0.5;
        vec2 o = floor(f);
        vec2 t = f - o;
        ivec2 a = ivec2(clamp(o, vec2(0.0), last));
        ivec2 b = ivec2(clamp(o + 1.0, vec2(0.0), last));
        c = mix(mix(texelFetch(target, a, 0), texelFetch(target, ivec2(b.x, a.y), 0), t.x),
                mix(texelFetch(target, ivec2(a.x, b.y), 0), texelFetch(target, b, 0), t.x), t.y);
    }
    if (modes.y == 1u) {
        c.rgb *= size_light.z;
    } else if (modes.y == 2u) {
        c = vec4(pq(c.rgb * size_light.w), 1.0);
    }
    color = c;
}
