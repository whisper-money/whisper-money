<?php

namespace App\Http\Controllers\Forecast;

use App\Enums\ForecastSuggestionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\RecurringChargeRequest;
use App\Models\ForecastDismissal;
use App\Models\ForecastRecurringDrop;
use Illuminate\Http\RedirectResponse;

/**
 * "Keep" on a recurring charge, and its Undo: the card stops asking about
 * it. Remembered as a dismissal of its own kind (ForecastSuggestionKind::Kept)
 * keyed by the charge key, so it never hides a suggestion of the review.
 */
class ForecastRecurringKeepController extends Controller
{
    /**
     * A charge the user is dropping is not kept as well.
     */
    public function store(RecurringChargeRequest $request): RedirectResponse
    {
        $key = $request->charge()->key;
        $isDropped = ForecastRecurringDrop::query()->where('user_id', $request->user()->id)->forSpace($request->user()->activeSpace()->id)->where('charge_key', $key)->exists();

        if (! $isDropped) {
            ForecastDismissal::query()->firstOrCreate($this->dismissal($request, $key));
        }

        return back();
    }

    /**
     * Forgetting works by the key alone, so a kept charge that stopped
     * repeating can still be let go.
     */
    public function destroy(RecurringChargeRequest $request): RedirectResponse
    {
        ForecastDismissal::query()->where($this->dismissal($request, $request->key()))->delete();

        return back();
    }

    /**
     * @return array{user_id: string, space_id: string, fingerprint: string, kind: ForecastSuggestionKind}
     */
    private function dismissal(RecurringChargeRequest $request, string $key): array
    {
        return [
            'user_id' => $request->user()->id,
            'space_id' => $request->user()->activeSpace()->id,
            'fingerprint' => $key,
            'kind' => ForecastSuggestionKind::Kept,
        ];
    }
}
