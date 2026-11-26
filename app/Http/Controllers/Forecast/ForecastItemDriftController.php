<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\ForecastItemActionRequest;
use App\Models\ForecastItem;
use App\Services\Forecast\ForecastCalendar;
use App\Services\Forecast\ForecastDrift;
use App\Services\Forecast\ForecastEditWindow;
use App\Services\Forecast\ForecastItemWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * "Tu alquiler ahora es de 950 €. ¿Actualizar?": accepts the update a
 * line's last payments suggest.
 */
class ForecastItemDriftController extends Controller
{
    public function store(ForecastItemActionRequest $request, ForecastItem $item, ForecastDrift $drift, ForecastItemWriter $writer, ForecastEditWindow $window): RedirectResponse
    {
        $suggestion = $drift->suggestionFor($item);

        $from = $suggestion === null ? null : $drift->startsOn($request->user(), $item, $suggestion);

        if ($from === null || ! $window->allowsMonth($request->user(), ForecastCalendar::monthKey($from))) {
            throw ValidationException::withMessages(['drift' => __('There is nothing to update on this line.')]);
        }

        $writer->changeFromHere($request->user(), $item, $from, $suggestion->amount, null, null);

        return back();
    }
}
