<?php

declare(strict_types=1);

namespace Jovian\Venusian\Sdl3\Enums;

/** SDL_GLProfile bits (SDL_video.h), for SDL_GL_CONTEXT_PROFILE_MASK. jovian/sdl3 does not mine them: no projected prototype takes the type. */
enum GLProfile: int
{
    case CORE = 1;  // SDL_GL_CONTEXT_PROFILE_CORE
    case ES = 4;    // SDL_GL_CONTEXT_PROFILE_ES
}
