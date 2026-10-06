<?php

namespace App\Enums;

/**
 * What a full import does with what the space already holds: add to it, or
 * wipe the manual accounts first.
 */
enum ImportMode: string
{
    case Add = 'add';
    case Wipe = 'wipe';
}
