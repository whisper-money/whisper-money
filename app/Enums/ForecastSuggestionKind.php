<?php

namespace App\Enums;

/**
 * The kind of forecast suggestion a user dismissed, so it is not proposed
 * again. `Kept` is a recurring charge the user said they keep on the "What
 * you pay every month" card, so it stops asking; it never hides a suggestion.
 */
enum ForecastSuggestionKind: string
{
    case Recurring = 'recurring';
    case Annual = 'annual';
    case Transfer = 'transfer';
    case Spike = 'spike';
    case Kept = 'kept';
}
