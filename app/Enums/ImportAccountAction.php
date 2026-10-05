<?php

namespace App\Enums;

/**
 * What a full import does with one account found in the file.
 */
enum ImportAccountAction: string
{
    case Create = 'create';
    case Map = 'map';
    case Merge = 'merge';
    case Skip = 'skip';
}
