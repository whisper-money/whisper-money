<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MonthlySavingsTotalsRequest;
use App\Services\CashflowAnalyticsService;
use App\Services\PeriodComparator;
use App\Services\SavingsGoals\MonthlySavingsGoalTotals;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashflowAnalyticsController extends Controller
{
    public function __construct(
        private CashflowAnalyticsService $cashflow,
        private MonthlySavingsGoalTotals $monthlySavings,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate(PeriodComparator::rules());

        return $this->cashflowJson($this->cashflow->summary($request->user(), PeriodComparator::fromRequest($validated)));
    }

    public function sankey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
            'parent' => 'nullable|uuid',
        ]);

        return $this->cashflowJson($this->cashflow->sankey(
            $request->user(),
            Carbon::parse($validated['from']),
            Carbon::parse($validated['to']),
            $validated['parent'] ?? null,
        ));
    }

    public function trend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'months' => 'nullable|integer|min:1|max:'.CashflowAnalyticsService::MAX_TREND_MONTHS,
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        if (isset($validated['from'], $validated['to'])) {
            $start = Carbon::parse($validated['from'])->startOfMonth();
            $end = Carbon::parse($validated['to'])->endOfMonth();
        } else {
            $months = $validated['months'] ?? 12;
            $end = isset($validated['to'])
                ? Carbon::parse($validated['to'])->endOfMonth()
                : Carbon::now()->endOfMonth();
            $start = $end->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();
        }

        return $this->cashflowJson([
            'data' => $this->cashflow->trend($request->user(), $start, $end),
        ]);
    }

    public function breakdown(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
            'type' => 'required|in:income,expense',
            'parent' => 'nullable|uuid',
        ]);

        return $this->cashflowJson($this->cashflow->breakdown(
            $request->user(),
            PeriodComparator::fromRequest($validated),
            $validated['type'] === 'income' ? CategoryType::Income : CategoryType::Expense,
            $validated['parent'] ?? null,
        ));
    }

    /**
     * Every monthly goal added up for one month, with the months before it. The
     * data is null when no monthly goal ran that month.
     */
    public function monthlySavings(MonthlySavingsTotalsRequest $request): JsonResponse
    {
        return $this->cashflowJson([
            'data' => $this->monthlySavings->forMonth($request->user(), $request->month()),
        ]);
    }

    private function cashflowJson(array $data): JsonResponse
    {
        return response()
            ->json($data)
            ->header('Cache-Control', 'no-store, private');
    }
}
