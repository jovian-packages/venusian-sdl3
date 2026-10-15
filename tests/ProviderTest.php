<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Sdl3Device;
use Jovian\Engines\Sdl3\Providers\VenusianSdl3ServiceProvider;
use Surface\Bridge\ToolkitManager;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\HumanInput\Key;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\HumanInput\HumanInputManager;
use Surface\NutsAndBolts\Color;

it('registers the sdl3 engine on the drawing manager', function (): void {
    video();
    $drawing = sdl3Drawing();

    VenusianSdl3ServiceProvider::extend($drawing);
    $engine = $drawing->renderer('sdl3', ['width' => 16, 'height' => 8, 'edges' => 'hard']);

    expect($drawing->engines())->toBe(['velvet', 'sdl3'])
        ->and($engine)->toBeInstanceOf(GpuRenderingEngine::class)
        ->and($engine->name())->toBe('sdl3')
        ->and($engine->device())->toBeInstanceOf(Sdl3Device::class)
        ->and([$engine->width(), $engine->height()])->toBe([16, 8]);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));
    expect(pixelOf($engine->framebuffer()->toRgba8(), 16, 0, 0))->toBe('ffffffff');
});

it('refuses the Velvet-only arguments by name', function (): void {
    video();
    $drawing = sdl3Drawing();
    VenusianSdl3ServiceProvider::extend($drawing);

    $drawing->renderer('sdl3', ['width' => 16, 'height' => 8, 'mode' => 'ring']);
})->throws(DrawingException::class, "sdl3 does not take 'mode'. It takes: output, width, height, edges, format, colorspace, frames_in_flight, resolution.");

it('brings SDL video up for the engine it builds', function (): void {
    SDL_Quit();
    $drawing = sdl3Drawing();
    VenusianSdl3ServiceProvider::extend($drawing);

    $engine = $drawing->renderer('sdl3', ['width' => 8, 'height' => 8]);

    // The creator's SDL_InitSubSystem is never quit: the tests after it draw on that video.
    expect($engine->name())->toBe('sdl3');
    $engine->release();
});

it('leaves the macOS application to AppKit when it brings SDL video up', function (): void {
    // With no NSApp, SDL makes its own: a Dock icon, its own menus, and itself as the delegate.
    $script = tempnam(sys_get_temp_dir(), 'sdl3').'.php';
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).';
        require '.var_export(__DIR__.'/Pest.php', true).';
        $drawing = sdl3Drawing();
        Jovian\Engines\Sdl3\Providers\VenusianSdl3ServiceProvider::extend($drawing);
        $drawing->renderer("sdl3", ["width" => 8, "height" => 8])->release();
        $app = NSApplication::sharedApplication();
        echo json_encode([$app->className(), is_null($app->mainMenu()), $app->delegate()?->className()]);');

    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($process);
    unlink($script);
    [$class, $no_menu, $delegate] = json_decode($out, true) ?? throw new RuntimeException("No answer: {$out} {$err}");

    expect($class)->toBe('NSApplication')
        ->and($no_menu)->toBeTrue()
        ->and($delegate)->not->toBeIn([null, 'SDL3AppDelegate']);
})->skip(fn () => PHP_OS_FAMILY !== 'Darwin' || ! class_exists(NSApplication::class), 'the macOS application is AppKit\'s only where ext-appkit is loaded');

it('registers the sdl3 input engine: HumanInput follows the SDL session and reads its keys', function (): void {
    $toolkits = new class extends ToolkitManager {
        public function __construct()
        {
            $this->drivers = ['sdl3' => stager()];
        }

        public function getDefaultDriver(): string
        {
            return 'sdl3';
        }
    };
    $input = new HumanInputManager($toolkits, inputFrame(), device_os_family(), null, fn (object $mail) => null);
    VenusianSdl3ServiceProvider::input($input, $toolkits);
    $window = inputWindow('provided');

    $input->poll();
    SDL_PushEvent(keyEvent($window, SDL_SCANCODE_Q, true));
    stager()->connect()->pump(0);

    try {
        expect(array_keys($input->engines()))->toBe(['sdl3'])
            ->and($input->keyboard()->isDown(Key::Q))->toBeTrue();
    } finally {
        $input->destroy();
        $window->close();
    }
});

it('registers the sdl3 pad source: HumanInput lists SDL pads with no session', function (): void {
    $id = virtualPad('provided pad');
    $input = new HumanInputManager(new class extends ToolkitManager {
        public function __construct() {}

        public function getDefaultDriver(): string
        {
            return 'none';
        }
    }, inputFrame(), device_os_family(), 'sdl3', fn (object $mail) => null);
    VenusianSdl3ServiceProvider::pads($input);

    $input->poll();

    try {
        expect($input->pads())->toBeInstanceOf(Jovian\Engines\Sdl3\Input\Sdl3PadSource::class)
            ->and($input->engines())->toBe([])
            ->and($input->gameControllers())->toHaveKey("sdl3-{$id}");
    } finally {
        $input->destroy();
        letGoOfPads();
    }
});
