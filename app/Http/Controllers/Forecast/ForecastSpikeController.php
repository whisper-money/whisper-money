<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\StoreForecastSpikeAnswerRequest;
use App\Models\Transaction;
use App\Services\Forecast\Data\NewForecastLine;
use App\Services\Forecast\ForecastItemWriter;
use App\Services\Forecast\ForecastSpikeAnswers;
use Illuminate\Http\RedirectResponse;

/**
 * "¿Fue puntual o se repite?": a one-off leaves the charge out of the
 * estimate, and one that repeats becomes a yearly line from it.
 */
class ForecastSpikeController extends Controller
{
    public function store(StoreForecastSpikeAnswerRequest $request, Transaction $transaction, ForecastSpikeAnswers $answers, ForecastItemWriter $writer): RedirectResponse
    {
        $candidate = $answers->answer($transaction, $request->answer());

        if ($candidate !== null) {
            $writer->create($request->user(), $request->user()->activeSpace()->id, NewForecastLine::fromCandidate($candidate));
        }

        return back();
    }
}
