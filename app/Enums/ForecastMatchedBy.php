<?php

namespace App\Enums;

/**
 * Who paired a transaction with a forecast occurrence.
 */
enum ForecastMatchedBy: string
{
    case Auto = 'auto';
    case User = 'user';
}
