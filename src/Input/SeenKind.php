<?php

namespace Jovian\Engines\Sdl3\Input;

/** The SDL events the sdl3 input engine reads. */
enum SeenKind
{
    case Key;
    case Text;
    case Motion;
    case Button;
    case Wheel;
    case FocusLost;
    case MouseLeave;
}
