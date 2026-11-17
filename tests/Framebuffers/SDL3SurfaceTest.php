<?php

use Jovian\Engines\Sdl3\Framebuffers\SDL3FramebufferDriver;
use Jovian\Engines\Sdl3\Framebuffers\SDL3Surface;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\Endianness;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\Spans;
use Surface\NutsAndBolts\Affine;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\EffectsFramebuffer;
use VelvetGE\Contracts\Framebuffers\GradientFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;

/*
 * An SDL surface held to lustrous-php, the reference: the same calls on both, in every format SDL
 * holds. Moves, fills and conversions move bytes, so they match exactly (565 read-outs widen by
 * SDL's bit replication, within 1); blends are SDL's arithmetic, within the surface's TOLERANCE
 * (5% of 255) of lustrous-php's where SDL lands there, and the measured distance, asserted, where
 * it does not (each says so beside it).
 */

const SDW = 37;
const SDH = 23;

dataset('sdl3 formats', [
    'rgba8' => [FormatSpec::rgba8()],
    'bgra8' => [FormatSpec::bgra8()],
    'argb' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, channel_order: ChannelOrder::ARGB)],
    'abgr' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, channel_order: ChannelOrder::ABGR)],
    'rgb888' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24)],
    'bgr888' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24, channel_order: ChannelOrder::BGR)],
    '565' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B16, endianness: Endianness::LSB)],
    '565 bgr' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B16, endianness: Endianness::LSB, channel_order: ChannelOrder::BGR)],
]);

/** @return array{SDL3Surface, FullFramebuffer} */
function sdlBoth(FormatSpec $spec, int $w = SDW, int $h = SDH, ?string $rgba8 = null): array
{
    $pair = [new SDL3Surface($spec, $w, $h), new FullFramebuffer(new PHPLandFramebufferDriver(), $spec, $w, $h)];
    if (! is_null($rgba8)) {
        foreach ($pair as $frame) {
            $frame->writeRgba8($rgba8, $w, $h);
        }
    }

    return $pair;
}

/** The largest difference between two byte strings of one length. */
function sdlFurthest(string $a, string $b): int
{
    expect(strlen($a))->toBe(strlen($b));
    $most = 0;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $most = max($most, abs(ord($a[$i]) - ord($b[$i])));
    }

    return $most;
}

function sdlValue(FormatSpec $spec): int
{
    return match ($spec->bit_depth) {
        BitDepth::B16 => mt_rand(0, 0xFFFF),
        BitDepth::B24 => mt_rand(0, 0xFFFFFF),
        default => mt_rand(0, 0xFFFFFFFF),
    };
}

/** How far a read-out may differ: 0, but 565 widens to RGBA8 by SDL's bit replication. */
function sdlReadOutSlack(FormatSpec $spec): int
{
    return $spec->bit_depth === BitDepth::B16 ? 1 : 0;
}

beforeEach(fn () => mt_srand(7));

it('is the core plus compositing and scrolling, and no effects and no gradients', function () {
    $surface = new SDL3Surface(FormatSpec::rgba8(), 4, 4);

    expect($surface)->toBeInstanceOf(InstanceThatManagesAFramebuffer::class)
        ->and($surface)->toBeInstanceOf(CompositingFramebuffer::class)
        ->and($surface)->toBeInstanceOf(ScrollingFramebuffer::class)
        ->and($surface)->not->toBeInstanceOf(EffectsFramebuffer::class)
        // SDL has no gradient operation: a module states what it does by the contracts it implements.
        ->and($surface)->not->toBeInstanceOf(GradientFramebuffer::class)
        ->and(method_exists($surface, 'paintGradient'))->toBeFalse();
});

it('refuses a format SDL has no layout for', function () {
    new SDL3Surface(new FormatSpec(PixelFormat::MONO_HORIZONTAL, BitDepth::B1), 8, 2);
})->throws(FramebufferException::class);

it('is made by the sdl3 driver', function () {
    $driver = new SDL3FramebufferDriver();

    expect($driver->driver())->toBe('sdl3')
        ->and($driver->make(FormatSpec::bgra8(), 5, 3))->toBeInstanceOf(SDL3Surface::class);
});

it('holds pixel values as lustrous-php does', function (FormatSpec $spec) {
    [$s, $l] = sdlBoth($spec);
    for ($i = 0; $i < 50; $i++) {
        [$x, $y, $v] = [mt_rand(0, SDW - 1), mt_rand(0, SDH - 1), sdlValue($spec)];
        $s->setPixel($x, $y, $v);
        $l->setPixel($x, $y, $v);
        expect($s->getPixel($x, $y))->toBe($l->getPixel($x, $y));
    }

    expect($s->dump())->toBe($l->dump());
})->with('sdl3 formats');

it('fills, writes segments and clears to the same bytes', function (FormatSpec $spec) {
    [$s, $l] = sdlBoth($spec);
    foreach ([$s, $l] as $frame) {
        mt_srand(1);
        $frame->fill(sdlValue($spec))->setSegment(30, 18, 20, 20, sdlValue($spec))->setPixels([[0, 0, sdlValue($spec)], [3, 4, sdlValue($spec)]])->setRegion([[5, 5], [6, 7]], sdlValue($spec));
    }
    expect($s->dump())->toBe($l->dump());

    $s->clear();
    $l->clear();
    expect($s->dump())->toBe($l->dump());
})->with('sdl3 formats');

it('takes RGBA8 in, and reads it out, as lustrous-php does', function (FormatSpec $spec) {
    [$s, $l] = sdlBoth($spec, rgba8: random_bytes(SDW * SDH * 4));
    $region = new Region(3, 2, 11, 9);

    expect($s->dump())->toBe($l->dump())
        ->and(sdlFurthest($s->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(sdlReadOutSlack($spec))
        ->and(sdlFurthest($s->flush(FormatSpec::bgra8()), $l->flush(FormatSpec::bgra8())))->toBeLessThanOrEqual(sdlReadOutSlack($spec))
        ->and(sdlFurthest($s->flushRegion($region, FormatSpec::rgba8()), $l->flushRegion($region, FormatSpec::rgba8())))->toBeLessThanOrEqual(sdlReadOutSlack($spec))
        ->and($s->flush($spec))->toBe($l->flush($spec));
})->with('sdl3 formats');

it('scrolls to the same bytes', function (FormatSpec $spec) {
    [$s, $l] = sdlBoth($spec, rgba8: random_bytes(SDW * SDH * 4));
    foreach ([[3, -2], [-5, 4], [0, 7]] as [$dx, $dy]) {
        $s->scroll(new Region(2, 2, 30, 18), $dx, $dy);
        $l->scroll(new Region(2, 2, 30, 18), $dx, $dy);
    }

    expect($s->dump())->toBe($l->dump());
})->with('sdl3 formats');

it('blits between surfaces, and to and from Lustrous, without changing a byte', function (FormatSpec $spec) {
    [$s, $l] = sdlBoth($spec, rgba8: random_bytes(SDW * SDH * 4));
    $copy = new SDL3Surface($spec, SDW, SDH);
    $lustrous = new FullFramebuffer(new PHPLandFramebufferDriver(), $spec, SDW, SDH);

    $s->blitTo($copy);
    $s->blitTo($lustrous);
    expect($copy->dump())->toBe($s->dump())
        ->and($lustrous->dump())->toBe($l->dump());

    $back = new SDL3Surface($spec, SDW, SDH);
    $back->blitFrom($lustrous);
    expect($back->dump())->toBe($l->dump());
})->with('sdl3 formats');

/** n bytes from mt_rand, so a case's data is the same every run; $opaque makes every fourth byte (alpha) 255. */
function sdlBytes(int $n, bool $opaque = false): string
{
    $out = '';
    for ($i = 0; $i < $n; $i++) {
        $out .= chr($opaque && $i % 4 === 3 ? 255 : mt_rand(0, 255));
    }

    return $out;
}

/** The share of bytes ($a against $b) further apart than the tolerance, in percent. */
function sdlOutside(string $a, string $b): float
{
    $outside = 0;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $outside += abs(ord($a[$i]) - ord($b[$i])) > SDL3Surface::TOLERANCE ? 1 : 0;
    }

    return 100 * $outside / strlen($a);
}

it('blends spans and masks within its tolerance of lustrous-php', function (FormatSpec $spec) {
    $worst = 0;
    for ($t = 0; $t < 30; $t++) {
        $background = sdlBytes(SDW * SDH * 4);
        $colour = mt_rand(0, 0xFFFFFFFF);

        $spans = '';
        for ($y = 0; $y < SDH; $y += mt_rand(1, 2)) {
            for ($x = mt_rand(0, 3); $x < SDW - 1; $x += mt_rand(2, 9)) {
                $length = mt_rand(1, min(6, SDW - $x));
                $spans .= Spans::pack($y, $x, $length, mt_rand(1, 255));
                $x += $length;
            }
        }
        [$s, $l] = sdlBoth($spec, rgba8: $background);
        $s->paintSpans($spans, $colour);
        $l->paintSpans($spans, $colour);
        expect(sdlFurthest($s->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(SDL3Surface::TOLERANCE);

        $masks = [[sdlBytes(10 * 6), 10, mt_rand(-3, SDW - 5), mt_rand(-3, SDH - 3)], [sdlBytes(4 * 9), 4, mt_rand(0, SDW - 4), mt_rand(0, SDH - 9)]];
        [$s, $l] = sdlBoth($spec, rgba8: $background);
        $s->paintMasks($masks, $colour, new Region(1, 1, SDW - 2, SDH - 2));
        $l->paintMasks($masks, $colour, new Region(1, 1, SDW - 2, SDH - 2));
        $worst = max($worst, sdlFurthest($s->toRgba8(), $l->toRgba8()));
    }
    // Measured: one 565 blend lands up to 9 from lustrous-php (SDL's 565 rounding), and where the two
    // masks overlap a pixel is blended twice, so the two errors add: 17 over these 30 runs. That is
    // outside 5%; it is SDL's 565, reported, not corrected toward Lustrous.
    expect($worst)->toBeLessThanOrEqual($spec->bit_depth === BitDepth::B16 ? 17 : SDL3Surface::TOLERANCE);
})->with('sdl3 formats');

/** One image painted on both frames: an opaque background (SDL's alpha handling is its own: see below) and a random 9x7 source. */
function sdlPaintImage(FormatSpec $spec, Affine $placement, Filter $filter, int $opacity, int $tint, ?array $window, bool $coverage): array
{
    $W = 48;
    $H = 40;
    $results = [0, 0.0];
    for ($t = 0; $t < 10; $t++) {
        [$s, $l] = sdlBoth($spec, $W, $H, sdlBytes($W * $H * 4, opaque: true));
        [$ss, $ls] = sdlBoth(FormatSpec::rgba8(), 9, 7, sdlBytes(9 * 7 * 4));
        $spans = null;
        if ($coverage) {
            $spans = '';
            for ($y = 0; $y < $H; $y++) {
                if ($y % 3 !== 0) {
                    $spans .= Spans::pack($y, 2 + $y % 5, 20, mt_rand(1, 255));
                }
            }
        }
        $s->paintImage($ss, $placement, $opacity, $filter, null, $tint, $window, $spans);
        $l->paintImage($ls, $placement, $opacity, $filter, null, $tint, $window, $spans);
        $results = [max($results[0], sdlFurthest($s->toRgba8(), $l->toRgba8())), max($results[1], sdlOutside($s->toRgba8(), $l->toRgba8()))];
    }

    return $results;
}

it('paints another framebuffer placed on whole pixels within its tolerance of lustrous-php', function (FormatSpec $spec, Affine $placement, int $opacity, int $tint, bool $coverage) {
    mt_srand(7);

    // Measured: 3 on 32- and 24-bit surfaces, 9 on 565 (SDL's 565 rounding).
    expect(sdlPaintImage($spec, $placement, Filter::NEAREST, $opacity, $tint, null, $coverage)[0])->toBeLessThanOrEqual(SDL3Surface::TOLERANCE);
})->with('sdl3 formats')->with([
    'moved' => [Affine::translation(11, 7), 255, 0xFFFFFFFF, false],
    'moved, translucent, tinted' => [Affine::translation(11, 7), 180, 0xFF8040C0, false],
    'moved, through coverage' => [Affine::translation(11, 7), 200, 0xFFFFFFFF, true],
]);

/*
 * Where the placement is not whole pixels, SDL's software renderer is not lustrous-php: it takes
 * integer vertex positions and samples a texture its own way, so a scaled, turned or windowed image
 * puts some pixels' source texel one over from where lustrous-php samples it, and a random source
 * (every texel unlike its neighbour) shows that as a far byte. The measured numbers, over 10 runs of
 * random 9x7 sources on opaque 48x40 backgrounds, are asserted: the largest byte distance and the
 * share of bytes outside the tolerance, the same on every format SDL holds up to 565's own rounding.
 * SDL 3.2 samples its own way again: its numbers were measured on the Pi 5 (distro SDL 3.2.10) on
 * 2026-11-15, the 3.4 ones on macOS (3.4.x) and an x86_64 box (3.4.15). Not tuned toward Lustrous.
 */
it('paints another framebuffer as SDL samples it, at the measured distance from lustrous-php', function (FormatSpec $spec, Affine $placement, Filter $filter, int $opacity, int $tint, ?array $window, bool $coverage, array $on34, array $on32) {
    [$furthest, $outside] = SDL_GetVersion() >= 3004000 ? $on34 : $on32;
    mt_srand(7);
    [$worst, $share] = sdlPaintImage($spec, $placement, $filter, $opacity, $tint, $window, $coverage);

    expect($worst)->toBeLessThanOrEqual($furthest)
        ->and($share)->toBeLessThanOrEqual($outside);
})->with('sdl3 formats')->with([
    'scaled, linear, translucent, tinted' => [Affine::translation(4.3, 3.1)->multiply(Affine::scaling(1.7, 1.3)), Filter::LINEAR, 180, 0xFF8040C0, null, false, [91, 1.0], [90, 0.9]],
    'turned, nearest' => [Affine::translation(24, 6)->multiply(Affine::rotation(M_PI / 6))->multiply(Affine::scaling(2, 2)), Filter::NEAREST, 255, 0xFFFFFFFF, null, false, [239, 2.0], [239, 6.1]],
    'turned, windowed' => [Affine::translation(30, 4)->multiply(Affine::rotation(1.3))->multiply(Affine::scaling(2.5, 1.5)), Filter::LINEAR, 230, 0xFFFFFFFF, [1.5, 1.0, 7.2, 5.5], false, [181, 3.0], [190, 3.4]],
    'scaled, through coverage' => [Affine::translation(3.25, 2.25)->multiply(Affine::scaling(3, 2.5)), Filter::NEAREST, 200, 0xFFFFFFFF, null, true, [148, 2.0], [148, 1.6]],
]);

it('paints a rotated image larger than SDL\'s software triangle blit takes in one triangle', function () {
    // SDL refuses a rotated 1024x768 triangle ("triangle area overflow") and draws nothing: the quad is drawn in cells.
    [$W, $H] = [1100, 800];
    $background = str_repeat("\x10\x20\x30\xff", $W * $H);
    $source = '';
    for ($y = 0; $y < 768; $y++) {
        $source .= str_repeat(pack('C4', $y & 0xFF, 255 - ($y & 0xFF), 128, 255), 1024);
    }
    [$s, $l] = sdlBoth(FormatSpec::rgba8(), $W, $H, $background);
    [$ss, $ls] = sdlBoth(FormatSpec::rgba8(), 1024, 768, $source);
    $placement = Affine::translation(40, 5)->multiply(Affine::rotation(0.1));
    $s->paintImage($ss, $placement, 255, Filter::NEAREST);
    $l->paintImage($ls, $placement, 255, Filter::NEAREST);

    // The source's pixels (y, 255 - y, 128, 255) never spell the background's four bytes, so counting them counts the untouched pixels.
    $painted = fn (string $rgba8): int => $W * $H - substr_count($rgba8, "\x10\x20\x30\xff");
    $theirs = $painted($l->toRgba8());

    // SDL snaps the quad to whole pixels, so its footprint is within a row or two of Lustrous's edge, not identical.
    expect(abs($painted($s->toRgba8()) - $theirs))->toBeLessThan($theirs / 100);
});

/*
 * The destination's alpha. SDL's textured triangles, drawn with no vertex alpha scaling, leave 255 in
 * a destination pixel wherever the source has any alpha (measured: a source of alpha 128 over a
 * destination of alpha 128 reads 255; lustrous-php's source-over reads 192). Colour is blended
 * right. With the alpha scaled (an opacity or a tint's alpha below 255) SDL blends the alpha as
 * source-over, within 2. SDL's, asserted, not corrected: a translucent destination is the one place
 * a plain paintImage() differs in alpha.
 */
it('leaves 255 in the alpha of a destination an unscaled image lands on, and blends it as source-over when scaled', function () {
    $alphaOf = function (int $opacity): array {
        $sdl = new SDL3Surface(FormatSpec::rgba8(), 1, 1);
        $lustrous = new FullFramebuffer(new PHPLandFramebufferDriver(), FormatSpec::rgba8(), 1, 1);
        foreach ([$sdl, $lustrous] as $frame) {
            $frame->writeRgba8(pack('C4', 10, 20, 30, 128), 1, 1);
            $image = $frame instanceof SDL3Surface ? new SDL3Surface(FormatSpec::rgba8(), 1, 1) : new FullFramebuffer(new PHPLandFramebufferDriver(), FormatSpec::rgba8(), 1, 1);
            $image->writeRgba8(pack('C4', 200, 100, 50, 128), 1, 1);
            $frame->paintImage($image, Affine::identity(), $opacity);
        }

        return [ord($sdl->toRgba8()[3]), ord($lustrous->toRgba8()[3])];
    };

    // SDL 3.4 leaves the destination's alpha at 255 for an unscaled image; SDL 3.2 blends it source-over as Lustrous
    // does, to within rounding (191 against 192), measured on the Pi 5's 3.2.10.
    expect($alphaOf(255))->toBe(SDL_GetVersion() >= 3004000 ? [255, 192] : [191, 192])
        ->and(abs($alphaOf(254)[0] - $alphaOf(254)[1]))->toBeLessThanOrEqual(2);
});

it('offers its pixels as memory for drawing in place, at SDL\'s own address', function () {
    $surface = new SDL3Surface(FormatSpec::bgra8(), 8, 4);
    $memory = $surface->memory();

    expect($memory)->not->toBeNull()
        ->and($surface->pointer())->not->toBe(0)
        ->and($memory->address)->toBe($surface->pointer())
        ->and($memory->width)->toBe(8)
        ->and($memory->height)->toBe(4)
        ->and($memory->stride)->toBe(32)
        ->and($surface->drewInto(new Region(0, 0, 2, 2), null))->toBe($surface);

    // The address is the surface's pixels: bytes written through SDL show in it.
    $surface->fill(0x11223344);
    expect($surface->dump())->toBe(str_repeat("\x33\x22\x11\x44", 8 * 4));
});

// Library to library is a pointer: C sources are read where they are (venusian-velvet/tests/Support).
moduleTicketChecks('sdl3', fn (FormatSpec $f, int $w, int $h) => new SDL3Surface($f, $w, $h), FormatSpec::bgra8(), [
    'rgba8' => FormatSpec::rgba8(),
    'bgra8' => FormatSpec::bgra8(),
    'rgb888' => new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24),
]);
