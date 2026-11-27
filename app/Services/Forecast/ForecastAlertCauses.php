<?php

namespace App\Services\Forecast;

use App\Enums\ForecastFrequency;
use App\Services\Forecast\Data\ForecastPlan;
use App\Services\Forecast\Data\Occurrence;

/**
 * Why a month runs short: its two largest charges on the account that are
 * not monthly. They are what the user already knows is coming, which explains
 * the dip. The usual transfers between the user's accounts (TransferEstimator)
 * are monthly too, so they never are one. The forecast page's warning and the
 * dashboard's card both say them.
 */
class ForecastAlertCauses
{
    private const LIMIT = 2;

    /**
     * @param  string|null  $month  `Y-m`; null has no causes.
     * @return list<array{name: string, amount: int}> Amounts positive, in the account's minor units.
     */
    public function for(ForecastPlan $plan, string $accountId, ?string $month): array
    {
        if ($month === null) {
            return [];
        }

        $items = $plan->items->keyBy('id');

        return collect($plan->input->occurrences)
            ->filter(fn (Occurrence $occurrence): bool => $occurrence->accountId === $accountId
                && $occurrence->amount < 0
                && ForecastCalendar::monthKey($occurrence->date) === $month
                && $items->has($occurrence->itemId)
                && $items->get($occurrence->itemId)->frequency !== ForecastFrequency::Monthly)
            ->sortBy('amount')
            ->take(self::LIMIT)
            ->map(fn (Occurrence $occurrence): array => ['name' => (string) $items->get($occurrence->itemId)?->name, 'amount' => -$occurrence->amount])
            ->values()
            ->all();
    }
}
