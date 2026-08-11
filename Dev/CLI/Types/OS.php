<?php

namespace App\XQL\Dev\CLI\Types;

enum OS: int
{
    case WINDOWS = 1;
    case MACOS = 2;
    case DEBIAN = 3;
    case FEDORA = 4;
}
