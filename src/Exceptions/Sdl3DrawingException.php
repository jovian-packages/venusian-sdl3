<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Exceptions;

use Surface\Contracts\Drawing\DrawingException;

final class Sdl3DrawingException extends DrawingException
{
    /** An ext-sdl3 creator's RuntimeException at this package's boundary; a DrawingException passes through. */
    public static function from(\RuntimeException $e): DrawingException
    {
        return $e instanceof DrawingException ? $e : new self($e->getMessage(), 0, $e);
    }

    public static function noGpuDriver(string $reason): self
    {
        return new self("SDL_GPU has no driver for MSL or SPIR-V here: {$reason}");
    }

    public static function claimFailed(string $reason): self
    {
        return new self("SDL_GPU could not claim the stage window: {$reason}");
    }

    public static function outOfFrame(string $operation): self
    {
        return new self("{$operation} is only legal between beginFrame() and endFrame().");
    }

    public static function unknownTexture(int $id): self
    {
        return new self("Texture {$id} is not held by this executor.");
    }

    public static function textureBytes(int $width, int $height, int $got): self
    {
        return new self("A {$width}×{$height} RGBA8 texture needs ".($width * $height * 4)." bytes; got {$got}.");
    }

    public static function spirv(string $name): self
    {
        return new self("{$name} is not a SPIR-V module.");
    }

    public static function released(): self
    {
        return new self('This executor has been released.');
    }

    public static function frameOpen(): self
    {
        return new self('beginFrame() while a frame is open: endFrame() first.');
    }

    public static function submitFailed(string $reason): self
    {
        return new self("SDL_GPU could not submit the frame: {$reason}");
    }
}
