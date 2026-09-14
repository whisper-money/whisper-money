<?php

namespace App\Http\Controllers;

use App\Enums\BankingConnectionStatus;
use App\Enums\SignupPlan;
use App\Http\Requests\StoreOnboardingAnswersRequest;
use App\Jobs\CategorizeOnboardingTransactionsJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use Carbon\Carbon;
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
        'promise',
        'goal',
        'today',
        'guess',
        'plan',
        'create-account',
        'import-transactions',
        'import-balances',
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
            'onboardingAnswers' => $user->onboarding_answers ?? [],
            'signupPlan' => $signupPlan?->value,
            'pendingMapping' => $this->pendingMapping($user),
        ]);
    }

    /**
     * The bank connection still waiting for the user to say which of its
     * accounts to keep, if there is one.
     *
     * Only ever one is offered: the accounts hub is a single screen, and a user
     * who somehow has two unfinished connections deals with them one after the
     * other rather than on a screen that has to explain which bank is which.
     *
     * @return array{connection_id: string, bank_name: string, bank_logo: ?string, accounts: list<array{uid: string, name: ?string, iban: ?string, currency: ?string}>}|null
     */
    private function pendingMapping(User $user): ?array
    {
        $connection = $user->bankingConnections()
            ->where('status', BankingConnectionStatus::AwaitingMapping)
            ->orderBy('created_at')
            ->get()
            ->first(fn (BankingConnection $connection): bool => $connection->mappablePendingAccounts() !== []);

        if (! $connection) {
            return null;
        }

        return [
            'connection_id' => $connection->id,
            'bank_name' => $connection->aspsp_name,
            'bank_logo' => $connection->aspsp_logo,
            'accounts' => array_map(fn (array $account): array => [
                'uid' => $account['uid'],
                'name' => $account['name'] ?? null,
                'iban' => $account['account_id']['iban'] ?? null,
                'currency' => $account['currency'] ?? null,
            ], $connection->mappablePendingAccounts()),
        ];
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
        $user = $request->user();

        $unsynced = $user
            ->bankingConnections()
            ->whereIn('status', [BankingConnectionStatus::Active, BankingConnectionStatus::Error])
            ->whereNull('last_synced_at')
            ->get(['aspsp_name', 'status', 'error_message']);

        $pending = $unsynced->contains(
            fn (BankingConnection $connection): bool => $connection->status === BankingConnectionStatus::Active
                && $connection->error_message === null
        );

        return response()->json([
            'pending' => $pending,
            'failed' => ! $pending && $unsynced->isNotEmpty(),
            // The wait is the bank's, so the screen says whose it is.
            'bank' => $unsynced->first()?->aspsp_name,
            'progress' => $this->syncProgress($user),
        ]);
    }

    /**
     * What the first sync has actually brought in so far.
     *
     * A spinner cannot tell a slow bank from a dead queue, and this wait is the
     * longest one in the onboarding. Real counters can: they move, and when they
     * stop moving the user can see for themselves how much they already have and
     * decide whether the rest is worth waiting for.
     *
     * @return array{transactions: int, merchants: int, accounts: int, months: int, first_date: ?string, last_date: ?string}
     */
    private function syncProgress(User $user): array
    {
        $accountIds = $user->accounts()
            ->whereNotNull('banking_connection_id')
            ->pluck('id');

        $empty = [
            'transactions' => 0,
            'merchants' => 0,
            'accounts' => $accountIds->count(),
            'months' => 0,
            'first_date' => null,
            'last_date' => null,
        ];

        if ($accountIds->isEmpty()) {
            return $empty;
        }

        $totals = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('account_id', $accountIds)
            ->selectRaw('count(*) as transactions, count(distinct creditor_name) as merchants, min(transaction_date) as first_date, max(transaction_date) as last_date')
            ->first();

        if (! $totals || (int) $totals->transactions === 0) {
            return $empty;
        }

        $first = Carbon::parse($totals->first_date)->startOfMonth();
        $last = Carbon::parse($totals->last_date)->startOfMonth();

        return [
            'transactions' => (int) $totals->transactions,
            // Counterparty names are the only plaintext description we hold:
            // `description` is encrypted at rest, so it cannot be counted here.
            'merchants' => (int) $totals->merchants,
            'accounts' => $accountIds->count(),
            // Inclusive: a January-to-January import covers one month, not zero.
            'months' => (int) $first->diffInMonths($last) + 1,
            'first_date' => $first->toDateString(),
            'last_date' => $last->toDateString(),
        ];
    }

    /**
     * Keep what the user told the onboarding about themselves.
     *
     * Merged rather than replaced, because each question saves on its own as
     * it is answered: a user who drops out after the second one leaves the
     * first behind instead of overwriting it with a half-empty payload.
     */
    public function answers(StoreOnboardingAnswersRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->update([
            'onboarding_answers' => [...$user->onboarding_answers ?? [], ...$request->validated()],
        ]);

        return back();
    }

    /**
     * Queue the AI pass over whatever the suggestions step left uncategorized.
     *
     * Fired when the user leaves the AI step, by any of its exits: rules
     * accepted or skipped, too few transactions to be eligible, nothing
     * suggested, or a run that failed. Every one of those users paid the same
     * and expects the same, and the batch is small (the generated rules already
     * cover the bulk of the import), so it finishes during the two or three
     * steps that remain instead of on a dashboard the user is already looking
     * at. The gate is the job's own, so a user without a plan or without
     * consent never even queues one.
     */
    public function categorize(Request $request, AiCategorizationGate $gate): JsonResponse
    {
        $user = $request->user();
        $queued = $gate->allows($user);

        if ($queued) {
            CategorizeOnboardingTransactionsJob::dispatch($user);
        }

        return response()->json(['queued' => $queued]);
    }

    public function complete(Request $request): RedirectResponse
    {
        $request->user()->update([
            'onboarded_at' => now(),
        ]);

        return redirect()->route('dashboard');
    }
}
