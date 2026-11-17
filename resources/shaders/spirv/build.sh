#!/bin/sh
# Compiles every shader here to SPIR-V for Vulkan 1.1 and validates it.
set -e
cd "$(dirname "$0")"
for src in *.vert *.frag; do
    glslangValidator -V --target-env vulkan1.1 -o "$src.spv" "$src" > /dev/null
    spirv-val --target-env vulkan1.1 "$src.spv"
done
echo "compiled $(ls *.spv | wc -l | tr -d ' ') shaders"
