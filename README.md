# jovian/venusian-sdl3

SDL3 composition for Venusian Surface. Installing it publishes two container aliases: `stage.sdl3`, the stage host that mints whole SDL windows an engine owns (and lends that engine a GL context, a CAMetalLayer or a Vulkan surface), and `gpu.sdl3`, the SDL_GPU engine that draws into a stage.

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
