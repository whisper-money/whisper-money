<?php

namespace App\Services\Forecast;

use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Services\Forecast\Data\DriftSuggestion;
use Illuminate\Database\Eloquent\Collection;

/**
 * A confirmed line never changes on its own. When its last two payments both
 * deviate beyond the line's tolerance in the same direction, the forecast
 * suggests updating it to what was actually paid. Only payments of the
 * occurrences since the latest version count, so once the user updates the
 * amount the suggestion goes away until it drifts again. A transfer is read
 * from its sending leg. Computed on the fly: nothing is stored.
 *
 * The edit flow of a later phase reads it; until then only the tests do.
 *
 * @api
 */
class ForecastDrift
{
    private const CONSECUTIVE_PAYMENTS = 2;

    /**
     * @param  Collection<int, ForecastItem>  $items
     * @return array<string, DriftSuggestion> By line id.
     */
    public function suggestions(Collection $items): array
    {
        $items->loadMissing('versions');
        $matches = ForecastMatch::query()
            ->whereIn('forecast_item_id', $items->modelKeys())
            ->whereHas('transaction')
            ->with('transaction:id,account_id,amount')
            ->orderByDesc('occurrence_date')
            ->get()
            ->groupBy('forecast_item_id');

        $suggestions = [];

        foreach ($items as $item) {
            $suggestion = $this->evaluate($item, $matches->get($item->id) ?? new Collection);

            if ($suggestion !== null) {
                $suggestions[$item->id] = $suggestion;
            }
        }

        return $suggestions;
    }

    public function suggestionFor(ForecastItem $item): ?DriftSuggestion
    {
        return $this->suggestions(new Collection([$item]))[$item->id] ?? null;
    }

    /**
     * @param  Collection<int, ForecastMatch>  $matches  The line's, newest first.
     */
    private function evaluate(ForecastItem $item, Collection $matches): ?DriftSuggestion
    {
        $currentFrom = $item->versions->max(fn ($version) => $version->effective_from->toDateString());

        $latest = $matches
            ->filter(fn (ForecastMatch $match): bool => $match->transaction->account_id === $item->account_id
                && ($currentFrom === null || $match->occurrence_date->toDateString() >= $currentFrom))
            ->take(self::CONSECUTIVE_PAYMENTS)
            ->values();

        if ($latest->count() < self::CONSECUTIVE_PAYMENTS || ! $this->driftsTogether($item, $latest)) {
            return null;
        }

        return new DriftSuggestion($item->id, $latest->first()->transaction->amount, $latest->last()->occurrence_date->toDateString());
    }

    /**
     * @param  Collection<int, ForecastMatch>  $matches
     */
    private function driftsTogether(ForecastItem $item, Collection $matches): bool
    {
        $beyond = $matches->every(fn (ForecastMatch $match): bool => abs($match->deviation) > $item->amount_tolerance);
        $signs = $matches->map(fn (ForecastMatch $match): int => $match->deviation <=> 0)->unique();

        return $beyond && $signs->count() === 1;
    }
}
