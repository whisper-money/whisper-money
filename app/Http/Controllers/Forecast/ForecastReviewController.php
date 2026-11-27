<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\StoreForecastReviewRequest;
use App\Services\Forecast\ForecastReviewConfirmation;
use Illuminate\Http\RedirectResponse;

/**
 * The forecast's review, behind the Forecast flag: once the user has seen
 * how each category is estimated, they add the yearly suggestions they want,
 * dismiss the rest and type in their other yearly or one-off payments.
 * Users without Pro answer it too; what they see of the result is the
 * forecast page's business.
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
