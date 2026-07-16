<?php

namespace App\Http\Controllers;

use App\Enums\BankingConnectionStatus;
use App\Jobs\CategorizeOnboardingTransactionsJob;
use App\Models\Bank;
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
        'ai-suggestions',
        'categorize-transactions',
        'complete',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $space = $user->activeSpace();

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get();

        $accounts = $space->accounts()
            ->with('bank')
            ->get();

        $categories = $space->categories()
            ->forDisplay()
            ->get();

        $transactions = $space->transactions()
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
        $space = $request->user()->activeSpace();

        $pending = $space
            ->bankingConnections()
            ->where('status', BankingConnectionStatus::Active)
            ->whereNull('last_synced_at')
            ->exists();

        return response()->json(['pending' => $pending]);
    }

    public function complete(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->update([
            'onboarded_at' => now(),
        ]);

        CategorizeOnboardingTransactionsJob::dispatch($user);

        return redirect()->route('dashboard');
    }
}
