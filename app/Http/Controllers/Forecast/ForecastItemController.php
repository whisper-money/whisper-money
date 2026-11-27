<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\ForecastItemActionRequest;
use App\Http\Requests\Forecast\StoreForecastItemRequest;
use App\Http\Requests\Forecast\UpdateForecastItemRequest;
use App\Models\ForecastItem;
use App\Services\Forecast\ForecastItemWriter;
use Illuminate\Http\RedirectResponse;

/**
 * The concrete lines of the forecast: add one, change one occurrence or
 * every one from a month on, or stop forecasting it. Each answers with the
 * page the user came from, which the client reloads partially, so the
 * recomputed forecast replaces its optimistic one.
 */
class ForecastItemController extends Controller
{
    public function __construct(private readonly ForecastItemWriter $writer) {}

    public function store(StoreForecastItemRequest $request): RedirectResponse
    {
        $this->writer->create($request->user(), $request->user()->activeSpace()->id, $request->line());

        return back();
    }

    public function update(UpdateForecastItemRequest $request, ForecastItem $item): RedirectResponse
    {
        if ($request->isFromHere()) {
            $this->writer->changeFromHere($request->user(), $item, $request->occurrenceDate(), $request->signedAmount(), $request->frequency(), $request->day());
        } else {
            $this->writer->changeOccurrence($item, $request->occurrenceDate(), $request->signedAmount(), $request->movedTo());
        }

        return back();
    }

    public function destroy(ForecastItemActionRequest $request, ForecastItem $item): RedirectResponse
    {
        $this->writer->archive($item);

        return back();
    }
}
