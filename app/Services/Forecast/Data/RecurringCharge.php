<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastFrequency;

/**
 * One charge that repeats, as the "What you pay every month" card lists it.
 * `amount` is the median of its last three charges, positive, in the
 * account's minor units; `monthly` and `yearly` are what it costs a month
 * and a year at its frequency. `categoryId` is the parent category its
 * charges carry most often. `key` names it across runs: its account and
 * detector identity (see ForecastCandidate::identity()), hashed.
 */
final readonly class RecurringCharge
{
    public function __construct(
        public string $key,
        public string $name,
        public string $accountId,
        public string $currencyCode,
        public ?string $categoryId,
        public ForecastFrequency $frequency,
        public int $amount,
        public int $monthly,
        public int $yearly,
        public string $lastChargedOn,
    ) {}

    public static function keyOf(string $accountId, string $identity): string
    {
        return sha1($accountId.'|'.$identity);
    }
}
