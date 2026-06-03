<?php

namespace App\Enums;

enum CategoryDeletionStrategy: string
{
    case Reparent = 'reparent';
    case Promote = 'promote';
    case Cascade = 'cascade';
}
