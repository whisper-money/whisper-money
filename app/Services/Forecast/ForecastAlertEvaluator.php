<?php

namespace App\Services\Forecast;

/**
 * Reads the projected months of one account for the forecast's warnings. A
 * month is a strong problem when its lowest balance falls below the account's
 * wanted minimum (zero when it has none), and a soft one when only the
 * pessimistic minimum does, that is, only if the estimated spend comes in at
 * the top of its band. The strong month reported is the lowest one.
 */
class ForecastAlertEvaluator
{
    public const LEVEL_STRONG = 'strong';

    private const LEVEL_SOFT = 'soft';

    private const LEVEL_OK = 'ok';

    /**
     * @param  list<array<string, int|string|null>>  $months  ForecastProjector months.
     * @return array{level: string, month: string|null, min: int|null, minDate: string|null, softMonths: list<string>}
     */
    public function evaluate(array $months, ?int $minimumBalance): array
    {
        $threshold = $minimumBalance ?? 0;
        $strong = $this->lowest(array_filter($months, fn (array $month): bool => $month['min'] < $threshold));
        $softMonths = array_values(array_map(
            fn (array $month): string => (string) $month['month'],
            array_filter($months, fn (array $month): bool => $month['min'] >= $threshold && $month['pessimisticMin'] < $threshold),
        ));

        return [
            'level' => $this->level($strong, $softMonths),
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
        $threshold = $minimumBalance ?? 0;

        return count(array_filter($months, fn (array $month): bool => $month['min'] < $threshold));
    }

    /**
     * @param  array<int, array<string, int|string|null>>  $months
     * @return array<string, int|string|null>|null
     */
    private function lowest(array $months): ?array
    {
        $lowest = null;

        foreach ($months as $month) {
            if ($lowest === null || $month['min'] < $lowest['min']) {
                $lowest = $month;
            }
        }

        return $lowest;
    }

    /**
     * @param  array<string, int|string|null>|null  $strong
     * @param  list<string>  $softMonths
     */
    private function level(?array $strong, array $softMonths): string
    {
        if ($strong !== null) {
            return self::LEVEL_STRONG;
        }

        return $softMonths === [] ? self::LEVEL_OK : self::LEVEL_SOFT;
    }
}
