<?php

namespace App\Http\Controllers;

use App\Http\Requests\Forecast\StoreForecastReviewRequest;
use App\Services\Forecast\ForecastReviewConfirmation;
use Illuminate\Http\RedirectResponse;

/**
 * The forecast's review, behind the Forecast flag: the user keeps the
 * suggested lines they want, dismisses the rest and adds their yearly
 * payments. Users without Pro answer it too; what they see of the result is
 * the forecast page's business.
 */
class ForecastReviewController extends Controller
{
    public function store(StoreForecastReviewRequest $request, ForecastReviewConfirmation $confirmation): RedirectResponse
    {
        $user = $request->user();

        $confirmation->confirm($user, $user->activeSpace()->id, $request->answers());

        return back();
    }
}
