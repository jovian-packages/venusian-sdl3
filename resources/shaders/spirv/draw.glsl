// Shared by the painter's fragments (after place.vert). `at` is the point in surface pixels, read once a sample.
// Fragments return premultiplied colour for the pipeline to blend: source + destination x (1 - source alpha).

layout(location = 0) noperspective sample in vec2 at;
layout(location = 0) out vec4 color;

// A colour's bytes, straight, as premultiplied colour at alpha x cover.
vec4 premultiplied(uvec4 rgba, float cover)
{
    float a = float(rgba.a) / 255.0 * cover;
    return vec4(vec3(rgba.rgb) / 255.0 * a, a);
}

// Where line y crosses a rounded box: [ends.x, ends.y), empty when ends.y <= ends.x.
float corner(float cx, float cy, float r, float y, float side)
{
    float t = (y - cy) / r;
    float h = r * sqrt(max(0.0, 1.0 - t * t));
    return side < 0.0 ? cx - h : cx + h;
}

vec2 across(vec4 box, vec4 r, float y)
{
    if (y < box.y || y >= box.w) {
        return vec2(0.0, 0.0);
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
    return vec2(left, right);
}

bool inside(vec2 ends, float x)
{
    return ends.y > ends.x && x >= ends.x && x < ends.y;
}
