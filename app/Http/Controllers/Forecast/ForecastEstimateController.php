<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\StoreForecastEstimateRequest;
use App\Models\ForecastEstimateAdjustment;
use Illuminate\Http\RedirectResponse;

/**
 * Adjusts the estimated rest of a category on one account, for one month
 * or from it on. It only changes the forecast: the budget stays as it is.
 * Setting the same month again replaces the earlier figure.
 */
class ForecastEstimateController extends Controller
{
    public function store(StoreForecastEstimateRequest $request): RedirectResponse
    {
        ForecastEstimateAdjustment::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'space_id' => $request->user()->activeSpace()->id,
                'account_id' => $request->input('account_id'),
                'category_id' => $request->input('category_id'),
                'starts_on' => $request->startsOn(),
            ],
            ['ends_on' => $request->endsOn(), 'amount' => (int) $request->input('amount')],
        );

        return back();
    }
}
