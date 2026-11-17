#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

// Velvet's grid walk (PHPLandMarcher, Amanatides and Woo), one ray a pixel. A ray starts from its cell (an integer)
// and where in that cell it is (a fraction), worked out by the CPU in double; a boundary's distance is worked out from
// how many boundaries were crossed, so float32 only holds small numbers.
//
// The eye's cell and where in it; the centre ray's direction and the camera plane (zero for one ray); how many rays;
// how many a pair of rows of the hits texture holds; bit 0: there is a reach, bit 1: the eye is inside the grid,
// bit 2: each ray's direction is its own, in the directions texture; the reach; the grid's size.
layout(set = 2, binding = 0) uniform usampler2D grid;
layout(set = 2, binding = 1) uniform sampler2D directions;
layout(set = 3, binding = 0) uniform Cast { ivec2 cell; vec2 frac; vec2 dir; vec2 plane; uint columns; uint perRow; uint flags; float reach; ivec2 gridSize; } c;

struct Walk { bool hit; float distance; float along; ivec2 cell; uint wall; uint face; };

Walk march(ivec2 size, ivec2 cell, vec2 f, vec2 d, bool reaches, float reach)
{
    Walk w = Walk(false, 0.0, 0.0, ivec2(0), 0u, 0u);

    bool moveX = d.x != 0.0;
    bool moveY = d.y != 0.0;
    if (!moveX && !moveY) {
        return w;
    }

    // how far along the ray one whole cell is, in x and in y
    float deltaX = moveX ? abs(1.0 / d.x) : 0.0;
    float deltaY = moveY ? abs(1.0 / d.y) : 0.0;
    int stepX = d.x < 0.0 ? -1 : 1;
    int stepY = d.y < 0.0 ? -1 : 1;

    // how far along the ray the first boundary is, in x and in y
    float firstX = moveX ? (d.x < 0.0 ? f.x : 1.0 - f.x) * deltaX : 0.0;
    float firstY = moveY ? (d.y < 0.0 ? f.y : 1.0 - f.y) * deltaY : 0.0;

    // a ray inside the grid crosses at most width + height boundaries before it leaves
    int crossedX = 0;
    int crossedY = 0;
    int limit = size.x + size.y + 2;
    for (int i = 0; i < limit; i++) {
        float nextX = fma(float(crossedX), deltaX, firstX);
        float nextY = fma(float(crossedY), deltaY, firstY);

        // a tie steps in y, as Velvet's does
        bool acrossX = moveX && (!moveY || nextX < nextY);
        float distance;
        if (acrossX) {
            distance = nextX;
            crossedX++;
            cell.x += stepX;
        } else {
            distance = nextY;
            crossedY++;
            cell.y += stepY;
        }

        if ((reaches && distance > reach) || cell.x < 0 || cell.y < 0 || cell.x >= size.x || cell.y >= size.y) {
            return w;
        }

        uint wall = texelFetch(grid, cell, 0).r;
        if (wall == 0u) {
            continue;
        }

        // where the ray meets the face, from the eye's cell: its fraction is the same as from the grid's origin
        float where = acrossX ? f.y + distance * d.y : f.x + distance * d.x;
        w.hit = true;
        w.distance = distance;
        w.along = where - floor(where);
        w.cell = cell;
        w.wall = wall;
        w.face = acrossX ? (stepX > 0 ? 0u : 1u) : (stepY > 0 ? 2u : 3u);

        return w;
    }

    return w;
}

// One ray a pixel: ray i is at x = i % per_row, rows 2(i / per_row) and the next. The first row holds distance, along,
// cell x, cell y; the second wall, face. A miss is all zero (wall 0).
void main()
{
    uvec2 p = uvec2(gl_FragCoord.xy);
    uint index = (p.y >> 1) * c.perRow + p.x;
    if (index >= c.columns || (c.flags & 2u) == 0u) {
        color = vec4(0.0);
        return;
    }

    vec2 d;
    if ((c.flags & 4u) != 0u) {
        d = texelFetch(directions, ivec2(index % c.perRow, index / c.perRow), 0).xy;
    } else {
        float across = float(2u * index + 1u) / float(c.columns) - 1.0;
        d = c.dir + c.plane * across;
    }
    Walk w = march(c.gridSize, c.cell, c.frac, d, (c.flags & 1u) != 0u, c.reach);
    if (!w.hit) {
        color = vec4(0.0);
        return;
    }

    color = (p.y & 1u) == 0u
        ? vec4(w.distance, w.along, float(w.cell.x), float(w.cell.y))
        : vec4(float(w.wall), float(w.face), 0.0, 0.0);
}
