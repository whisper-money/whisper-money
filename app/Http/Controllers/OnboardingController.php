<?php

namespace App\Http\Controllers;

use App\Enums\BankingConnectionStatus;
use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Enums\SignupPlan;
use App\Http\Requests\StoreOnboardingAnswersRequest;
use App\Http\Requests\StoreOnboardingTargetRequest;
use App\Jobs\CategorizeOnboardingTransactionsJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use App\Services\BudgetService;
use App\Services\OnboardingRevealService;
use App\Services\OnboardingSummaryService;
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
        'reveal',
        'ai-suggestions',
        'categorize-transactions',
        'target',
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
            ->get()
            // Only the hub needs it, so it is asked for here rather than
            // appended to every account the app serializes.
            ->append('iban_tail');

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
    public function syncStatus(Request $request, OnboardingSummaryService $summary): JsonResponse
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
            'progress' => $summary->ledger($user, $user->accounts()
                ->whereNotNull('banking_connection_id')
                ->pluck('id')),
        ]);
    }

    /**
     * The numbers step 7 is written about.
     *
     * Its own request rather than a page prop: it is several queries over a
     * year of movements, and every other step of the onboarding would pay for
     * them on every render without ever showing them.
     */
    public function reveal(Request $request, OnboardingRevealService $reveal): JsonResponse
    {
        return response()->json($reveal->for($request->user()));
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
        $this->remember($request->user(), $request->validated());

        return back();
    }

    /**
     * Merge answers onto the user's row.
     *
     * @param  array<string, mixed>  $answers
     */
    private function remember(User $user, array $answers): void
    {
        $user->update([
            'onboarding_answers' => [...$user->onboarding_answers ?? [], ...$answers],
        ]);
    }

    /**
     * What the user has to show for the flow, for the two screens that close it.
     *
     * Its own request for the same reason the reveal is: it reads a year of
     * movements, and the nine steps before it would pay for that on every
     * render without ever showing it.
     */
    public function summary(Request $request, OnboardingSummaryService $summary): JsonResponse
    {
        return response()->json($summary->for($request->user()));
    }

    /**
     * Turn the number step 10 landed on into the budget that holds it.
     *
     * The target is what the user chose to keep back; the budget's limit is
     * what is left of the month they actually spent once that is set aside. It
     * is a catch-all, so every movement counts towards it without the user
     * having to assign categories to a budget on their way out of onboarding.
     *
     * A user who already has a catch-all keeps it — a reload, or a second run
     * through the step, must not leave two budgets claiming the same movements.
     */
    public function target(
        StoreOnboardingTargetRequest $request,
        BudgetService $budgets,
        OnboardingSummaryService $summary,
    ): JsonResponse {
        $user = $request->user();
        $target = (int) $request->validated('amount');
        $spending = $summary->spending($user)['spent'] ?? null;

        if ($spending === null) {
            // Nothing was read to build a target on, so there is no limit to
            // set: the step itself never offers this to such a user.
            return response()->json(['message' => __('No spending to build a target on.')], 422);
        }

        $budget = $this->catchAll($user) ?? $budgets->create($user, [
            'name' => __('Monthly spending'),
            'period_type' => BudgetPeriodType::Monthly,
            'period_start_day' => 1,
            'rollover_type' => RolloverType::Reset,
            'is_catch_all' => true,
        ], max(0, $spending - $target));

        // Set from the toggle rather than left to the account default, so the
        // row that says "warn me before I overspend" is the row that decides it.
        $budget->update([
            'notify_on_close_to_limit' => $request->boolean('warn'),
            'notify_on_over_limit' => $request->boolean('warn'),
        ]);

        // Only once the budget behind it exists: step 11 reads this back as
        // proof the target is real, and it may not report one that is not.
        $this->remember($user, ['target' => $target]);

        return response()->json(['target' => $target, 'budget_id' => $budget->id]);
    }

    /** The budget that absorbs everything not claimed by another one. */
    private function catchAll(User $user): ?Budget
    {
        return $user->budgets()->notArchived()->where('is_catch_all', true)->first();
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
