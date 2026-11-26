<?php

namespace App\Enums;

/**
 * A strong alert: the expected minimum balance drops below zero (or below the
 * account's desired minimum). A soft one: only the pessimistic case does.
 */
enum ForecastAlertLevel: string
{
    case Strong = 'strong';
    case Soft = 'soft';
}
