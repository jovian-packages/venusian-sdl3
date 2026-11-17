<?php

namespace Jovian\Engines\Sdl3;

use SDL_GPUColorTargetInfo;
use SDL_GPUCommandBuffer;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Raycasting\Camera;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Raycasting\RaycastException;
use Surface\Contracts\Rendering\Raycasting\Scenery;
use Surface\Rendering\Raycasting\Raycaster;
use WeakMap;

/**
 * The sdl3 engine's raycaster. Rendering checks every call and turns angles into vectors, in double, as it does for
 * every driver. Then each job goes where it is fastest:
 *
 *  - A view painted on a framebuffer stays on the GPU: one fragment walks a ray (raycast.metal), the view is drawn
 *    from the hits, and nothing comes back, so the CPU does not wait.
 *  - What comes back to the CPU (cast, casts, fan; a view as bytes) is one GPU pass and one read of its hits, so many
 *    rays go in one call (casts, fan), never one call a ray.
 *
 * A ray walks from its cell and where in that cell it is, so float32 holds small numbers only. The cell, wall and
 * face of a hit are the walk's own integers. Distances are float32: within a few units in the last place of Velvet's
 * doubles.
 *
 * The GPU holds textures up to 16384 a side, so the grid, the atlas of wall textures and a ray list's rows are kept
 * to that; a grid wider or taller is refused.
 */
class SDLGPUCaster extends Raycaster
{
    /** The largest texture side the GPU takes. */
    public const int MAX_SIDE = 16384;

    /**
     * How many rays a row of the hits texture holds. The hits texture is drawn into, and a GPU renders into less than
     * it samples from (the Pi 5's V3D: 7680 a side, measured). SDL has no query for it; Vulkan guarantees every device
     * 4096 (maxFramebufferWidth), so a row is that long on every backend and more rays take more rows.
     */
    public const int ROW = 4096;

    /** The grid's cells, one byte each. */
    protected readonly SDLGPUTexture $cells;

    /** @var array<int, true> the wall ids the grid holds */
    protected readonly array $ids;

    /** A ray list's hits, kept while ray lists are the same size. */
    protected ?SDLGPUTexture $hits = null;

    /** Rays' own directions, kept while ray lists are the same size; and the 1x1 that stands in for none. */
    protected ?SDLGPUTexture $directions = null;

    protected ?SDLGPUTexture $noDirections = null;

    /** A view read out as bytes, kept while views are the same size. */
    protected ?SDLGPUTexture $out = null;

    /** @var WeakMap<Scenery, array{SDLGPUTexture, SDLGPUTexture}> each scenery's atlas and wall table */
    protected WeakMap $atlases;

    /**
     * @throws SDL3Exception When the grid is wider or taller than the GPU holds a texture.
     */
    public function __construct(
        protected readonly SDLGPUDevice $device,
        Grid $grid,
    ) {
        parent::__construct($grid);

        if ($grid->width > self::MAX_SIDE || $grid->height > self::MAX_SIDE) {
            throw SDL3Exception::because("a grid {$grid->width}x{$grid->height} is past the GPU's textures, ".self::MAX_SIDE.' a side.');
        }

        $this->cells = $device->texture(SDL_GPU_TEXTUREFORMAT_R8_UINT, $grid->width, $grid->height, 1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
        $device->write($this->cells, $grid->cells, new Region(0, 0, $grid->width, $grid->height), false);

        $ids = [];
        foreach (str_split(count_chars($grid->cells, 3)) as $byte) {
            if ($byte !== "\0" && $byte !== '') {
                $ids[ord($byte)] = true;
            }
        }
        $this->ids = $ids;

        $this->atlases = new WeakMap;
    }

    public function driver(): string
    {
        return 'sdl3';
    }

    /** A view as RGBA8. The scenery's atlas is kept for the next call with it. */
    public function view(Camera $camera, int $width, int $height, Scenery $scenery, float $reach = INF): string
    {
        $arguments = self::pictureOf($camera, $width, $height, $scenery, $reach);

        return $this->viewBytes($arguments, $this->atlas($scenery));
    }

    /**
     * A view on an sdl3 framebuffer at $where, drawn on the GPU from the GPU's hits.
     *
     * @internal
     *
     * @throws RaycastException When a ray hits a wall the scenery has no entry for: nothing is drawn.
     * @throws SDL3Exception
     */
    public function paintOn(Camera $camera, Scenery $scenery, SDLGPUFramebuffer $target, Region $where, float $reach): void
    {
        [$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $table, $ceiling, $floor, $shade, $reach] = self::pictureOf($camera, $where->width, $where->height, $scenery, $reach);
        [$atlas, $walls] = $this->atlas($scenery);

        // rays and the view in one submission, and nothing comes back: the CPU does not wait for it
        $commands = $this->device->commands();
        [$hits, $perRow] = $this->hitsPass($commands, $x, $y, $dirX, $dirY, $planeX, $planeY, $width, $reach);
        $commands = $this->checked($commands, $hits, $perRow, $width, $table);

        $target->cover($commands, $where, 'raycast_view', [$hits, $walls, $atlas], self::viewBytesOf($where->x, $where->y, $width, $height, $ceiling, $floor, $shade, $perRow, false));
        $this->device->submit($commands);
    }

    /**
     * Rays from ($x, $y), one an angle: their Hits records, in order. Checked as cast() checks one ray, and each
     * angle becomes its vector in double, as cast() makes it. Walked in one GPU pass.
     *
     * @param list<float> $angles
     *
     * @throws RaycastException
     * @throws SDL3Exception
     */
    public function casts(float $x, float $y, array $angles, float $reach = INF): string
    {
        foreach (['x' => $x, 'y' => $y] as $what => $value) {
            if (! is_finite($value)) {
                throw RaycastException::notFinite("A ray's {$what}");
            }
            if (abs($value) > self::LIMIT) {
                throw RaycastException::pastLimit("A ray's {$what}", $value);
            }
        }
        if (! ($reach > 0.0)) {
            throw RaycastException::reach($reach);
        }
        if ($angles === []) {
            return '';
        }

        foreach ($angles as $angle) {
            if (! is_finite($angle)) {
                throw RaycastException::notFinite("A ray's angle");
            }
        }

        $directions = '';
        foreach ($angles as $angle) {
            $directions .= pack('g2', cos($angle), sin($angle));
        }

        $commands = $this->device->commands();
        [$hits, $perRow] = $this->hitsPass($commands, $x, $y, 0.0, 0.0, 0.0, 0.0, count($angles), $reach, $directions);

        return $this->records($commands, $hits, $perRow, count($angles));
    }

    protected function ray(float $x, float $y, float $dx, float $dy, float $reach): string
    {
        return $this->hitRecords($x, $y, $dx, $dy, 0.0, 0.0, 1, $reach);
    }

    protected function rays(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach): string
    {
        return $this->hitRecords($x, $y, $dirX, $dirY, $planeX, $planeY, $columns, $reach);
    }

    protected function picture(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $width, int $height, array $walls, int $ceiling, int $floor, int $shade, float $reach): string
    {
        return $this->viewBytes([$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $walls, $ceiling, $floor, $shade, $reach], $this->pack($walls));
    }

    /**
     * $columns rays as Hits records.
     *
     * @throws SDL3Exception
     */
    protected function hitRecords(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach): string
    {
        $commands = $this->device->commands();
        [$hits, $perRow] = $this->hitsPass($commands, $x, $y, $dirX, $dirY, $planeX, $planeY, $columns, $reach);

        return $this->records($commands, $hits, $perRow, $columns);
    }

    /**
     * A view's straight RGBA8 bytes, from an offscreen texture.
     *
     * @param array{float, float, float, float, float, float, int, int, array, int, int, int, float} $arguments pictureOf()'s
     * @param array{SDLGPUTexture, SDLGPUTexture} $atlas the atlas and the wall table
     *
     * @throws RaycastException When a ray hits a wall with no entry.
     * @throws SDL3Exception
     */
    protected function viewBytes(array $arguments, array $atlas): string
    {
        [$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $table, $ceiling, $floor, $shade, $reach] = $arguments;
        [$texture, $walls] = $atlas;

        $commands = $this->device->commands();
        [$hits, $perRow] = $this->hitsPass($commands, $x, $y, $dirX, $dirY, $planeX, $planeY, $width, $reach);
        $commands = $this->checked($commands, $hits, $perRow, $width, $table);

        if (is_null($this->out) || $this->out->width !== $width || $this->out->height !== $height) {
            $this->out = $this->device->texture(SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM, $width, $height, 1, SDL_GPU_TEXTUREUSAGE_COLOR_TARGET);
        }
        $this->pass($commands, $this->out, 'raycast_view', [$hits, $walls, $texture], self::viewBytesOf(0, 0, $width, $height, $ceiling, $floor, $shade, $perRow, true));

        return $this->device->read($commands, $this->out, new Region(0, 0, $width, $height));
    }

    /**
     * Encodes the hits pass: $columns rays, one a pixel.
     *
     * @return array{SDLGPUTexture, int} the hits texture and how many rays a pair of its rows holds
     *
     * @throws SDL3Exception
     */
    protected function hitsPass(SDL_GPUCommandBuffer $commands, float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach, ?string $directions = null): array
    {
        $perRow = min($columns, self::ROW);
        $rows = 2 * intdiv($columns + $perRow - 1, $perRow);
        if (is_null($this->hits) || $this->hits->width !== $perRow || $this->hits->height !== $rows) {
            $this->hits = $this->device->texture(SDL_GPU_TEXTUREFORMAT_R32G32B32A32_FLOAT, $perRow, $rows, 1, SDL_GPU_TEXTUREUSAGE_COLOR_TARGET | SDL_GPU_TEXTUREUSAGE_SAMPLER);
        }

        // Velvet walks nothing from outside the grid: every ray misses
        $inside = $x >= 0.0 && $y >= 0.0 && $x < $this->grid->width && $y < $this->grid->height;
        $cellX = $inside ? (int) floor($x) : 0;
        $cellY = $inside ? (int) floor($y) : 0;
        // past what float32 holds, a reach stops nothing a grid this size can show
        $reaches = is_finite($reach) && $reach < 3.0e38;
        $flags = ($reaches ? 1 : 0) | ($inside ? 2 : 0) | (is_null($directions) ? 0 : 4);

        $cast = pack('l2g6V3gl2', $cellX, $cellY, $inside ? $x - $cellX : 0.0, $inside ? $y - $cellY : 0.0, $dirX, $dirY, $planeX, $planeY, $columns, $perRow, $flags, $reaches ? $reach : 0.0, $this->grid->width, $this->grid->height);
        $this->pass($commands, $this->hits, 'raycast_hits', [$this->cells, $this->directions($directions ?? '', $perRow, intdiv($rows, 2))], $cast);

        return [$this->hits, $perRow];
    }

    /**
     * When the grid holds a wall the table has no entry for, the hits are read before a view is drawn: a ray on such
     * a wall is refused, as Velvet refuses it, and nothing is drawn.
     *
     * @return SDL_GPUCommandBuffer what to go on encoding into
     *
     * @throws RaycastException
     * @throws SDL3Exception
     */
    protected function checked(SDL_GPUCommandBuffer $commands, SDLGPUTexture $hits, int $perRow, int $columns, array $table): SDL_GPUCommandBuffer
    {
        if (array_diff_key($this->ids, $table) === []) {
            return $commands;
        }

        $records = $this->records($commands, $hits, $perRow, $columns);
        for ($i = 0; $i < $columns; $i++) {
            $wall = ord($records[$i * 22 + 20]);
            if ($wall !== 0 && ! isset($table[$wall])) {
                throw RaycastException::noWall($wall);
            }
        }

        return $this->device->commands();
    }

    /**
     * The hits texture's rays as Hits records: 22 bytes each, all zero for a miss. Submits $commands and waits for
     * them.
     *
     * @throws SDL3Exception
     */
    protected function records(SDL_GPUCommandBuffer $commands, SDLGPUTexture $hits, int $perRow, int $columns): string
    {
        $rows = $hits->height;
        $floats = unpack('g*', $this->device->read($commands, $hits, new Region(0, 0, $perRow, $rows), 16));

        $out = '';
        for ($i = 0; $i < $columns; $i++) {
            // unpack counts from 1
            $first = ((2 * intdiv($i, $perRow)) * $perRow + $i % $perRow) * 4 + 1;
            $second = $first + $perRow * 4;
            $wall = (int) $floats[$second];
            $out .= $wall === 0
                ? str_repeat("\0", 22)
                : pack('eevvCC', $floats[$first], $floats[$first + 1], (int) $floats[$first + 2], (int) $floats[$first + 3], $wall, (int) $floats[$second + 1]);
        }

        return $out;
    }

    /**
     * Each ray's direction (RG32Float, ray i at i % $perRow, i / $perRow), kept while it is the same size. With no
     * directions, a 1x1 stands in: the pass binds a texture either way.
     *
     * @throws SDL3Exception
     */
    protected function directions(string $directions, int $perRow, int $rows): SDLGPUTexture
    {
        if ($directions === '') {
            return $this->noDirections ??= $this->device->texture(SDL_GPU_TEXTUREFORMAT_R32G32_FLOAT, 1, 1, 1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
        }
        if (is_null($this->directions) || $this->directions->width !== $perRow || $this->directions->height !== $rows) {
            $this->directions = $this->device->texture(SDL_GPU_TEXTUREFORMAT_R32G32_FLOAT, $perRow, $rows, 1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
        }
        $this->device->write($this->directions, str_pad($directions, $perRow * $rows * 8, "\0"), new Region(0, 0, $perRow, $rows));

        return $this->directions;
    }

    /**
     * The wall table as a texture: per wall id its kind, colour, and where its texture is in the atlas. Written once,
     * when it is made.
     *
     * @param array<int, int|array{string, int, int}> $table
     * @param array<int, array{int, int}> $places
     *
     * @throws SDL3Exception
     */
    protected function wallTable(array $table, array $places): SDLGPUTexture
    {
        $texels = str_repeat("\0", 256 * 16);
        foreach ($table as $id => $wall) {
            $texel = is_int($wall)
                ? pack('V4', 1, $wall, 0, 0)
                : pack('V4', 2, 0, $places[$id][0] | ($places[$id][1] << 16), $wall[1] | ($wall[2] << 16));
            $texels = substr_replace($texels, $texel, $id * 16, 16);
        }
        $walls = $this->device->texture(SDL_GPU_TEXTUREFORMAT_R32G32B32A32_UINT, 256, 1, 1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
        $this->device->write($walls, $texels, new Region(0, 0, 256, 1), false);

        return $walls;
    }

    /**
     * The scenery on the GPU: its wall textures in one atlas and its wall table, made the first time it is drawn and
     * never written again, so a frame the GPU is still drawing can go on reading them.
     *
     * @return array{SDLGPUTexture, SDLGPUTexture} the atlas, the wall table
     */
    protected function atlas(Scenery $scenery): array
    {
        return $this->atlases[$scenery] ??= $this->pack($scenery->table());
    }

    /**
     * Wall textures packed in rows into one RGBA8Uint texture, tallest first.
     *
     * @param array<int, int|array{string, int, int}> $table
     * @return array{SDLGPUTexture, SDLGPUTexture} the atlas and the wall table that says where each wall's texture is in it
     *
     * @throws SDL3Exception When a texture, or all of them, is past the GPU's textures.
     */
    protected function pack(array $table): array
    {
        $textures = array_filter($table, is_array(...));
        if ($textures === []) {
            return [$this->device->texture(SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UINT, 1, 1, 1, SDL_GPU_TEXTUREUSAGE_SAMPLER), $this->wallTable($table, [])];
        }

        $area = 0;
        $widest = 0;
        foreach ($textures as $id => [, $width, $height]) {
            if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
                throw SDL3Exception::because("wall {$id}'s texture {$width}x{$height} is past the GPU's textures, ".self::MAX_SIDE.' a side.');
            }
            $area += $width * $height;
            $widest = max($widest, $width);
        }
        $side = min(self::MAX_SIDE, max($widest, (int) ceil(sqrt($area))));

        uasort($textures, fn (array $a, array $b): int => $b[2] <=> $a[2]);
        [$x, $y, $shelf, $places] = [0, 0, 0, []];
        foreach ($textures as $id => [, $width, $height]) {
            if ($x + $width > $side) {
                [$x, $y, $shelf] = [0, $y + $shelf, 0];
            }
            $places[$id] = [$x, $y];
            $x += $width;
            $shelf = max($shelf, $height);
        }
        $tall = $y + $shelf;
        if ($tall > self::MAX_SIDE) {
            throw SDL3Exception::because("the scenery's wall textures need an atlas {$side}x{$tall}: past the GPU's textures, ".self::MAX_SIDE.' a side.');
        }

        // each wall's texture into its place: not cycled, so one write does not take away another's
        $atlas = $this->device->texture(SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UINT, $side, $tall, 1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
        foreach ($textures as $id => [$bytes, $width, $height]) {
            $this->device->write($atlas, $bytes, new Region($places[$id][0], $places[$id][1], $width, $height), false);
        }

        return [$atlas, $this->wallTable($table, $places)];
    }

    /**
     * Encodes one pass over all of $target with $fragment.
     *
     * @param list<SDLGPUTexture> $textures
     *
     * @throws SDL3Exception
     */
    protected function pass(SDL_GPUCommandBuffer $commands, SDLGPUTexture $target, string $fragment, array $textures, string $bytes): void
    {
        $pipeline = $this->device->pipeline($fragment, 'cover', blend: false, format: $target->format);
        $color = new SDL_GPUColorTargetInfo();
        $color->texture = $target->texture;
        $color->load_op = SDL_GPU_LOADOP_DONT_CARE;
        $color->store_op = SDL_GPU_STOREOP_STORE;
        $pass = SDL_BeginGPURenderPass($commands, [$color], 1, null) ?? throw SDL3Exception::fromSdl('no render pass could be begun');
        SDL_BindGPUGraphicsPipeline($pass, $pipeline);
        $bindings = array_map(function (SDLGPUTexture $texture): \SDL_GPUTextureSamplerBinding {
            $binding = new \SDL_GPUTextureSamplerBinding();
            $binding->texture = $texture->texture;
            $binding->sampler = $this->device->sampler();

            return $binding;
        }, $textures);
        SDL_BindGPUFragmentSamplers($pass, 0, $bindings, count($bindings));
        SDL_PushGPUFragmentUniformData($commands, 0, $bytes, strlen($bytes));
        SDL_DrawGPUPrimitives($pass, 3, 1, 0, 0);
        SDL_EndGPURenderPass($pass);
    }

    /** The View struct of raycast.metal. */
    protected static function viewBytesOf(int $x, int $y, int $width, int $height, int $ceiling, int $floor, int $shade, int $perRow, bool $straight): string
    {
        return pack('l4V8', $x, $y, $width, $height, $ceiling, $floor, $shade, $perRow, $straight ? 1 : 0, 0, 0, 0);
    }
}
