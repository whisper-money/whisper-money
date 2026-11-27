<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\DestroyForecastRecurringDropRequest;
use App\Http\Requests\Forecast\RecurringChargeRequest;
use App\Models\ForecastRecurringDrop;
use App\Services\Forecast\RecurringChargeDrops;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * "I'm dropping it" on a recurring charge, and its Undo: from next month on
 * its category's spend estimate goes down by what it costs a month (see
 * RecurringChargeDrops). Next month is within the months a user without Pro
 * sees, so everyone can drop a charge.
 */
class ForecastRecurringDropController extends Controller
{
    public function store(RecurringChargeRequest $request, RecurringChargeDrops $drops): RedirectResponse
    {
        $user = $request->user();
        $charge = $request->charge();

        if (! $drops->canDrop($charge, $drops->expenseCategoryIds($user))) {
            throw ValidationException::withMessages(['key' => __('This charge is not in an expense category, so the forecast does not count it as spend.')]);
        }

        $drops->drop($user, $user->activeSpace()->id, $charge);

        return back();
    }

    public function destroy(DestroyForecastRecurringDropRequest $request, ForecastRecurringDrop $drop, RecurringChargeDrops $drops): RedirectResponse
    {
        $drops->undo($drop);

        return back();
    }
}
