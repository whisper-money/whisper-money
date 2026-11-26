<?php

namespace App\Http\Controllers;

use App\Http\Requests\Forecast\ShowForecastRequest;
use App\Services\Forecast\ForecastPresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cash-flow forecast ("Previsión") of one account, behind the Forecast
 * flag. Users without Pro reach it too, and see the next month only.
 */
class ForecastController extends Controller
{
    public function index(ShowForecastRequest $request, ForecastPresenter $presenter): Response
    {
        $user = $request->user();

        return Inertia::render('forecast/index', $presenter->present(
            $user,
            $user->activeSpace()->id,
            $request->accountId(),
            $request->horizon(),
        ));
    }
}
