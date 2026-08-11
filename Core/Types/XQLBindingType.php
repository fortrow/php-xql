<?php

namespace XQL\Core\Types;

enum XQLBindingType: int
{
    case DB_TO_FILE = 1;
    case FILE_TO_DB = 2;
    case FILE_TO_FILE = 3;
    case FUNCTION = 4;
}