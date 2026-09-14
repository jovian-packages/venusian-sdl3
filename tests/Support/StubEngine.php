<?php

declare(strict_types=1);

namespace Venusian\Tests\Support;

use Surface\Contracts\Drawing\GPUAttachment;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Contracts\Drawing\GPUEngineDriver;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\SurfaceKind;

/** An engine of any surface kind that records the host it was given; throws attach_failure from attach() when set. */
final class StubEngine implements GPUEngineDriver
{
    /** @var list<GPUHost> */
    public array $hosts = [];

    public function __construct(
        private readonly SurfaceKind $kind = SurfaceKind::HOST_WINDOW,
        private readonly GPUEngine $engine = GPUEngine::SDL3,
        private readonly ?\Throwable $attach_failure = null,
    ) {}

    public function engine(): GPUEngine
    {
        return $this->engine;
    }

    public function surfaceKind(): SurfaceKind
    {
        return $this->kind;
    }

    public function attach(GPUHost $host): GPUAttachment
    {
        $this->hosts[] = $host;

        if (! is_null($this->attach_failure)) {
            throw $this->attach_failure;
        }

        return new GPUAttachment(new NullExecutor());
    }
}
