<?php

namespace App\Enums;

enum TransactionSource: string
{
    case ManuallyCreated = 'manually_created';
    case Imported = 'imported';
}
