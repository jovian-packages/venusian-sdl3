# jovian/venusian-sdl3

SDL3 composition for Venusian Surface. Installing it publishes three container aliases: `stage.sdl3`, the stage host that mints whole SDL windows an engine owns (and lends that engine a GL context, a CAMetalLayer or a Vulkan surface); `gpu.sdl3`, the SDL_GPU engine that draws into a stage; and `input.sdl3`, the input engine that reads keyboard, mouse and gamepads from SDL.

## Install

```bash
composer require jovian/venusian-sdl3
```

## Example

```php
use Surface\Stage\MagicAliases\Stage;

$stage = Stage::open('main', 'sdl3', 1024, 640, 'sdl3')   // engine, then host
    ->setTitle('Orbit')
    ->show();                                            // stages are minted hidden
```

## Input

`input.sdl3` (Surface's default input engine) reads keyboard, text and mouse for sdl3 stage windows, and gamepads on every OS with no SDL window needed. Keys are layout-independent scancodes. Every SDL gamepad is a game controller, id `sdl3-<instance_id>`. The dock polls the engine each tick; a sketch only reads.

- `mouse()->window()`: the stage under SDL's mouse focus after each poll; null when the pointer is over no stage. Position is the last one reported.
- `mouse()->wheel()`: `dy > 0` = up/away from the user, whatever the OS scroll direction ("natural" scrolling is undone).
- Focus loss: SDL releases held keys itself (key-ups arrive as events).
- Scancode 83 reads `Key::NUM_LOCK` (SDL's name); the Mac Clear key sends it, so it is `NUM_LOCK` here and `CLEAR` on appkit.

```php
use Surface\Contracts\HumanInput\Key;
use Surface\HumanInput\MagicAliases\HumanInput;

$sdl = HumanInput::engine('sdl3');               // connects on first use
$sdl->keyboard()->isPressed(Key::SPACE);
$sdl->mouse()->window();                         // stage name under the pointer, or null
foreach ($sdl->gameControllers() as $id => $pad) {   // $id is 'sdl3-<instance_id>'; SDL ids start at 1
    $pad->leftStick();                           // ['x' => -1…1, 'y' => -1…1], y down = +
}
```
