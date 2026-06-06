<?php

namespace App\Http\Controllers;

use App\Enums\BankingConnectionStatus;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * Steps a deep link may land on directly via ?step=.
     *
     * @var list<string>
     */
    private const VALID_STEPS = [
        'welcome',
        'account-types',
        'create-account',
        'import-transactions',
        'import-balances',
        'category-types',
        'customize-categories',
        'smart-rules',
        'syncing',
        'categorize-transactions',
        'complete',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get();

        $accounts = $user->accounts()
            ->with('bank')
            ->get();

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->forDisplay()
            ->get();

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->with(['account.bank', 'labels'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $step = $request->query('step');
        $initialStep = is_string($step) && in_array($step, self::VALID_STEPS, true)
            ? $step
            : null;

        return Inertia::render('onboarding/index', [
            'banks' => $banks,
            'accounts' => $accounts,
            'categories' => $categories,
            'transactions' => $transactions,
            'initialStep' => $initialStep,
        ]);
    }

    public function syncStatus(Request $request): JsonResponse
    {
        $pending = $request->user()
            ->bankingConnections()
            ->where('status', BankingConnectionStatus::Active)
            ->whereNull('last_synced_at')
            ->exists();

        return response()->json(['pending' => $pending]);
    }

    public function complete(Request $request): RedirectResponse
    {
        $request->user()->update([
            'onboarded_at' => now(),
        ]);

        return redirect()->route('dashboard');
    }
}
