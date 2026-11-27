<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\DestroyForecastRecurringDropRequest;
use App\Http\Requests\Forecast\RecurringChargeRequest;
use App\Models\ForecastRecurringDrop;
use App\Services\Forecast\RecurringChargeDrops;
use Illuminate\Http\RedirectResponse;

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
        $drops->drop($request->user(), $request->user()->activeSpace()->id, $request->charge());

        return back();
    }

    public function destroy(DestroyForecastRecurringDropRequest $request, ForecastRecurringDrop $drop, RecurringChargeDrops $drops): RedirectResponse
    {
        $drops->undo($drop);

        return back();
    }
}
