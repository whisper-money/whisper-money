<?php

namespace App\Enums;

/**
 * Whether a forecast line repeats on a schedule or happens once.
 */
enum ForecastItemKind: string
{
    case Recurring = 'recurring';
    case OneOff = 'one_off';
}
