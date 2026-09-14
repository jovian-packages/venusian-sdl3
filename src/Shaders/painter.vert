#version 450

layout(location = 0) in vec3 aPos;
layout(location = 1) in vec4 aColor;
layout(location = 2) in vec2 aUV;

layout(set = 1, binding = 0) uniform Projection {
    mat4 projection;
} u;

layout(location = 0) out vec4 v_color;
layout(location = 1) out vec2 v_uv;

void main() {
    gl_Position = u.projection * vec4(aPos.xy, 0.0, 1.0);
    gl_PointSize = 1.0;
    v_color = aColor;
    v_uv = aUV;
}
