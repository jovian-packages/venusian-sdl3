# Security Policy

## Supported versions

Venusian is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the
next release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on this repository's
**Security** tab. If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, and steps to reproduce. Reports are read and
weighed for the release line in development; before 1.0 there is no response-time commitment.

## Security model

- The shaders are the package's own files, never user input. The SPIR-V is compiled from the committed GLSL with `glslangValidator -V -g` and carries that source text; the suite checks it, and a reviewer recompiles and diffs.
- A `LentSurface` handle is an address trusted to be an `SDL_Window` (ext-sdl3's `fromPointer` rule). It comes only from a toolkit package's canvas.
- Transfer buffers are sized by the region being read or written: `Sdl3Framebuffer` refuses a region outside the target and an upload whose bytes do not fill its region exactly, so the GPU never copies past either.
- Textures stop at 16384 × 16384; a larger target or image source is refused before SDL sees it.
