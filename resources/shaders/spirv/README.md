# SPIR-V shaders (SDL_GPU on Vulkan)

The same entry points as `../*.metal`, in GLSL, laid out as SDL_GPU binds SPIR-V: a vertex stage's textures in set 0
and uniform buffers in set 1; a fragment stage's textures in set 2 and uniform buffers in set 3; no push constants.
Uniform blocks are std140 and hold the bytes the PHP packs for the MSL structs; arrays are vec4/uvec4 arrays, as std140
pads a scalar array to 16 bytes an element. Rounding is floor(x + 0.5): GLSL leaves round()'s halves to the driver.

Each `<entry>.vert` / `<entry>.frag` compiles to `<entry>.vert.spv` / `<entry>.frag.spv`:

    ./build.sh
