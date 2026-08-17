<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashflowController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $periodType = $request->query('period_type');
        $validPeriodType = is_string($periodType) && in_array($periodType, ['month', 'quarter', 'year'], true)
            ? $periodType
            : 'month';

        $period = $request->query('period');
        $validPeriod = $this->validPeriod($period, $validPeriodType);

        // This used to also send categories, accounts and banks. HandleInertiaRequests
        // already shares all three on every response, and page props win the merge, so
        // the only thing the controller's copies did was replace them - with a `banks`
        // that was every *global* bank rather than the user's own.
        return Inertia::render('cashflow/index', [
            'period' => $validPeriod,
            'periodType' => $validPeriodType,
        ]);
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
