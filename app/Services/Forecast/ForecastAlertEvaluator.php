<?php

namespace App\Services\Forecast;

use App\Enums\AccountType;
use App\Enums\ForecastAlertLevel;

/**
 * Reads the projected months of one account for the forecast's warnings. A
 * month is a strong problem when its lowest balance falls below the
 * account's threshold (see threshold()), and a soft one when only the
 * pessimistic minimum does, that is, only if the estimated spend comes in at
 * the top of its band. The strong month reported is the lowest one; `level`
 * is `ok` when there is no problem at all, or no threshold to fall below.
 */
class ForecastAlertEvaluator
{
    private const LEVEL_OK = 'ok';

    /**
     * The balance an account must stay above: the minimum its owner wants,
     * else zero. A credit card or a loan lives below zero, so without a
     * minimum it has none.
     */
    public function threshold(AccountType $type, ?int $minimumBalance): ?int
    {
        if ($minimumBalance !== null) {
            return $minimumBalance;
        }

        return in_array($type, [AccountType::CreditCard, AccountType::Loan], true) ? null : 0;
    }

    /**
     * @param  list<array<string, int|string|null>>  $months  ForecastProjector months.
     * @return array{level: string, month: string|null, min: int|null, minDate: string|null, softMonths: list<string>}
     */
    public function evaluate(array $months, ?int $threshold): array
    {
        $months = $threshold === null ? [] : $months;
        $strong = collect($months)->filter(fn (array $month): bool => $this->isStrong($month, $threshold))->sortBy('min')->first();
        $softMonths = collect($months)
            ->filter(fn (array $month): bool => ! $this->isStrong($month, $threshold) && $month['pessimisticMin'] < $threshold)
            ->map(fn (array $month): string => (string) $month['month'])
            ->values()
            ->all();

        return [
            'level' => $this->level($strong !== null, $softMonths !== []),
            'month' => $strong['month'] ?? null,
            'min' => $strong['min'] ?? null,
            'minDate' => $strong['minDate'] ?? null,
            'softMonths' => $softMonths,
        ];
    }

    /**
     * How many of the months are a strong problem.
     *
     * @param  list<array<string, int|string|null>>  $months
     */
    public function strongCount(array $months, ?int $threshold): int
    {
        return $threshold === null ? 0 : count(array_filter($months, fn (array $month): bool => $this->isStrong($month, $threshold)));
    }

    /**
     * @param  array<string, int|string|null>  $month
     */
    private function isStrong(array $month, int $threshold): bool
    {
        return $month['min'] < $threshold;
    }

    private function level(bool $strong, bool $soft): string
    {
        if ($strong) {
            return ForecastAlertLevel::Strong->value;
        }

        return $soft ? ForecastAlertLevel::Soft->value : self::LEVEL_OK;
    }
}
