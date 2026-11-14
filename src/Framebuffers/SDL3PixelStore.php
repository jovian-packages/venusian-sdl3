<?php

namespace Jovian\Engines\Sdl3\Framebuffers;

use SDL_Rect;
use SDL_Surface;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\PixelStore;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\VelvetGE\Framebuffers\Layout;
use Surface\VelvetGE\Framebuffers\PHPLand\Packings\Packing;
use Surface\VelvetGE\Framebuffers\PHPLand\PHPLandPixelStore;

/**
 * The core PixelStore tier with the pixels in an SDL surface. The surface wraps a PHP string
 * (SDL_CreateSurfaceFrom keeps it alive), whose bytes are VelvetGE's bytes for the format word
 * for word (SDL3Formats picks an SDL format with the same memory layout), rows packed with no
 * padding. SDL writes into that string; reads take copies of it.
 *
 * Writes go through SDL: fills are SDL_FillSurfaceRect, copies and loads SDL_BlitSurface with
 * SDL_BLENDMODE_NONE on the source, a straight copy, exact. Where a value has to pass through
 * VelvetGE's arithmetic (a word's byte encoding, a conversion to another FormatSpec, 565 to
 * RGBA8, the plane of a word), the store lends the bytes to a PHPLandPixelStore and asks it,
 * so the answer is VelvetGE's by construction.
 *
 * No painting or effects tier: Topside's CompletingPixelStore runs those on VelvetGE's
 * arithmetic, so every byte this store holds matches VelvetGE's.
 */
final class SDL3PixelStore implements PixelStore
{
    private SDL_Surface $surface;

    /** The surface's pixels. Never written from PHP, never handed out uncopied: SDL writes into it. */
    private string $bits;

    private int $code;

    private int $bytes_per_pixel;

    private int $pitch;

    /** A 1x1 store of the format: VelvetGE's own encoding of one word into bytes and back. */
    private PHPLandPixelStore $codec;

    private ?DamageGranularity $granularity = null;

    /**
     * @throws FramebufferException When the format has no SDL layout (SDL3Formats::code() is null).
     */
    public function __construct(
        private readonly FormatSpec $format,
        private readonly int $width,
        private readonly int $height,
    ) {
        Layout::size($width, $height);
        $this->codec = new PHPLandPixelStore($format, 1, 1);
        $code = SDL3Formats::code($format);
        if ($code === null) {
            throw FramebufferException::unsupportedFormat($format, 'SDL has no format with this byte layout.');
        }
        $this->code = $code;
        $this->bytes_per_pixel = SDL3Formats::bytesPerPixel($code);
        $this->pitch = $width * $this->bytes_per_pixel;
        $this->wrap(str_repeat("\0", $this->pitch * $height));
    }

    public function __clone()
    {
        $this->codec = clone $this->codec;
        $this->wrap($this->snapshot());
    }

    public function __destruct()
    {
        SDL_DestroySurface($this->surface);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function format(): FormatSpec
    {
        return $this->format;
    }

    public function get(int $x, int $y): int
    {
        $this->guard($x, $y);
        $this->codec->load(new Region(0, 0, 1, 1), $this->read(new Region($x, $y, 1, 1)));

        return $this->codec->get(0, 0);
    }

    public function set(int $x, int $y, int $value): void
    {
        $this->guard($x, $y);
        $this->paintCell(new Region($x, $y, 1, 1), $this->encode($value));
    }

    public function setPixels(array $pixels): ?Region
    {
        $bounds = $this->bounds($pixels, 3, '[x, y, value]');
        foreach ($pixels as [$x, $y, $value]) {
            $this->paintCell(new Region($x, $y, 1, 1), $this->encode($value));
        }

        return $bounds;
    }

    public function setCoordinates(array $coordinates, int $value): ?Region
    {
        $bounds = $this->bounds($coordinates, 2, '[x, y]');
        $cell = $this->encode($value);
        foreach ($coordinates as [$x, $y]) {
            $this->paintCell(new Region($x, $y, 1, 1), $cell);
        }

        return $bounds;
    }

    public function rect(Region $region, int $value): void
    {
        $this->inside($region);
        $this->paintCell($region, $this->encode($value));
    }

    public function fill(int $value): void
    {
        $this->paintCell(Region::wholeSurface($this->width, $this->height), $this->encode($value));
    }

    public function bytes(?int $layer = null): string
    {
        if ($layer !== null) {
            // Row-major formats have one layer: VelvetGE answers (and refuses) layers its own way.
            return $this->lent()->bytes($layer);
        }

        return $this->snapshot();
    }

    public function region(Region $region, FormatSpec $spec): string
    {
        $this->inside($region);
        $own = $this->read($region);
        if ($spec->equals($this->format)) {
            return $own;
        }
        $lent = new PHPLandPixelStore($this->format, $region->width, $region->height);
        $lent->load(Region::wholeSurface($region->width, $region->height), $own);

        return $lent->region(Region::wholeSurface($region->width, $region->height), $spec);
    }

    public function load(Region $region, string $bytes): void
    {
        $this->inside($region);
        $need = $region->width * $region->height * $this->bytes_per_pixel;
        if (strlen($bytes) !== $need) {
            throw new FramebufferException("load() takes the {$need} bytes of a {$region->width}x{$region->height} rect in this format, got ".strlen($bytes).'.');
        }
        $this->write($region, $bytes);
    }

    public function toRgba8(): string
    {
        if ($this->code === SDL_PIXELFORMAT_RGBA32) {
            return $this->snapshot();
        }
        if ($this->bytes_per_pixel >= 3) {
            // 32- and 24-bit layouts reach RGBA8 by moving bytes (24-bit gains alpha 255, as VelvetGE's does).
            $out = str_repeat("\0", $this->width * $this->height * 4);
            $target = SDL_CreateSurfaceFrom($this->width, $this->height, SDL_PIXELFORMAT_RGBA32, $out, $this->width * 4);
            SDL_BlitSurface($this->surface, null, $target, null);
            SDL_DestroySurface($target);

            return $out;
        }

        // 565 widens by round(v / 31 × 255) in VelvetGE; SDL replicates bits. VelvetGE's arithmetic answers.
        return $this->lent()->toRgba8();
    }

    public function blitRgba8(string $rgba8, int $width, int $height, int $offset_x, int $offset_y, ?Region $clip = null): ?Region
    {
        if ($width < 1 || $height < 1 || strlen($rgba8) !== $width * $height * 4) {
            throw new FramebufferException("blitRgba8() takes {$width}x{$height} RGBA8 pixels (".($width * $height * 4).' bytes), got '.strlen($rgba8).'.');
        }
        $target = (new Region($offset_x, $offset_y, $width, $height))->intersect(Region::wholeSurface($this->width, $this->height));
        if ($target !== null && $clip !== null) {
            $target = $target->intersect($clip);
        }
        if ($target === null) {
            return null;
        }
        if ($this->bytes_per_pixel < 3) {
            // RGBA8 to 565 is VelvetGE's fromRgba8(), on lent pixels.
            $lent = new PHPLandPixelStore($this->format, $target->width, $target->height);
            $lent->blitRgba8($rgba8, $width, $height, $offset_x - $target->x, $offset_y - $target->y);
            $this->write($target, $lent->bytes());

            return $target;
        }

        $source = SDL_CreateSurfaceFrom($width, $height, SDL_PIXELFORMAT_RGBA32, $rgba8, $width * 4);
        SDL_SetSurfaceBlendMode($source, SDL_BLENDMODE_NONE);
        SDL_BlitSurface($source, new SDL_Rect($target->x - $offset_x, $target->y - $offset_y, $target->width, $target->height),
            $this->surface, new SDL_Rect($target->x, $target->y, $target->width, $target->height));
        SDL_DestroySurface($source);

        return $target;
    }

    public function copy(PixelStore $source, Region $region): void
    {
        if ($source->width() !== $this->width || $source->height() !== $this->height || ! $source->format()->equals($this->format)) {
            throw new FramebufferException('copy() takes a store of the same size and format.');
        }
        $this->inside($region);
        if ($source instanceof self) {
            $rect = new SDL_Rect($region->x, $region->y, $region->width, $region->height);
            SDL_BlitSurface($source->surface, $rect, $this->surface, $rect);

            return;
        }
        $this->write($region, $source->region($region, $this->format));
    }

    public function plane(int $value): string
    {
        return $this->lent()->plane($value);
    }

    /** The address of the surface's pixels: the format's bytes row by row, no padding. */
    public function pointer(): int
    {
        return $this->surface->pixels();
    }

    /** The surface's pixels as PixelMemory: they hold exactly what bytes() answers, so a direct scanner can paint them in place. */
    public function memory(): ?PixelMemory
    {
        return new PixelMemory($this->pointer(), $this->width, $this->height, $this->pitch, $this->format, $this->surface);
    }

    /** VelvetGE's granularity for this format at this size (it depends on the size, so not the 1x1 codec's). */
    public function granularity(): DamageGranularity
    {
        return $this->granularity ??= Packing::for($this->format, $this->width, $this->height)->granularity();
    }

    /** A surface over $bits, blending off: as a blit source it copies. */
    private function wrap(string $bits): void
    {
        $this->bits = $bits;
        $this->surface = SDL_CreateSurfaceFrom($this->width, $this->height, $this->code, $this->bits, $this->pitch)
            ?? throw new FramebufferException("SDL could not wrap a {$this->width}x{$this->height} surface: ".SDL_GetError());
        SDL_SetSurfaceBlendMode($this->surface, SDL_BLENDMODE_NONE);
    }

    /** A copy of every pixel byte, never the live string SDL writes into. */
    private function snapshot(): string
    {
        $copy = $this->bits;
        $copy[0] = $copy[0];

        return $copy;
    }

    private function encode(int $value): string
    {
        $this->codec->set(0, 0, $value);

        return $this->codec->bytes();
    }

    /** A whole-surface PHPLandPixelStore holding these bytes, for the answers that are VelvetGE's arithmetic. */
    private function lent(): PHPLandPixelStore
    {
        $lent = new PHPLandPixelStore($this->format, $this->width, $this->height);
        $lent->load(Region::wholeSurface($this->width, $this->height), $this->snapshot());

        return $lent;
    }

    /** One cell over a rect: SDL_FillSurfaceRect writes the word's bytes in memory order on a little-endian host. */
    private function paintCell(Region $region, string $cell): void
    {
        SDL_FillSurfaceRect($this->surface, new SDL_Rect($region->x, $region->y, $region->width, $region->height), unpack('V', str_pad($cell, 4, "\0"))[1]);
    }

    /** A rect's bytes, rows packed, top row first. */
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

    /** Packed rows of the format's bytes into a rect. */
    private function write(Region $region, string $bytes): void
    {
        $source = SDL_CreateSurfaceFrom($region->width, $region->height, $this->code, $bytes, $region->width * $this->bytes_per_pixel);
        SDL_SetSurfaceBlendMode($source, SDL_BLENDMODE_NONE);
        SDL_BlitSurface($source, null, $this->surface, new SDL_Rect($region->x, $region->y, $region->width, $region->height));
        SDL_DestroySurface($source);
    }

    private function guard(int $x, int $y): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw FramebufferException::outOfRange($x, $y, $this->width, $this->height);
        }
    }

    private function inside(Region $region): void
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width || $region->bottom() > $this->height) {
            throw FramebufferException::outsideSurface($region, $this->width, $this->height);
        }
    }

    /**
     * The whole list checked before anything is written, and its bounding box: VelvetGE's rule and messages.
     *
     * @param  array<int, array<int, int>>  $pixels
     */
    private function bounds(array $pixels, int $arity, string $shape): ?Region
    {
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        $position = 0;
        foreach ($pixels as $pixel) {
            if (! is_array($pixel) || count($pixel) !== $arity || ! is_int($pixel[0] ?? null) || ! is_int($pixel[1] ?? null) || ($arity === 3 && ! is_int($pixel[2] ?? null))) {
                throw new FramebufferException("Pixel {$position} is not {$shape}.");
            }
            $position++;
            $this->guard($pixel[0], $pixel[1]);
            $left = min($left, $pixel[0]);
            $top = min($top, $pixel[1]);
            $right = max($right, $pixel[0]);
            $bottom = max($bottom, $pixel[1]);
        }

        return $pixels === [] ? null : new Region($left, $top, $right - $left + 1, $bottom - $top + 1);
    }
}
