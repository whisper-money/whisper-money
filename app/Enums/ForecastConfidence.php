<?php

namespace App\Enums;

/**
 * How sure the forecast is about a line: `review` asks the user to check it.
 */
enum ForecastConfidence: string
{
    case High = 'high';
    case Review = 'review';
}
