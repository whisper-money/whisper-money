<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\StoreForecastEstimateRequest;
use App\Models\ForecastEstimateAdjustment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Adjusts the estimated rest of a category on one account, for one month
 * or from it on. It only changes the forecast: the budget stays as it is.
 * Setting the same month again replaces the earlier figure, and "from this
 * month on" replaces the adjustments that started later too.
 */
class ForecastEstimateController extends Controller
{
    public function store(StoreForecastEstimateRequest $request): RedirectResponse
    {
        $bucket = [
            'user_id' => $request->user()->id,
            'space_id' => $request->user()->activeSpace()->id,
            'account_id' => $request->input('account_id'),
            'category_id' => $request->input('category_id'),
        ];

        DB::transaction(function () use ($request, $bucket): void {
            if ($request->endsOn() === null) {
                ForecastEstimateAdjustment::query()->where($bucket)->whereDate('starts_on', '>', $request->startsOn())->delete();
            }

            ForecastEstimateAdjustment::query()->updateOrCreate(
                [...$bucket, 'starts_on' => $request->startsOn()],
                ['ends_on' => $request->endsOn(), 'amount' => (int) $request->input('amount')],
            );
        });

        return back();
    }
}
