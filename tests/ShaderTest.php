<?php

declare(strict_types=1);

use Jovian\Venusian\Sdl3\Sdl3GpuContext;
use Jovian\Venusian\Sdl3\Shaders\Spirv;

it('ships an MSL painter on SDL_GPU\'s binding layout', function () {
    $msl = Sdl3GpuContext::mslSource();

    expect($msl)->toContain('vertex VertexOut painter_vertex')
        ->and($msl)->toContain('[[stage_in]]')
        ->and($msl)->toContain('[[buffer(0)]]')
        ->and($msl)->toContain('[[point_size]]')
        ->and($msl)->toContain('[[texture(0)]]');
});

it('ships SPIR-V that starts with the magic word', function () {
    foreach (['painter.vert.spv', 'painter.frag.spv'] as $name) {
        $bytes = Spirv::load($name);
        expect(unpack('V', $bytes)[1])->toBe(0x07230203)
            ->and(strlen($bytes) % 4)->toBe(0);
    }
});
