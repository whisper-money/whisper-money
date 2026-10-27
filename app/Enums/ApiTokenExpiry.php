<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How long a personal API token lives, picked when it is created. Stored as
 * Sanctum's `expires_at`; `Never` leaves it null.
 */
enum ApiTokenExpiry: string
{
    case ThirtyDays = '30_days';
    case NinetyDays = '90_days';
    case OneYear = '1_year';
    case Never = 'never';

    public const self Default = self::NinetyDays;

    public function expiresAt(): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();

        return match ($this) {
            self::ThirtyDays => $now->addDays(30),
            self::NinetyDays => $now->addDays(90),
            self::OneYear => $now->addYear(),
            self::Never => null,
        };
    }
}
