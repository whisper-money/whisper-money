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

        $period = $request->query('period');
        $validPeriod = is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period) === 1
            ? $period
            : null;

        return Inertia::render('cashflow/index', [
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'period' => $validPeriod,
        ]);
    }
}
