<?php

namespace Jovian\Engines\Sdl3;

use SDL_GPUColorTargetInfo;
use SDL_GPUCommandBuffer;
use Surface\Contracts\Graphics\Effects\Blend;
use Surface\Contracts\Graphics\Effects\BlendMode;
use Surface\Contracts\Graphics\Effects\Blur;
use Surface\Contracts\Graphics\Effects\BlurSampling;
use Surface\Contracts\Graphics\Effects\Channel;
use Surface\Contracts\Graphics\Effects\ColorMatrix;
use Surface\Contracts\Graphics\Effects\Composite;
use Surface\Contracts\Graphics\Effects\CompositeOperator;
use Surface\Contracts\Graphics\Effects\Displace;
use Surface\Contracts\Graphics\Effects\EdgeMode;
use Surface\Contracts\Graphics\Effects\Field;
use Surface\Contracts\Graphics\Effects\FieldKind;
use Surface\Contracts\Graphics\Effects\FilterChain;
use Surface\Contracts\Graphics\Effects\FilterStep;
use Surface\Contracts\Graphics\Effects\Flood;
use Surface\Contracts\Graphics\Effects\Noise;
use Surface\Contracts\Graphics\Effects\NoiseType;
use Surface\Contracts\Graphics\Effects\Table;
use Surface\Contracts\Rendering\Framebuffers\Framebuffer;
use Surface\Contracts\Rendering\Framebuffers\Region;
use VelvetGE\Framebuffers\PHP\PHPLandFilters;

/**
 * Filter chains, glass captures and the glass pyramid on the GPU. Every image is an RGBA8 texture; each step is one
 * pass that draws a triangle over the image and works out each pixel from its inputs (shaders/filters.metal).
 *
 * Images live for one frame: they come from a pool by size, rounded up to 64 pixels, and go back to it when the
 * frame ends (release()). A pool entry no frame used is let go then.
 */
class SDLGPUFilters
{
    /** An image's sides are multiples of this many pixels in the pool. */
    protected const int STEP = 64;

    /** @var array<string, list<SDLGPUTexture>> images by rounded size, kept from frame to frame */
    protected array $pool = [];

    /** @var array<string, int> how many of each size this frame has taken */
    protected array $taken = [];

    /** @var array<int, SDLGPUTexture> each turbulence lattice's gradients as a texture, by seed */
    protected array $lattices = [];

    public function __construct(protected readonly SDLGPUDevice $device) {}

    /**
     * Runs $chain over a $width x $height image, the chain's source. Every step works on premultiplied colour, as
     * PHPLandFilters::run() does; the result is premultiplied too.
     *
     * @param SDLGPUTexture $source premultiplied colour, the image at its top left
     * @param int $originX where the image's top left lies on the surface: noise and fields are functions of position
     *
     * @throws SDL3Exception
     */
    public function run(FilterChain $chain, SDL_GPUCommandBuffer $commands, SDLGPUTexture $source, int $width, int $height, int $originX, int $originY): SDLGPUTexture
    {
        $results = [FilterStep::SOURCE => $source];
        $previous = $source;
        foreach ($chain->steps as $step) {
            $input = fn (?string $name): SDLGPUTexture => $name === null || $name === '' ? $previous : $results[$name];
            $made = match (true) {
                $step instanceof Blur => $this->blur($commands, $input($step->in), $width, $height, $step),
                $step instanceof ColorMatrix => $this->pass($commands, 'color_matrix', $width, $height, [$input($step->in)], pack('g20', ...$step->values)),
                $step instanceof Flood => $this->pass($commands, 'flood', $width, $height, [], pack('V4', ...self::floodPixel($step))),
                $step instanceof Composite => $this->pass($commands, 'composite', $width, $height, [$input($step->in), $input($step->in2)], pack('V4g4', self::operator($step->operator), 0, 0, 0, $step->k1, $step->k2, $step->k3, $step->k4)),
                $step instanceof Blend => $this->pass($commands, 'blend', $width, $height, [$input($step->in), $input($step->in2)], pack('V', self::mode($step->mode))),
                $step instanceof Noise => $this->noise($commands, $step, $width, $height, $originX, $originY),
                $step instanceof Displace => $this->displace($commands, $input($step->in), $width, $height, $step, is_string($step->map) ? $results[$step->map] : null),
                $step instanceof Field => $this->field($commands, $step, $width, $height, $originX, $originY),
                $step instanceof Table => $this->pass($commands, 'table', $width, $height, [$input($step->in)], self::tables($step)),
            };
            $previous = $made;
            if (($name = $step->named()) !== null) {
                $results[$name] = $made;
            }
        }

        return $previous;
    }

    /**
     * A $width x $height region of $source at ($x, $y) as an image of its own; past $source's own $sourceWidth x
     * $sourceHeight, transparent. With $straight, unpremultiplied, as Velvet's glass reads its backdrop.
     *
     * @throws SDL3Exception
     */
    public function grab(SDL_GPUCommandBuffer $commands, SDLGPUTexture $source, int $sourceWidth, int $sourceHeight, int $x, int $y, int $width, int $height, bool $straight): SDLGPUTexture
    {
        return $this->pass($commands, 'grab', $width, $height, [$source], pack('l4V4', $x, $y, $sourceWidth, $sourceHeight, $straight ? 1 : 0, 0, 0, 0));
    }

    /**
     * One level down of a $width x $height region at the top left of $source: PHPLandGlass::down().
     *
     * @throws SDL3Exception
     */
    public function kawaseDown(SDL_GPUCommandBuffer $commands, SDLGPUTexture $source, int $width, int $height): SDLGPUTexture
    {
        return $this->pass($commands, 'kawase_down', intdiv($width + 1, 2), intdiv($height + 1, 2), [$source], pack('l4', 0, 0, $width, $height));
    }

    /**
     * One level up of a $sourceWidth x $sourceHeight level, $width x $height: PHPLandGlass::up().
     *
     * @throws SDL3Exception
     */
    public function kawaseUp(SDL_GPUCommandBuffer $commands, SDLGPUTexture $source, int $sourceWidth, int $sourceHeight, int $width, int $height): SDLGPUTexture
    {
        return $this->pass($commands, 'kawase_up', $width, $height, [$source], pack('l2', $sourceWidth, $sourceHeight));
    }

    /**
     * An image of the pool, for this frame only.
     *
     * @throws SDL3Exception
     */
    public function take(int $width, int $height): SDLGPUTexture
    {
        $size = self::round($width).'x'.self::round($height);
        $index = $this->taken[$size] ?? 0;
        $this->taken[$size] = $index + 1;

        return $this->pool[$size][$index] ??= $this->device->texture(SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM, self::round($width), self::round($height), 1, SDL_GPU_TEXTUREUSAGE_SAMPLER | SDL_GPU_TEXTUREUSAGE_COLOR_TARGET);
    }

    /**
     * $rgba8 ($width x $height, rows from the top) as an image for this frame. Written on a command buffer of its own,
     * ahead of the frame; SDL writes fresh memory where an earlier frame may still read the pooled texture.
     *
     * @throws SDL3Exception
     */
    public function upload(string $rgba8, int $width, int $height): SDLGPUTexture
    {
        $size = 'upload:'.self::round($width).'x'.self::round($height);
        $index = $this->taken[$size] ?? 0;
        $this->taken[$size] = $index + 1;
        $texture = $this->pool[$size][$index] ??= $this->device->texture(SDL_GPU_TEXTUREFORMAT_R8G8B8A8_UNORM, self::round($width), self::round($height), 1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
        $this->device->write($texture, $rgba8, new Region(0, 0, $width, $height));

        return $texture;
    }

    /** The frame is over: every image goes back to the pool, and one this frame did not use is let go. */
    public function release(): void
    {
        foreach ($this->pool as $size => $images) {
            $this->pool[$size] = array_slice($images, 0, $this->taken[$size] ?? 0);
        }
        $this->pool = array_filter($this->pool);
        $this->taken = [];
    }

    /** @throws SDL3Exception */
    protected function blur(SDL_GPUCommandBuffer $commands, SDLGPUTexture $image, int $width, int $height, Blur $blur): SDLGPUTexture
    {
        $edge = $blur->edge === EdgeMode::DUPLICATE ? 1 : 0;
        if ($blur->sampling === BlurSampling::SPIRAL) {
            if ($blur->x <= 0.0) {
                return $image;
            }
            $offsets = '';
            foreach (PHPLandFilters::spiralOffsets($blur->x, $blur->samples) as [$dx, $dy]) {
                $offsets .= pack('g2', $dx, $dy);
            }

            return $this->pass($commands, 'spiral_blur', $width, $height, [$image], pack('l2V2', $width, $height, $blur->samples, $edge), [$offsets]);
        }
        foreach ([[true, $blur->x], [false, $blur->y]] as [$horizontal, $sigma]) {
            if ($sigma <= 0.0) {
                continue;
            }
            if ($sigma < 2.0) {
                $weights = PHPLandFilters::kernelWeights($sigma);
                $radius = intdiv(count($weights) - 1, 2);
                $bytes = pack('l2V3V3', $width, $height, $horizontal ? 1 : 0, $radius, $edge, 0, 0, 0).pack('l64', ...array_pad($weights, 64, 0));
                $image = $this->pass($commands, 'kernel_blur', $width, $height, [$image], $bytes);

                continue;
            }
            foreach (PHPLandFilters::boxes($sigma) as [$size, $left]) {
                $image = $this->pass($commands, 'box_blur', $width, $height, [$image], pack('l2V2lV3', $width, $height, $horizontal ? 1 : 0, $size, $left, $edge, 0, 0));
            }
        }

        return $image;
    }

    /** @throws SDL3Exception */
    protected function noise(SDL_GPUCommandBuffer $commands, Noise $noise, int $width, int $height, int $originX, int $originY): SDLGPUTexture
    {
        $type = match ($noise->type) {
            NoiseType::TURBULENCE => 0,
            NoiseType::FRACTAL => 1,
            NoiseType::HASH => 2,
        };
        [$frequencyX, $frequencyY] = PHPLandFilters::stitched($noise, $width, $height);
        $stitch = [0, 0, 0, 0];
        if ($noise->stitch) {
            $w = (int) ($width * $frequencyX + 0.5);
            $h = (int) ($height * $frequencyY + 0.5);
            $stitch = [$w, $h, (int) ($originX * $frequencyX + 4096 + $w), (int) ($originY * $frequencyY + 4096 + $h)];
        }
        $bytes = pack('l2lV', $originX, $originY, $noise->seed, $type).pack('g2', $frequencyX, $frequencyY).pack('V2', $noise->octaves, $noise->stitch ? 1 : 0).pack('l4', ...$stitch);
        // the lattice: 514 selector entries (uint16) as bytes, then 4 x 514 gradients (float2) as a texture, a row a channel
        $tables = PHPLandFilters::noiseTables($noise->seed);
        // a seed's lattice never changes: written once, when its texture is made
        if (! isset($this->lattices[$noise->seed])) {
            $this->lattices[$noise->seed] = $this->device->texture(SDL_GPU_TEXTUREFORMAT_R32G32_FLOAT, 514, 4, 1, SDL_GPU_TEXTUREUSAGE_SAMPLER);
            $this->device->write($this->lattices[$noise->seed], substr($tables, 1028), new Region(0, 0, 514, 4), false);
        }

        return $this->pass($commands, 'noise', $width, $height, [$this->lattices[$noise->seed]], $bytes, [substr($tables, 0, 1028)]);
    }

    /** @throws SDL3Exception */
    protected function field(SDL_GPUCommandBuffer $commands, Field $field, int $width, int $height, int $originX, int $originY): SDLGPUTexture
    {
        $kind = match ($field->kind) {
            FieldKind::ROUNDED_BOX => 0,
            FieldKind::INSIDE => 1,
            FieldKind::RAMP => 2,
        };
        $bytes = pack('l2V2', $originX, $originY, $kind, 0).pack('g4', ...($field->box ?? [0.0, 0.0, 0.0, 0.0])).pack('g4', ...$field->radii).pack('g2', $field->from, $field->to).pack('g2', 0.0, 0.0);

        return $this->pass($commands, 'field', $width, $height, [], $bytes);
    }

    /**
     * @param SDLGPUTexture|null $named the map an earlier step made, premultiplied; null: the step's own framebuffer
     *
     * @throws SDL3Exception
     */
    protected function displace(SDL_GPUCommandBuffer $commands, SDLGPUTexture $image, int $width, int $height, Displace $step, ?SDLGPUTexture $named): SDLGPUTexture
    {
        if (is_null($named)) {
            /** @var Framebuffer $framebuffer */
            $framebuffer = $step->map;
            [$mapWidth, $mapHeight] = [$framebuffer->viewportWidth(), $framebuffer->viewportHeight()];
            $map = $this->upload($framebuffer->toRgba8(), $mapWidth, $mapHeight);
        } else {
            [$map, $mapWidth, $mapHeight] = [$named, $width, $height];
        }
        $channel = fn (Channel $c): int => match ($c) {
            Channel::R => 0,
            Channel::G => 1,
            Channel::B => 2,
            Channel::A => 3,
        };
        $weights = '';
        foreach ($step->weights ?? PHPLandFilters::bandWeights($step->bands) as [$r, $g, $b]) {
            $weights .= pack('g4', $r, $g, $b, 0.0);
        }
        $bytes = pack('l4', $width, $height, $mapWidth, $mapHeight).pack('g2', $step->scale, $step->spread).pack('V4', $channel($step->xChannel), $channel($step->yChannel), $step->bands, is_null($named) ? 0 : 1).pack('V2', 0, 0);

        return $this->pass($commands, 'displace', $width, $height, [$image, $map], $bytes, [$weights]);
    }

    /**
     * One pass: $fragment over a $width x $height image, into an image of the pool.
     *
     * @param list<SDLGPUTexture> $textures at indexes 0, 1, …
     * @param string $bytes the fragment's uniform buffer 0
     * @param list<string> $buffers the fragment's uniform buffers 1, 2, …
     *
     * @throws SDL3Exception
     */
    protected function pass(SDL_GPUCommandBuffer $commands, string $fragment, int $width, int $height, array $textures, string $bytes = '', array $buffers = []): SDLGPUTexture
    {
        $out = $this->take($width, $height);
        $pipeline = $this->device->pipeline($fragment, 'cover', blend: false);
        $target = new SDL_GPUColorTargetInfo();
        $target->texture = $out->texture;
        $target->load_op = SDL_GPU_LOADOP_DONT_CARE;
        $target->store_op = SDL_GPU_STOREOP_STORE;

        $pass = SDL_BeginGPURenderPass($commands, [$target], 1, null) ?? throw SDL3Exception::fromSdl('no render pass could be begun');
        // the viewport is the image's own size: the pooled texture may be larger
        SDL_SetGPUViewport($pass, self::viewport($width, $height));
        SDL_BindGPUGraphicsPipeline($pass, $pipeline);
        $this->bind($pass, $textures);
        if ($bytes !== '') {
            SDL_PushGPUFragmentUniformData($commands, 0, $bytes, strlen($bytes));
        }
        foreach ($buffers as $i => $data) {
            SDL_PushGPUFragmentUniformData($commands, $i + 1, $data, strlen($data));
        }
        SDL_DrawGPUPrimitives($pass, 3, 1, 0, 0);
        SDL_EndGPURenderPass($pass);

        return $out;
    }

    /** @param list<SDLGPUTexture> $textures */
    protected function bind(\SDL_GPURenderPass $pass, array $textures): void
    {
        if ($textures === []) {
            return;
        }
        $bindings = array_map(function (SDLGPUTexture $texture): \SDL_GPUTextureSamplerBinding {
            $binding = new \SDL_GPUTextureSamplerBinding();
            $binding->texture = $texture->texture;
            $binding->sampler = $this->device->sampler();

            return $binding;
        }, $textures);
        SDL_BindGPUFragmentSamplers($pass, 0, $bindings, count($bindings));
    }

    public static function viewport(int $width, int $height): \SDL_GPUViewport
    {
        $viewport = new \SDL_GPUViewport();
        [$viewport->x, $viewport->y, $viewport->w, $viewport->h, $viewport->min_depth, $viewport->max_depth] = [0.0, 0.0, (float) $width, (float) $height, 0.0, 1.0];

        return $viewport;
    }

    /** @return array{int, int, int, int} a flood's colour, premultiplied as PHPLandFilters::flood() does */
    protected static function floodPixel(Flood $flood): array
    {
        $a = (int) round($flood->color->alpha * 255);
        $p = fn (float $c): int => intdiv((int) round($c * 255) * $a + 127, 255);

        return [$p($flood->color->red), $p($flood->color->green), $p($flood->color->blue), $a];
    }

    /** Four tables of 256 bytes, red, green, blue, alpha; an identity for one the step left out. */
    protected static function tables(Table $table): string
    {
        $bytes = '';
        foreach ([$table->red, $table->green, $table->blue, $table->alpha] as $entries) {
            $bytes .= pack('C256', ...($entries ?? range(0, 255)));
        }

        return $bytes;
    }

    protected static function operator(CompositeOperator $operator): int
    {
        return match ($operator) {
            CompositeOperator::OVER => 0,
            CompositeOperator::IN => 1,
            CompositeOperator::OUT => 2,
            CompositeOperator::ATOP => 3,
            CompositeOperator::XOR => 4,
            CompositeOperator::ARITHMETIC => 5,
        };
    }

    protected static function mode(BlendMode $mode): int
    {
        return match ($mode) {
            BlendMode::NORMAL => 0,
            BlendMode::MULTIPLY => 1,
            BlendMode::SCREEN => 2,
            BlendMode::DARKEN => 3,
            BlendMode::LIGHTEN => 4,
        };
    }

    protected static function round(int $side): int
    {
        return max(self::STEP, intdiv($side + self::STEP - 1, self::STEP) * self::STEP);
    }
}
