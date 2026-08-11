<?php

namespace XQL\Core\Types;

enum XQLHookType: int
{
    case UPDATE = 1;
    case INSERT = 2;
    case DELETE = 3;
}
