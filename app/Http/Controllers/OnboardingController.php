<?php

namespace App\Http\Controllers;

use App\Enums\BankingConnectionStatus;
use App\Enums\SignupPlan;
use App\Jobs\CategorizeOnboardingTransactionsJob;
use App\Models\Bank;
use App\Models\BankingConnection;
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
        'ai-suggestions',
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

        // Nothing to hide or warn about when nothing is paid, so a hand-typed
        // ?plan= on a self-hosted instance changes nothing.
        $signupPlan = config('subscriptions.enabled')
            ? SignupPlan::fromRequest($user->signup_plan)
            : null;

        // A free-plan signup never sees the AI step, so a deep link must not
        // drop them onto it either.
        $validSteps = $signupPlan === SignupPlan::Free
            ? array_values(array_diff(self::VALID_STEPS, ['ai-suggestions']))
            : self::VALID_STEPS;

        $step = $request->query('step');
        $initialStep = is_string($step) && in_array($step, $validSteps, true)
            ? $step
            : null;

        return Inertia::render('onboarding/index', [
            'banks' => $banks,
            'accounts' => $accounts,
            'categories' => $categories,
            'transactions' => $transactions,
            'initialStep' => $initialStep,
            'signupPlan' => $signupPlan?->value,
        ]);
    }

    /**
     * Report whether the onboarding sync step should keep waiting.
     *
     * A connection that already recorded an error will never set last_synced_at
     * (rate limits keep the status Active while only storing the message), so it
     * must not hold the user on the syncing step forever. It is reported as
     * failed instead, so the step can say so rather than claim everything worked.
     */
    public function syncStatus(Request $request): JsonResponse
    {
        $unsynced = $request->user()
            ->bankingConnections()
            ->whereIn('status', [BankingConnectionStatus::Active, BankingConnectionStatus::Error])
            ->whereNull('last_synced_at')
            ->get(['status', 'error_message']);

        $pending = $unsynced->contains(
            fn (BankingConnection $connection): bool => $connection->status === BankingConnectionStatus::Active
                && $connection->error_message === null
        );

        return response()->json([
            'pending' => $pending,
            'failed' => ! $pending && $unsynced->isNotEmpty(),
        ]);
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
