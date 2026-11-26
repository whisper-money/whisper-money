<?php

namespace App\Enums;

/**
 * Why a transaction is left out of the estimated spend.
 */
enum ForecastExclusionReason: string
{
    case OneOff = 'one_off';
}
