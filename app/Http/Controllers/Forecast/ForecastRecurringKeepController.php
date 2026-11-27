<?php

namespace App\Http\Controllers\Forecast;

use App\Enums\ForecastSuggestionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\RecurringChargeRequest;
use App\Models\ForecastDismissal;
use Illuminate\Http\RedirectResponse;

/**
 * "Keep" on a recurring charge, and its Undo: the card stops asking about
 * it. Remembered as a dismissal of its own kind (ForecastSuggestionKind::Kept)
 * keyed by the charge key, so it never hides a suggestion of the review.
 */
class ForecastRecurringKeepController extends Controller
{
    public function store(RecurringChargeRequest $request): RedirectResponse
    {
        ForecastDismissal::query()->firstOrCreate($this->dismissal($request));

        return back();
    }

    public function destroy(RecurringChargeRequest $request): RedirectResponse
    {
        ForecastDismissal::query()->where($this->dismissal($request))->delete();

        return back();
    }

    /**
     * @return array{user_id: string, space_id: string, fingerprint: string, kind: ForecastSuggestionKind}
     */
    private function dismissal(RecurringChargeRequest $request): array
    {
        return [
            'user_id' => $request->user()->id,
            'space_id' => $request->user()->activeSpace()->id,
            'fingerprint' => $request->charge()->key,
            'kind' => ForecastSuggestionKind::Kept,
        ];
    }
}
