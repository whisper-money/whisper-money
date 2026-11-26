<?php

namespace App\Enums;

/**
 * Where the estimated rest of a category comes from: the median of recent
 * months, a budget that fits the category, or the user's own figure.
 */
enum ForecastEstimateBasis: string
{
    case Median = 'median';
    case Budget = 'budget';
    case Adjustment = 'adjustment';
}
