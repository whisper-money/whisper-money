<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\AccountMetricsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private AccountMetricsService $accountMetricsService) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank:id,name,logo')
            ->orderByRaw("FIELD(type, 'checking', 'savings', 'investment', 'retirement', 'loan', 'credit_card', 'others')")
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code']);

        return Inertia::render('Accounts/Index', [
            'accounts' => $accounts,
            'accountMetrics' => Inertia::defer(fn () => $this->accountMetricsService->getAccountMetrics($user->currency_code, $accounts)),
        ]);
    }

    public function show(Request $request, Account $account): Response
    {
        $this->authorize('view', $account);

        $account->load('bank:id,name,logo');

        return Inertia::render('Accounts/Show', [
            'account' => $account->only(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'bank']),
        ]);
    }
}
