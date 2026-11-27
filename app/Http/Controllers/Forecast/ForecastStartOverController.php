<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\StartForecastOverRequest;
use App\Services\Forecast\ForecastStartOver;
use Illuminate\Http\RedirectResponse;

/**
 * "Start over" on the forecast, behind the Forecast flag: deletes what the
 * user built in the space they are in and takes them back to the forecast,
 * where the review opens as it does the first time.
 */
class ForecastStartOverController extends Controller
{
    public function store(StartForecastOverRequest $request, ForecastStartOver $startOver): RedirectResponse
    {
        $user = $request->user();

        $startOver->startOver($user, $user->activeSpace()->id);

        return to_route('forecast.index');
    }
}
