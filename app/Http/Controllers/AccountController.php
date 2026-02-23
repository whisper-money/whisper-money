<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\User;
use App\Services\BalanceLookup;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private ExchangeRateService $exchangeRateService) {}

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
            'accountMetrics' => Inertia::defer(fn () => $this->getAccountMetrics($user, $accounts)),
        ]);
    }

    /**
     * Compute per-account balance metrics: current balance, previous month balance, and 12-month sparkline history.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<string, array{currentBalance: int, previousBalance: int, diff: int, investedAmount: int|null, history: list<array{date: string, value: int, investedAmount?: int|null}>}>
     */
    private function getAccountMetrics(User $user, Collection $accounts): array
    {
        $userCurrency = $user->currency_code;
        $now = Carbon::now();
        $rangeStart = $now->copy()->subMonths(12)->startOfMonth();

        $accountIds = $accounts->pluck('id');
        $lookup = BalanceLookup::forAccounts($accountIds, $rangeStart, $now->copy());

        $metrics = [];

        foreach ($accounts as $account) {
            $history = [];
            $current = $rangeStart->copy();
            $endMonth = $now->copy()->startOfMonth();

            while ($current->lte($endMonth)) {
                $date = $current->copy()->endOfMonth();
                $originalBalance = $lookup->getBalanceAt($account->id, $date);
                $convertedBalance = $this->convertBalance($originalBalance, $account->currency_code, $userCurrency, $date->toDateString());

                $point = [
                    'date' => $this->formatMonth($date),
                    'value' => $convertedBalance,
                ];

                if ($account->type->supportsInvestedAmount()) {
                    $investedAmount = $lookup->getInvestedAmountAt($account->id, $date);
                    $point['investedAmount'] = $investedAmount !== null
                        ? $this->convertBalance($investedAmount, $account->currency_code, $userCurrency, $date->toDateString())
                        : null;
                }

                $history[] = $point;
                $current->addMonth();
            }

            $currentBalance = end($history)['value'] ?? 0;
            $previousBalance = count($history) > 1 ? $history[count($history) - 2]['value'] : 0;

            $investedAmount = null;
            if ($account->type->supportsInvestedAmount()) {
                $rawInvested = $lookup->getInvestedAmountAt($account->id, $now);
                $investedAmount = $rawInvested !== null
                    ? $this->convertBalance($rawInvested, $account->currency_code, $userCurrency, $now->toDateString())
                    : null;
            }

            $metrics[$account->id] = [
                'currentBalance' => $currentBalance,
                'previousBalance' => $previousBalance,
                'diff' => $currentBalance - $previousBalance,
                'investedAmount' => $investedAmount,
                'history' => $history,
            ];
        }

        return $metrics;
    }

    private function formatMonth(Carbon $date): string
    {
        $isCurrentYear = $date->year === Carbon::now()->year;

        return $isCurrentYear
            ? $date->format('M')
            : $date->format("M 'y");
    }

    private function convertBalance(int $balance, string $sourceCurrency, string $targetCurrency, string $date): int
    {
        if (strtolower($sourceCurrency) === strtolower($targetCurrency)) {
            return $balance;
        }

        return $this->exchangeRateService->convert($sourceCurrency, $targetCurrency, $balance, $date);
    }

    public function show(Request $request, Account $account): Response
    {
        $this->authorize('view', $account);

        $user = $request->user();

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'color']);

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

        $account->load('bank:id,name,logo');

        return Inertia::render('Accounts/Show', [
            'account' => $account->only(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'bank']),
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
        ]);
    }
}
