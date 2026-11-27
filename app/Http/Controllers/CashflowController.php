<?php

namespace App\Http\Controllers;

use App\Services\Forecast\ForecastAudience;
use App\Services\Forecast\ForecastRestOfMonth;
use App\Support\PeriodSelection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashflowController extends Controller
{
    public function __invoke(Request $request, ForecastAudience $forecastAudience): Response
    {
        $selection = PeriodSelection::fromRequest($request);

        // categories, accounts and banks arrive via HandleInertiaRequests::share().
        return Inertia::render('cashflow/index', [
            'period' => $selection->period,
            'periodType' => $selection->type,
            ...$this->forecastProps($request, $forecastAudience),
        ]);
    }

    /**
     * Behind the Forecast flag, what the forecast still expects for the rest
     * of the month in progress. Deferred, so the page paints first; the page
     * shows it only while the month in progress is on screen. Without the
     * flag, no prop at all.
     *
     * @return array<string, mixed>
     */
    private function forecastProps(Request $request, ForecastAudience $forecastAudience): array
    {
        if (! $forecastAudience->isActive($request->user())) {
            return [];
        }

        return [
            'forecastRestOfMonth' => Inertia::defer(fn (): ?array => app(ForecastRestOfMonth::class)->for($request->user())),
        ];
    }
}
