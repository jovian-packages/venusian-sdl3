<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Surfaces;

use Jovian\Bindings\Sdl3\SDLError;
use Jovian\Bindings\Sdl3\Video\SDLMetal;
use Jovian\Venusian\Sdl3\Contracts\LendsToEngine;
use Jovian\Venusian\Sdl3\Exceptions\Sdl3StageException;

/**
 * An SDL Metal view; its CAMetalLayer crosses to a LAYER engine as pointer
 * bits (GPUHost->layer). The layer is borrowed: valid until release()
 * destroys the view — after the engine, before the window.
 */
final class SdlMetalView implements LendsToEngine
{
    private function __construct(
        private int $view,
        public readonly int $layer,
    ) {}

    public static function create(int $window): self
    {
        try {
            $view = SDLMetal::SDLMetalCreateView($window);
        } catch (\RuntimeException $e) {
            throw Sdl3StageException::lendFailed('Metal view', $e->getMessage());
        }

        $layer = SDLMetal::SDLMetalGetLayer($view);
        if ($layer === 0) {
            SDLMetal::SDLMetalDestroyView($view);
            throw Sdl3StageException::lendFailed('CAMetalLayer', SDLError::SDLGetError());
        }

        return new self($view, $layer);
    }

    public function release(): void
    {
        if ($this->view !== 0) {
            SDLMetal::SDLMetalDestroyView($this->view);
            $this->view = 0;
        }
    }
}
