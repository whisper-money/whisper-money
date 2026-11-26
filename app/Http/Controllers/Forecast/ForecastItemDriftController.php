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

        if ($suggestion === null || ! $window->allowsMonth($request->user(), ForecastCalendar::monthKey($writer->driftStartsOn($request->user(), $item, $suggestion)))) {
            throw ValidationException::withMessages(['drift' => __('There is nothing to update on this line.')]);
        }

        $writer->acceptDrift($request->user(), $item, $suggestion);

        return back();
    }
}
