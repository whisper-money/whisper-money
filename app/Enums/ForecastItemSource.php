<?php

namespace App\Enums;

/**
 * Where a forecast line came from: found in the history, typed in by the user,
 * or proposed by a suggester and still waiting to be reviewed.
 */
enum ForecastItemSource: string
{
    case Detected = 'detected';
    case Manual = 'manual';
    case Suggested = 'suggested';
}
