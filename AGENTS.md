# AGENTS.md

1. Bindings are ext-sdl3's, 1:1. This package holds the engine, never SDL calls of its own beyond SDL_GPU through ext-sdl3.
2. Every shader change keeps Velvet's arithmetic, in both the GLSL and the MSL. A `.vert` / `.frag` change is recompiled with `glslangValidator -V -g` (in `resources/shaders`) and committed with its `.spv`. See [.okf/architecture/engine.md](.okf/architecture/engine.md).
3. The parity suite is the gate, on the Mac and the Pi: `tests/ParityTest.php` through Surface's `GpuParity`.
4. No toolkit code here. Window lending lives in the toolkit packages.
5. Tests run on the GPU, never skipped; on the Pi, inside the Wayland session.
6. Publish prep is part of done: README examples run, `.okf` validated.
