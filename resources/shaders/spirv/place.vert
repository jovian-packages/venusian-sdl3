#version 450

// Every vertex is a point in surface pixels, origin top-left. A texture that holds only part of the surface (a
// layer's) says where it starts: frame.origin. y is up in clip space, as SDL_GPU has it on every backend.
layout(location = 0) in vec2 point;
layout(set = 1, binding = 0) uniform Frame { vec2 size; vec2 origin; } frame;
layout(location = 0) noperspective out vec2 at;

void main()
{
    vec2 q = point - frame.origin;
    gl_Position = vec4(q.x / frame.size.x * 2.0 - 1.0, 1.0 - q.y / frame.size.y * 2.0, 0.0, 1.0);
    at = point;
}
