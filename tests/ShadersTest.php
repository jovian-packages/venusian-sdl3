<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Sdl3Device;

it('ships SPIR-V compiled from the committed GLSL, and MSL', function (): void {
    foreach (['venusian.vert', 'venusian.frag', 'venusian.vert.spv', 'venusian.frag.spv', 'venusian.metal'] as $file) {
        expect(is_file(Sdl3Device::SHADERS.'/'.$file))->toBeTrue($file);
    }
    expect(substr(file_get_contents(Sdl3Device::SHADERS.'/venusian.vert.spv'), 0, 4))->toBe("\x03\x02\x23\x07")
        ->and(substr(file_get_contents(Sdl3Device::SHADERS.'/venusian.frag.spv'), 0, 4))->toBe("\x03\x02\x23\x07");

    // Compiled with -g, each module carries its GLSL source: a stale .spv no longer holds the committed text.
    foreach (['vert', 'frag'] as $stage) {
        expect(str_contains(file_get_contents(Sdl3Device::SHADERS."/venusian.{$stage}.spv"), file_get_contents(Sdl3Device::SHADERS."/venusian.{$stage}")))
            ->toBeTrue("venusian.{$stage}.spv is not compiled from venusian.{$stage}: glslangValidator -V -g venusian.{$stage} -o venusian.{$stage}.spv");
    }
});

it('compiles on this device in the format it takes', function (): void {
    video();
    $device = new Sdl3Device;

    expect($device->shader(SDL_GPU_SHADERSTAGE_VERTEX))->toBeInstanceOf(SDL_GPUShader::class)
        ->and($device->shader(SDL_GPU_SHADERSTAGE_FRAGMENT))->toBeInstanceOf(SDL_GPUShader::class);
});

it('ships the show pass as SPIR-V compiled from its GLSL, and as MSL', function (): void {
    foreach (['vert', 'frag'] as $stage) {
        $spv = file_get_contents(Sdl3Device::SHADERS."/show.{$stage}.spv");
        expect(substr($spv, 0, 4))->toBe("\x03\x02\x23\x07")
            ->and(str_contains($spv, file_get_contents(Sdl3Device::SHADERS."/show.{$stage}")))->toBeTrue("show.{$stage}.spv is not compiled from show.{$stage}: glslangValidator -V -g show.{$stage} -o show.{$stage}.spv");
    }
    expect(file_get_contents(Sdl3Device::SHADERS.'/venusian.metal'))->toContain('vertex ShowOut show_place(')->toContain('fragment float4 show(');
});

it('compiles the show pass on this device in the format it takes', function (): void {
    video();
    $device = new Sdl3Device;

    expect($device->showShader(SDL_GPU_SHADERSTAGE_VERTEX))->toBeInstanceOf(SDL_GPUShader::class)
        ->and($device->showShader(SDL_GPU_SHADERSTAGE_FRAGMENT))->toBeInstanceOf(SDL_GPUShader::class);
});
