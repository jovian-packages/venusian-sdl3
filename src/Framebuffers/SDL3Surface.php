<?php

namespace Jovian\Engines\Sdl3\Framebuffers;

use SDL_Renderer;
use SDL_Surface;
use SDL_Rect;
use SDL_Vertex;
use SDL_FColor;
use SDL_FPoint;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\NutsAndBolts\Affine;
use Surface\Rendering\Framebuffers\FramebufferDelegate;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;
use VelvetGE\Framebuffers\ImagePlacement;
use VelvetGE\Framebuffers\Layout;
use VelvetGE\Framebuffers\MaskList;
use VelvetGE\Framebuffers\ReadsBlitSources;
use VelvetGE\Framebuffers\SpanList;

/**
 * One SDL surface, SDL's way: SDL holds the pixels and does every fill, copy, conversion and blend.
 * The surface wraps a PHP string (SDL_CreateSurfaceFrom keeps it alive) that holds the format's
 * bytes as the framework defines them (SDL3Formats picks the SDL format with that byte layout),
 * rows packed with no padding, so pointer() is SDL's pixel address, a direct drawer can paint
 * memory() in place, and reads copy bytes out of it.
 *
 * Byte moves (fill, writeRgba8, blits, scroll, read-outs in another format) are SDL_BlitSurface
 * and SDL_FillSurfaceRect with the source's blend mode NONE: bytes move as they are, exact. A
 * source another library lends as memory is wrapped where it is (SDL_CreateSurfaceFrom over its
 * address) and SDL copies or converts it in C.
 *
 * Blends are SDL's own. A solid colour through coverage (paintSpans, paintMasks) is an SDL blit with
 * SDL_BLENDMODE_BLEND. An image (paintImage) is an SDL texture drawn by the surface's software
 * renderer with SDL_RenderGeometry as two triangles: SDL transforms and samples it, the tint and the
 * opacity are the vertices' colour (the software renderer takes colour and alpha from the vertices,
 * not from a texture's mods), the clip is the renderer's clip. SDL's BLEND adds src·α + dst·(1-α)
 * without dividing by the result alpha, so over a translucent destination its bytes are not
 * Lustrous's straight-alpha lerp, and rotated or scaled images are sampled the way SDL samples
 * them: blended bytes land within TOLERANCE of lustrous-php where tests measure it, and SDL3SurfaceTest
 * asserts the measured distance where they do not.
 *
 * SDL has no operation that takes an A8 mask, so an image through coverage is composited in steps
 * of SDL blends (see paintImage): the image is drawn into a copy of the destination, scaled by the
 * coverage with SDL_BLENDMODE_MUL, added onto a premultiplied layer holding the coverage as its alpha
 * (SDL_BLENDMODE_ADD_PREMULTIPLIED), and blended onto the surface (SDL_BLENDMODE_BLEND_PREMULTIPLIED).
 *
 * No gradients (SDL has no gradient operation), so this is not a GradientFramebuffer, no effects (no
 * filter chains, Kawase levels or glass) and no kinds: SDL has one surface.
 */
final class SDL3Surface extends FramebufferDelegate implements CompositingFramebuffer, ScrollingFramebuffer, PaintTarget
{
    use ReadsBlitSources;

    /** How far a blended byte may land from lustrous-php's: 5% of 255, as ruled for library framebuffers. */
    public const int TOLERANCE = 13;

    /** The largest cell, in pixels along an edge, an image's quad is drawn in: SDL's software triangle blit refuses larger triangles. */
    private const int CELL = 256;

    /** The ext-sdl3 functions this class calls beyond the first surface bindings: a build without one is stale. */
    public const array NEEDS = [
        'SDL_CreateSurface', 'SDL_SetSurfaceAlphaMod', 'SDL_SetSurfaceBlendMode',
        'SDL_CreateSoftwareRenderer', 'SDL_DestroyRenderer', 'SDL_CreateTextureFromSurface', 'SDL_DestroyTexture',
        'SDL_SetTextureBlendMode', 'SDL_SetTextureScaleMode', 'SDL_SetRenderClipRect', 'SDL_RenderGeometry', 'SDL_FlushRenderer',
    ];

    private SDL_Surface $surface;

    /** The surface's pixels. SDL writes into it; PHP never writes it and never hands it out uncopied. */
    private string $bits;

    private int $code;

    private int $bytes_per_pixel;

    private int $pitch;

    /** The software renderer drawing into $surface, made on the first image. */
    private ?SDL_Renderer $renderer = null;

    /** @var array<int, array<string, string>> Coverage byte to RGBA pixel, per colour, for paintMasks. */
    private static array $ramps = [];

    /**
     * @throws FramebufferException When SDL has no format with this byte layout, or cannot wrap the surface.
     */
    public function __construct(
        private readonly FormatSpec $format,
        private readonly int $width,
        private readonly int $height,
    ) {
        Layout::size($width, $height);
        $this->code = SDL3Formats::code($format)
            ?? throw FramebufferException::unsupportedFormat($format, 'SDL has no format with this byte layout.');
        $this->bytes_per_pixel = SDL3Formats::bytesPerPixel($this->code);
        $this->pitch = $width * $this->bytes_per_pixel;
        $this->wrap(str_repeat("\0", $this->pitch * $height));
    }

    public function __clone()
    {
        $this->renderer = null;
        $this->wrap($this->snapshot());
    }

    public function __destruct()
    {
        // A renderer draws into the surface: it goes first.
        if (! is_null($this->renderer)) {
            SDL_DestroyRenderer($this->renderer);
        }
        SDL_DestroySurface($this->surface);
    }

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function hostFormat(): FormatSpec
    {
        return $this->format;
    }

    /** SDL's pixels: the format's bytes row by row, no padding. The surface owns them. */
    public function memory(): ?PixelMemory
    {
        return new PixelMemory($this->pointer(), $this->width, $this->height, $this->pitch, $this->format, $this->surface);
    }

    public function pointer(): int
    {
        return $this->surface->pixels();
    }

    public function drewInto(?Region ...$regions): static
    {
        foreach ($regions as $region) {
            $this->wrote($region);
        }

        return $this;
    }

    public function getPixel(int $x, int $y): int
    {
        $this->guard($x, $y);

        return $this->decode($this->read(new Region($x, $y, 1, 1)));
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->guard($x, $y);
        $this->paintCell(new Region($x, $y, 1, 1), $this->encode($value));

        return $this->wrote(new Region($x, $y, 1, 1));
    }

    public function setPixels(array $pixels): static
    {
        $bounds = $this->bounds($pixels, 3, '[x, y, value]');
        foreach ($pixels as [$x, $y, $value]) {
            $this->paintCell(new Region($x, $y, 1, 1), $this->encode($value));
        }

        return $this->wrote($bounds);
    }

    public function setRegion(array $coordinates, int $value): static
    {
        $bounds = $this->bounds($coordinates, 2, '[x, y]');
        $cell = $this->encode($value);
        foreach ($coordinates as [$x, $y]) {
            $this->paintCell(new Region($x, $y, 1, 1), $cell);
        }

        return $this->wrote($bounds);
    }

    /** A rect of one value, clipped to the surface: a rect straddling the edge is ordinary. */
    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $region = (new Region($x, $y, max(0, $width), max(0, $height)))->intersect($this->whole());
        if (is_null($region)) {
            return $this;
        }
        $this->paintCell($region, $this->encode($color));

        return $this->wrote($region);
    }

    public function clear(): static
    {
        return $this->fill(0);
    }

    public function fill(int $color): static
    {
        $this->paintCell($this->whole(), $this->encode($color));

        return $this->wrote($this->whole());
    }

    /** RGBA8 in, through SDL_BlitSurface with blend NONE: bytes move as they are, 24-bit drops alpha, 565 narrows by SDL's own rule. */
    public function writeRgba8(string $rgba8, int $width, int $height, int $x = 0, int $y = 0): static
    {
        if ($width < 1 || $height < 1 || strlen($rgba8) !== $width * $height * 4) {
            throw new FramebufferException("writeRgba8() takes {$width}x{$height} RGBA8 pixels (".(max(0, $width) * max(0, $height) * 4).' bytes), got '.strlen($rgba8).'.');
        }
        $target = (new Region($x, $y, $width, $height))->intersect($this->whole());
        if (is_null($target)) {
            return $this;
        }
        $source = $this->over(SDL_PIXELFORMAT_RGBA32, $width, $height, $rgba8, $width * 4);
        $this->copyFrom($source, new Region($target->x - $x, $target->y - $y, $target->width, $target->height), $target);
        SDL_DestroySurface($source);

        return $this->wrote($target);
    }

    public function blitTo(InstanceThatManagesAFramebuffer $target, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(InstanceThatManagesAFramebuffer $source, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        if ($source instanceof self) {
            // Surface to surface: SDL_BlitSurface with blend NONE, converting between the two formats if they differ.
            $target = (new Region($offset_x, $offset_y, $source->width, $source->height))->intersect($this->whole());
            if (is_null($target)) {
                return $this;
            }
            $this->copyFrom($source->surface, new Region($target->x - $offset_x, $target->y - $offset_y, $target->width, $target->height), $target);

            return $this->wrote($target);
        }
        $view = $this->view($source);
        if (! is_null($view)) {
            // Another library's memory, wrapped where it is: SDL copies or converts it in C.
            $target = (new Region($offset_x, $offset_y, $view->w(), $view->h()))->intersect($this->whole());
            if (! is_null($target)) {
                $this->copyFrom($view, new Region($target->x - $offset_x, $target->y - $offset_y, $target->width, $target->height), $target);
            }
            SDL_DestroySurface($view);

            return is_null($target) ? $this : $this->wrote($target);
        }
        [$rgba8, $width, $height, $top] = $this->blitSource($source);

        return $this->writeRgba8($rgba8, $width, $height, $offset_x, $offset_y + $top);
    }

    /**
     * $source's pixels as an SDL surface over its own memory, when it lends memory in a format SDL
     * holds: nothing is copied. The caller destroys it. Null sends the caller to the RGBA8 read-back.
     */
    private function view(InstanceThatManagesAFramebuffer $source): ?SDL_Surface
    {
        $memory = $this->sourceMemory($source);
        $code = is_null($memory) ? null : SDL3Formats::code($memory->format);
        if (is_null($code) || $memory->stride < $memory->width * SDL3Formats::bytesPerPixel($code)) {
            return null;
        }
        $view = SDL_CreateSurfaceFrom($memory->width, $memory->height, $code, $memory->address, $memory->stride);
        if (is_null($view)) {
            return null;
        }
        SDL_SetSurfaceBlendMode($view, SDL_BLENDMODE_NONE);

        return $view;
    }

    /** SDL_BlitSurface with blend NONE on $source: $from's rect to $to's, bytes as they are. */
    private function copyFrom(SDL_Surface $source, Region $from, Region $to): void
    {
        SDL_SetSurfaceBlendMode($source, SDL_BLENDMODE_NONE);
        SDL_BlitSurface($source, new SDL_Rect($from->x, $from->y, $from->width, $from->height), $this->surface, new SDL_Rect($to->x, $to->y, $to->width, $to->height));
    }

    /** SDL's blit into RGBA8: 32-bit bytes move, 24-bit gains alpha 255, 565 widens by SDL's bit replication. */
    public function toRgba8(): string
    {
        return $this->convert($this->whole(), SDL_PIXELFORMAT_RGBA32);
    }

    public function dump(?int $layer = null): string
    {
        if (! is_null($layer) && $layer !== 0) {
            throw new FramebufferException("An SDL surface has one layer, 0; got {$layer}.");
        }

        return $this->snapshot();
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return self::answer($this->in($this->whole(), $spec), $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        $this->inside($region);

        return self::answer($this->in($region, $spec), $as_array);
    }

    public function scratch(int $width, int $height): InstanceThatManagesAFramebuffer
    {
        return new self(FormatSpec::rgba8(), $width, $height);
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    public function scroll(Region $region, int $dx, int $dy): void
    {
        $clip = $region->intersect($this->whole());
        $source = is_null($clip) ? null : self::scrollSource($clip, $dx, $dy);
        if (is_null($source)) {
            return;
        }
        // Through a scratch surface, since the two rects may overlap: both copies are SDL's blit.
        $held = SDL_CreateSurface($source->width, $source->height, $this->code);
        SDL_SetSurfaceBlendMode($held, SDL_BLENDMODE_NONE);
        SDL_BlitSurface($this->surface, new SDL_Rect($source->x, $source->y, $source->width, $source->height), $held, null);
        SDL_BlitSurface($held, null, $this->surface, new SDL_Rect($source->x + $dx, $source->y + $dy, $source->width, $source->height));
        SDL_DestroySurface($held);
        $this->touched($clip);
    }

    /**
     * Spans in one colour. Each span is one SDL blit: a 1x1 surface of the colour stretched over the
     * span's pixels with SDL_BLENDMODE_BLEND, its alpha mod the colour's alpha scaled by the span's
     * coverage. Spans paint in list order.
     */
    public function paintSpans(string $spans, int $rgba8): static
    {
        [$list, $bounds] = SpanList::read($spans, $rgba8, $this->width, $this->height);
        $alpha = $rgba8 & 0xFF;
        if (is_null($bounds) || $alpha === 0) {
            return is_null($bounds) ? $this : $this->wrote($bounds);
        }
        $dot = SDL_CreateSurfaceFrom(1, 1, SDL_PIXELFORMAT_RGBA32, pack('C4', ($rgba8 >> 24) & 0xFF, ($rgba8 >> 16) & 0xFF, ($rgba8 >> 8) & 0xFF, 255), 4);
        SDL_SetSurfaceBlendMode($dot, SDL_BLENDMODE_BLEND);
        $set = 255;
        foreach ($list as [$y, $x, $length, $coverage]) {
            $effective = intdiv($alpha * $coverage + 127, 255);
            if ($effective === 0) {
                continue;
            }
            if ($effective !== $set) {
                SDL_SetSurfaceAlphaMod($dot, $effective);
                $set = $effective;
            }
            SDL_BlitSurfaceScaled($dot, null, $this->surface, new SDL_Rect($x, $y, $length, 1), SDL_SCALEMODE_NEAREST);
        }
        SDL_DestroySurface($dot);

        return $this->wrote($bounds);
    }

    /**
     * Masks in one colour: each mask's coverage bytes become RGBA pixels of the colour, their alpha the
     * colour's scaled by the coverage, in one strtr() pass (a byte to four bytes, no PHP loop over
     * pixels), and that is an SDL blit with SDL_BLENDMODE_BLEND.
     */
    public function paintMasks(array $masks, int $rgba8, Region $clip): static
    {
        MaskList::check($masks, $rgba8, $clip, $this->width, $this->height);
        $ramp = $this->ramp($rgba8);
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach ($masks as [$coverage, $width, $mx, $my]) {
            $y0 = max($my, $clip->y);
            $y1 = min($my + intdiv(strlen($coverage), $width), $clip->bottom());
            $x0 = max($mx, $clip->x);
            $x1 = min($mx + $width, $clip->right());
            if ($y0 >= $y1 || $x0 >= $x1) {
                continue;
            }
            $w = $x1 - $x0;
            $rows = [];
            for ($y = $y0; $y < $y1; $y++) {
                $row = substr($coverage, ($y - $my) * $width + $x0 - $mx, $w);
                $lead = strspn($row, "\0");
                if ($lead < $w) {
                    $left = min($left, $x0 + $lead);
                    $right = max($right, $x0 + strlen(rtrim($row, "\0")));
                    $top = min($top, $y);
                    $bottom = max($bottom, $y + 1);
                }
                $rows[] = $row;
            }
            $pixels = SDL_CreateSurfaceFrom($w, $y1 - $y0, SDL_PIXELFORMAT_RGBA32, strtr(implode('', $rows), $ramp), $w * 4);
            SDL_SetSurfaceBlendMode($pixels, SDL_BLENDMODE_BLEND);
            SDL_BlitSurface($pixels, null, $this->surface, new SDL_Rect($x0, $y0, $w, $y1 - $y0));
            SDL_DestroySurface($pixels);
        }

        return $right === PHP_INT_MIN ? $this : $this->wrote(new Region($left, $top, $right - $left, $bottom - $top));
    }

    /**
     * Another framebuffer's pixels through $placement, SDL's way: an SDL texture of the source (made
     * from a surface over its memory where it lends it, so nothing is read back), drawn by the
     * surface's software renderer with SDL_RenderGeometry as two triangles whose positions are the
     * placement applied to the source's corners (or the window's), whose texture coordinates are the
     * same corners in the source, and whose colour is the tint with the alpha scaled by $opacity. SDL
     * rasterizes, transforms and samples; the filter is the texture's scale mode; the clip is the
     * renderer's clip. $coverage goes through SDL blends (see the class comment).
     */
    public function paintImage(InstanceThatManagesAFramebuffer $source, Affine $placement, int $opacity = 255, Filter $filter = Filter::NEAREST, ?Region $clip = null, int $tint = 0xFFFFFFFF, ?array $window = null, ?string $coverage = null): static
    {
        $picture = $source instanceof self ? $source->surface : $this->view($source);
        $temporary = ! ($source instanceof self);
        if (is_null($picture)) {
            [$rgba8, $sw, $sh, $top] = $this->blitSource($source);
            $picture = $this->over(SDL_PIXELFORMAT_RGBA32, $sw, $sh, $rgba8, $sw * 4);
        } else {
            [$sw, $sh, $top] = [$picture->w(), $picture->h(), 0];
        }
        try {
            $plan = ImagePlacement::plan($placement, $sw, $sh, $top, $this->whole(), $clip, $opacity, $window);
            if (is_null($plan) || $opacity === 0) {
                return $this;
            }
            [$target, , $held] = $plan;
            [$u0, $v0, $u1, $v1] = $held ?? [0.0, 0.0, (float) $sw, (float) $sh];
            $corner = fn (float $u, float $v): array => $placement->apply($u, $v + $top);
            $corners = [[$u0, $v0], [$u1, $v0], [$u1, $v1], [$u0, $v1]];
            // The tint's channels, and the opacity scaled by the tint's alpha: the vertices' colour.
            $color = [(($tint >> 24) & 0xFF) / 255, (($tint >> 16) & 0xFF) / 255, (($tint >> 8) & 0xFF) / 255, intdiv($opacity * ($tint & 0xFF) + 127, 255) / 255];

            if (is_null($coverage) && $this->bytes_per_pixel === 4) {
                $this->draw($this->renderer ??= $this->makeRenderer($this->surface), $picture, $corners, $corner, $color, $filter, $target, 0, 0);
            } elseif (is_null($coverage)) {
                $this->drawCopy($picture, $corners, $corner, $color, $filter, $target);
            } else {
                $this->drawThrough($coverage, $picture, $corners, $corner, $color, $filter, $target);
            }
        } finally {
            if ($temporary) {
                SDL_DestroySurface($picture);
            }
        }

        return $this->wrote($target);
    }

    /**
     * $picture as a texture drawn by $renderer, its quad (the four source $corners placed) shifted by
     * (-$dx, -$dy) and clipped to $target shifted the same.
     *
     * @param  list<array{float, float}>  $corners
     * @param  list<float>  $color
     */
    private function draw(SDL_Renderer $renderer, SDL_Surface $picture, array $corners, \Closure $place, array $color, Filter $filter, Region $target, int $dx, int $dy): void
    {
        $texture = SDL_CreateTextureFromSurface($renderer, $picture)
            ?? throw new FramebufferException('SDL could not make a texture of the source: '.SDL_GetError());
        SDL_SetTextureBlendMode($texture, SDL_BLENDMODE_BLEND);
        SDL_SetTextureScaleMode($texture, $filter === Filter::LINEAR ? SDL_SCALEMODE_LINEAR : SDL_SCALEMODE_NEAREST);
        $fcolor = new SDL_FColor();
        [$fcolor->r, $fcolor->g, $fcolor->b, $fcolor->a] = $color;
        // SDL's software triangle blit refuses a triangle that is too large ("triangle area overflow":
        // a rotated 1024x768 image, measured on SDL 3.4) and says so only in SDL_GetError(), so the
        // quad is a grid of cells no larger than CELL pixels along either edge, in the source and on
        // the surface: the same affine map, drawn as many triangles.
        [[$u0, $v0], [$u1], [, $v1]] = [$corners[0], $corners[1], $corners[2]];
        [$ax, $ay] = $place($u0, $v0);
        [$bx, $by] = $place($u1, $v0);
        [$cx, $cy] = $place($u0, $v1);
        $across = max(1, min(64, (int) ceil(max($u1 - $u0, hypot($bx - $ax, $by - $ay)) / self::CELL)));
        $down = max(1, min(64, (int) ceil(max($v1 - $v0, hypot($cx - $ax, $cy - $ay)) / self::CELL)));
        $vertices = [];
        for ($j = 0; $j <= $down; $j++) {
            for ($i = 0; $i <= $across; $i++) {
                $u = $u0 + ($u1 - $u0) * $i / $across;
                $v = $v0 + ($v1 - $v0) * $j / $down;
                [$x, $y] = $place($u, $v);
                $vertices[] = new SDL_Vertex(new SDL_FPoint($x - $dx, $y - $dy), $fcolor, new SDL_FPoint($u / $picture->w(), $v / $picture->h()));
            }
        }
        $indices = [];
        $row = $across + 1;
        for ($j = 0; $j < $down; $j++) {
            for ($i = 0; $i < $across; $i++) {
                $a = $j * $row + $i;
                array_push($indices, $a, $a + 1, $a + $row + 1, $a, $a + $row + 1, $a + $row);
            }
        }
        SDL_SetRenderClipRect($renderer, new SDL_Rect($target->x - $dx, $target->y - $dy, $target->width, $target->height));
        SDL_ClearError();
        $drawn = SDL_RenderGeometry($renderer, $texture, $vertices, count($vertices), $indices, count($indices));
        $flushed = SDL_FlushRenderer($renderer);
        $error = SDL_GetError();
        SDL_DestroyTexture($texture);
        if (! $drawn || ! $flushed || $error !== '') {
            throw new FramebufferException("SDL could not draw the image: {$error}");
        }
    }

    /**
     * An image onto a 24- or 16-bit surface. SDL's software renderer takes colour and alpha right on
     * a 32-bit target but, on these, copies part of a textured triangle's pixels with no blend
     * (measured on SDL 3.4: an image moved by whole pixels over an opaque destination lands up to 245
     * from lustrous-php, in the first rows of the quad). So the image is drawn into an RGBA32 copy of
     * the target's rect, where the renderer is right, and the rect is copied back (SDL_BLENDMODE_NONE).
     *
     * @param  list<array{float, float}>  $corners
     * @param  list<float>  $color
     */
    private function drawCopy(SDL_Surface $picture, array $corners, \Closure $place, array $color, Filter $filter, Region $target): void
    {
        $rect = new SDL_Rect($target->x, $target->y, $target->width, $target->height);
        $drawn = SDL_CreateSurface($target->width, $target->height, SDL_PIXELFORMAT_RGBA32);
        SDL_BlitSurface($this->surface, $rect, $drawn, null);
        $renderer = $this->makeRenderer($drawn);
        $this->draw($renderer, $picture, $corners, $place, $color, $filter, $target, $target->x, $target->y);
        SDL_DestroyRenderer($renderer);
        SDL_SetSurfaceBlendMode($drawn, SDL_BLENDMODE_NONE);
        SDL_BlitSurface($drawn, null, $this->surface, $rect);
        SDL_DestroySurface($drawn);
    }

    /**
     * An image through coverage, in SDL blends. The image is drawn into $drawn, a copy of the
     * destination's rect (so $drawn is what the rect would be with the image on it); $drawn is scaled
     * by the coverage, as grey, with SDL_BLENDMODE_MUL; that is added (SDL_BLENDMODE_ADD_PREMULTIPLIED,
     * which keeps the destination's alpha) onto $layer, a surface holding the coverage as alpha;
     * and $layer blends onto the surface with SDL_BLENDMODE_BLEND_PREMULTIPLIED: layer + dst × (1 - coverage).
     * A pixel no span holds has coverage 0 and is left as it is.
     *
     * @param  list<array{float, float}>  $corners
     * @param  list<float>  $color
     */
    private function drawThrough(string $coverage, SDL_Surface $picture, array $corners, \Closure $place, array $color, Filter $filter, Region $target): void
    {
        [$list] = SpanList::read($coverage, 0xFFFFFFFF, $this->width, $this->height);
        $drawn = SDL_CreateSurface($target->width, $target->height, SDL_PIXELFORMAT_RGBA32);
        $grey = SDL_CreateSurface($target->width, $target->height, SDL_PIXELFORMAT_RGBA32);
        $layer = SDL_CreateSurface($target->width, $target->height, SDL_PIXELFORMAT_RGBA32);
        SDL_FillSurfaceRect($grey, null, 0xFF000000);
        foreach ($list as [$y, $x, $length, $span]) {
            $cut = (new Region($x, $y, $length, 1))->intersect($target);
            if (is_null($cut) || $span === 0) {
                continue;
            }
            $rect = new SDL_Rect($cut->x - $target->x, $cut->y - $target->y, $cut->width, 1);
            SDL_FillSurfaceRect($grey, $rect, 0xFF000000 | ($span << 16) | ($span << 8) | $span);
            SDL_FillSurfaceRect($layer, $rect, $span << 24);
        }
        SDL_SetSurfaceBlendMode($this->surface, SDL_BLENDMODE_NONE);
        SDL_BlitSurface($this->surface, new SDL_Rect($target->x, $target->y, $target->width, $target->height), $drawn, null);
        $renderer = $this->makeRenderer($drawn);
        $this->draw($renderer, $picture, $corners, $place, $color, $filter, new Region($target->x, $target->y, $target->width, $target->height), $target->x, $target->y);
        SDL_DestroyRenderer($renderer);
        SDL_SetSurfaceBlendMode($grey, SDL_BLENDMODE_MUL);
        SDL_BlitSurface($grey, null, $drawn, null);
        SDL_SetSurfaceBlendMode($drawn, SDL_BLENDMODE_ADD_PREMULTIPLIED);
        SDL_BlitSurface($drawn, null, $layer, null);
        SDL_SetSurfaceBlendMode($layer, SDL_BLENDMODE_BLEND_PREMULTIPLIED);
        SDL_BlitSurface($layer, null, $this->surface, new SDL_Rect($target->x, $target->y, $target->width, $target->height));
        SDL_SetSurfaceBlendMode($this->surface, SDL_BLENDMODE_NONE);
        foreach ([$layer, $grey, $drawn] as $scratch) {
            SDL_DestroySurface($scratch);
        }
    }

    private function makeRenderer(SDL_Surface $surface): SDL_Renderer
    {
        return SDL_CreateSoftwareRenderer($surface)
            ?? throw new FramebufferException('SDL could not make a software renderer: '.SDL_GetError());
    }

    /** A pixel value of the format (0xRRGGBBAA, 0xRRGGBB, or a 565 word) as the format's bytes. */
    private function encode(int $value): string
    {
        [$r, $g, $b, $a] = [($value >> 24) & 0xFF, ($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF];

        return match ($this->code) {
            SDL_PIXELFORMAT_RGBA32 => pack('C4', $r, $g, $b, $a),
            SDL_PIXELFORMAT_BGRA32 => pack('C4', $b, $g, $r, $a),
            SDL_PIXELFORMAT_ARGB32 => pack('C4', $a, $r, $g, $b),
            SDL_PIXELFORMAT_ABGR32 => pack('C4', $a, $b, $g, $r),
            SDL_PIXELFORMAT_RGB24 => pack('C3', ($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF),
            SDL_PIXELFORMAT_BGR24 => pack('C3', $value & 0xFF, ($value >> 8) & 0xFF, ($value >> 16) & 0xFF),
            SDL_PIXELFORMAT_RGB565 => pack('v', $value & 0xFFFF),
            SDL_PIXELFORMAT_BGR565 => pack('v', (($value & 0x1F) << 11) | ($value & 0x7E0) | (($value >> 11) & 0x1F)),
        };
    }

    /** The format's bytes of one pixel as its value: encode() undone. */
    private function decode(string $bytes): int
    {
        $c = array_values(unpack('C*', $bytes));

        return match ($this->code) {
            SDL_PIXELFORMAT_RGBA32 => ($c[0] << 24) | ($c[1] << 16) | ($c[2] << 8) | $c[3],
            SDL_PIXELFORMAT_BGRA32 => ($c[2] << 24) | ($c[1] << 16) | ($c[0] << 8) | $c[3],
            SDL_PIXELFORMAT_ARGB32 => ($c[1] << 24) | ($c[2] << 16) | ($c[3] << 8) | $c[0],
            SDL_PIXELFORMAT_ABGR32 => ($c[3] << 24) | ($c[2] << 16) | ($c[1] << 8) | $c[0],
            SDL_PIXELFORMAT_RGB24 => ($c[0] << 16) | ($c[1] << 8) | $c[2],
            SDL_PIXELFORMAT_BGR24 => ($c[2] << 16) | ($c[1] << 8) | $c[0],
            SDL_PIXELFORMAT_RGB565 => $c[0] | ($c[1] << 8),
            SDL_PIXELFORMAT_BGR565 => (static fn (int $w): int => (($w & 0x1F) << 11) | ($w & 0x7E0) | (($w >> 11) & 0x1F))($c[0] | ($c[1] << 8)),
        };
    }

    /** One cell over a rect: SDL_FillSurfaceRect takes the word whose bytes, on a little-endian host, are the cell's. */
    private function paintCell(Region $region, string $cell): void
    {
        SDL_FillSurfaceRect($this->surface, new SDL_Rect($region->x, $region->y, $region->width, $region->height), unpack('V', str_pad($cell, 4, "\0"))[1]);
    }

    /** A rect's bytes in $spec: as they are when it is this format, else SDL's blit into $spec's SDL format. */
    private function in(Region $region, FormatSpec $spec): string
    {
        if ($spec->equals($this->format)) {
            return $this->read($region);
        }
        $code = SDL3Formats::code($spec)
            ?? throw FramebufferException::unsupportedFormat($spec, 'SDL has no format with this byte layout to convert into.');

        return $this->convert($region, $code);
    }

    /** A rect through SDL's blit into an SDL format, rows packed. */
    private function convert(Region $region, int $code): string
    {
        $row = $region->width * SDL3Formats::bytesPerPixel($code);
        $out = str_repeat("\0", $row * $region->height);
        $target = SDL_CreateSurfaceFrom($region->width, $region->height, $code, $out, $row);
        SDL_BlitSurface($this->surface, new SDL_Rect($region->x, $region->y, $region->width, $region->height), $target, null);
        SDL_DestroySurface($target);

        return $out;
    }

    /** A rect's bytes in this format, rows packed, top row first. */
    private function read(Region $region): string
    {
        if ($region->x === 0 && $region->width === $this->width) {
            return $region->height === $this->height
                ? $this->snapshot()
                : substr($this->bits, $region->y * $this->pitch, $region->height * $this->pitch);
        }
        $row = $region->width * $this->bytes_per_pixel;
        $rows = [];
        for ($y = $region->y; $y < $region->bottom(); $y++) {
            $rows[] = substr($this->bits, $y * $this->pitch + $region->x * $this->bytes_per_pixel, $row);
        }

        return implode('', $rows);
    }

    /** The coverage byte to RGBA pixel table of a colour: its RGB, its alpha scaled by the coverage. */
    private function ramp(int $rgba8): array
    {
        if (! isset(self::$ramps[$rgba8])) {
            if (count(self::$ramps) >= 16) {
                self::$ramps = [];
            }
            $rgb = pack('C3', ($rgba8 >> 24) & 0xFF, ($rgba8 >> 16) & 0xFF, ($rgba8 >> 8) & 0xFF);
            $alpha = $rgba8 & 0xFF;
            $table = [];
            for ($c = 0; $c < 256; $c++) {
                $table[chr($c)] = $rgb.chr(intdiv($alpha * $c + 127, 255));
            }
            self::$ramps[$rgba8] = $table;
        }

        return self::$ramps[$rgba8];
    }

    /** A surface over $bits (kept alive until it is destroyed), blend NONE: as a blit source it copies. */
    private function over(int $code, int $width, int $height, string $bits, int $pitch): SDL_Surface
    {
        $surface = SDL_CreateSurfaceFrom($width, $height, $code, $bits, $pitch)
            ?? throw new FramebufferException("SDL could not wrap a {$width}x{$height} surface: ".SDL_GetError());
        SDL_SetSurfaceBlendMode($surface, SDL_BLENDMODE_NONE);

        return $surface;
    }

    /** This surface over $bits, blend NONE. */
    private function wrap(string $bits): void
    {
        $this->bits = $bits;
        $this->surface = $this->over($this->code, $this->width, $this->height, $this->bits, $this->pitch);
    }

    /** A copy of every pixel byte, never the live string SDL writes into. */
    private function snapshot(): string
    {
        $copy = $this->bits;
        $copy[0] = $copy[0];

        return $copy;
    }

    private function whole(): Region
    {
        return Region::wholeSurface($this->width, $this->height);
    }

    /** @throws FramebufferException When $region is empty or reaches outside the surface. */
    private function inside(Region $region): void
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width || $region->bottom() > $this->height) {
            throw FramebufferException::outsideSurface($region, $this->width, $this->height);
        }
    }

    /**
     * The whole list checked before anything is written, and its bounding box.
     *
     * @param  array<int, array<int, int>>  $pixels
     */
    private function bounds(array $pixels, int $arity, string $shape): ?Region
    {
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach (array_values($pixels) as $position => $pixel) {
            if (! is_array($pixel) || count($pixel) !== $arity || ! is_int($pixel[0] ?? null) || ! is_int($pixel[1] ?? null) || ($arity === 3 && ! is_int($pixel[2] ?? null))) {
                throw new FramebufferException("Pixel {$position} is not {$shape}.");
            }
            $this->guard($pixel[0], $pixel[1]);
            $left = min($left, $pixel[0]);
            $top = min($top, $pixel[1]);
            $right = max($right, $pixel[0]);
            $bottom = max($bottom, $pixel[1]);
        }

        return $pixels === [] ? null : new Region($left, $top, $right - $left + 1, $bottom - $top + 1);
    }
}
