<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashflowController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'icon', 'color', 'cashflow_direction']);

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank:id,name,logo')
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code']);

        $banks = Bank::query()
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);

        $periodType = $request->query('period_type');
        $validPeriodType = is_string($periodType) && in_array($periodType, ['month', 'quarter', 'year'], true)
            ? $periodType
            : 'month';

        $period = $request->query('period');
        $validPeriod = $this->validPeriod($period, $validPeriodType);

        return Inertia::render('cashflow/index', [
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
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
