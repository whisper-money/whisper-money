<?php

namespace App\Enums;

/**
 * The kind of forecast suggestion a user dismissed, so it is not proposed again.
 */
enum ForecastSuggestionKind: string
{
    case Recurring = 'recurring';
    case Annual = 'annual';
    case Transfer = 'transfer';
    case Spike = 'spike';
}
