# src/Shaders — the painter on SDL_GPU

MSL for the Metal backend (`painter.msl.php`, compiled by SDL at device boot). GLSL 450 → SPIR-V for every other backend (Vulkan on the Pi). The `.spv` are **committed**: the Pi has no shader compiler. Regenerate by hand, on the Mac only.

## Binding layout (SDL_gpu.h, `SDL_CreateGPUShader`)

| Resource | MSL | SPIR-V |
|---|---|---|
| vertex uniform buffer 0 (projection `float4x4`) | `[[buffer(0)]]` | set 1, binding 0 |
| vertex buffer 0 (pitch 36) | SDL binds it at `[[buffer(14)]]`; read through `[[stage_in]]` | locations 0–2 |
| fragment texture + sampler 0 | `[[texture(0)]]`, `[[sampler(0)]]` | set 2, binding 0 |

Vertex attributes: location 0 `float3` position (offset 0), 1 `float4` colour (12), 2 `float2` uv (28). Point size 1.0 is written on both (`[[point_size]]`, `gl_PointSize`); a POINTLIST pipeline requires it. No y flip: SDL_GPU clip space is y-up (proven on Metal, and on Vulkan on the Pi's V3D (Mesa V3DV) through SDL_GPU's Vulkan backend).

## Commands (Mac)

```bash
glslangValidator -V src/Shaders/painter.vert -o src/Shaders/painter.vert.spv
glslangValidator -V src/Shaders/painter.frag -o src/Shaders/painter.frag.spv
shasum -a 256 src/Shaders/*.spv
```

`/opt/homebrew/bin/glslangValidator --version` (Homebrew `glslang`):

```
Glslang Version: 11:16.5.0
ESSL Version: OpenGL ES GLSL 3.20 glslang Khronos. 16.5.0
GLSL Version: 4.60 glslang Khronos. 16.5.0
SPIR-V Version 0x00010600, Revision 1
GLSL.std.450 Version 100, Revision 1
Khronos Tool ID 8
SPIR-V Generator Version 11
GL_KHR_vulkan_glsl version 100
ARB_GL_gl_spirv version 100
```

## Committed output

| File | Bytes | sha256 |
|---|---|---|
| `painter.vert.spv` | 1524 | `9ff0336ec93301381f406097554b22266957a14bf0e93461e1a658c5621e2df5` |
| `painter.frag.spv` | 664 | `f907c016d28696989168627190495002d1a57dc1a2b2bd0c0c5b0fbadf34a4a3` |
