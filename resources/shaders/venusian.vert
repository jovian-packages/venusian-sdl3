#version 450

// A vertex is a point in target pixels, origin top-left; `at` carries it to the
// fragment as the pixel centre under interpolation.
layout(location = 0) in vec2 point;
layout(location = 0) out vec2 at;
layout(set = 1, binding = 0) uniform Frame { vec4 size; };

void main()
{
    gl_Position = vec4(point.x / size.x * 2.0 - 1.0, 1.0 - point.y / size.y * 2.0, 0.0, 1.0);
    at = point;
}
