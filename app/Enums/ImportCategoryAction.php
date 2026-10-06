<?php

namespace App\Enums;

/**
 * What a full import does with one category found in the file: reuse one the
 * user already has, or create it.
 */
enum ImportCategoryAction: string
{
    case Match = 'match';
    case Create = 'create';
}
