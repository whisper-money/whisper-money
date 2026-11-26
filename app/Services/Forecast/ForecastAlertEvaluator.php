<?php

namespace App\Services\Forecast;

use App\Enums\ForecastAlertLevel;

/**
 * Reads the projected months of one account for the forecast's warnings. A
 * month is a strong problem when its lowest balance falls below the account's
 * wanted minimum (zero when it has none), and a soft one when only the
 * pessimistic minimum does, that is, only if the estimated spend comes in at
 * the top of its band. The strong month reported is the lowest one; `level`
 * is `ok` when there is no problem at all.
 */
class ForecastAlertEvaluator
{
    private const LEVEL_OK = 'ok';

    /**
     * @param  list<array<string, int|string|null>>  $months  ForecastProjector months.
     * @return array{level: string, month: string|null, min: int|null, minDate: string|null, softMonths: list<string>}
     */
    public function evaluate(array $months, ?int $minimumBalance): array
    {
        $threshold = $minimumBalance ?? 0;
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
    public function strongCount(array $months, ?int $minimumBalance): int
    {
        return count(array_filter($months, fn (array $month): bool => $this->isStrong($month, $minimumBalance ?? 0)));
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
