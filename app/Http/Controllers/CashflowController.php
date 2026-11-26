<?php

namespace App\Http\Controllers;

use App\Features\Forecast;
use App\Services\Forecast\ForecastRestOfMonth;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class CashflowController extends Controller
{
    public function __invoke(Request $request, ForecastRestOfMonth $restOfMonth): Response
    {
        $periodType = $request->query('period_type');
        $validPeriodType = is_string($periodType) && in_array($periodType, ['month', 'quarter', 'year'], true)
            ? $periodType
            : 'month';

        $period = $request->query('period');
        $validPeriod = $this->validPeriod($period, $validPeriodType);

        // categories, accounts and banks arrive via HandleInertiaRequests::share().
        return Inertia::render('cashflow/index', [
            'period' => $validPeriod,
            'periodType' => $validPeriodType,
            ...$this->forecastProps($request, $restOfMonth),
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
    private function forecastProps(Request $request, ForecastRestOfMonth $restOfMonth): array
    {
        if (! Feature::for($request->user())->active(Forecast::class)) {
            return [];
        }

        return [
            'forecastRestOfMonth' => Inertia::defer(fn (): ?array => $restOfMonth->for($request->user())),
        ];
    }

    private function validPeriod(mixed $period, string $periodType): ?string
    {
        if (! is_string($period)) {
            return null;
        }

        $pattern = match ($periodType) {
            'quarter' => '/^\d{4}-Q[1-4]$/',
            'year' => '/^\d{4}$/',
            default => '/^\d{4}-\d{2}$/',
        };

        return preg_match($pattern, $period) === 1 ? $period : null;
    }
}
