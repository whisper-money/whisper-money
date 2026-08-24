<?php

namespace App\Http\Controllers;

use App\Enums\LabelColor;
use App\Enums\LabelSource;
use App\Features\SavingsGoals;
use App\Http\Requests\StoreSavingsGoalRequest;
use App\Http\Requests\SyncSavingsGoalTransactionsRequest;
use App\Http\Requests\UpdateSavingsGoalRequest;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use Closure;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class SavingsGoalController extends Controller implements HasMiddleware
{
    use AuthorizesRequests;

    // ponytail: a flat window, no search. If people can't find older contributions
    // in it, the dialog needs a search box hitting the transactions query instead.
    private const RECENT_TRANSACTIONS_LIMIT = 50;

    /**
     * Hide the whole savings-goals surface behind the rollout feature flag.
     *
     * @return array<int, Closure>
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, Closure $next): mixed {
                abort_unless(Feature::active(SavingsGoals::class), 404);

                return $next($request);
            },
        ];
    }

    public function store(StoreSavingsGoalRequest $request): RedirectResponse
    {
        $goal = DB::transaction(function () use ($request) {
            $label = $request->user()->labels()->create([
                'name' => $request->name,
                'color' => LabelColor::Emerald->value,
                'source' => LabelSource::SavingsGoal,
            ]);

            return $request->user()->savingsGoals()->create([
                'label_id' => $label->id,
                'name' => $request->name,
                'target_amount' => $request->target_amount,
                'target_date' => $request->target_date,
            ]);
        });

        return redirect()->route('savings-goals.show', $goal);
    }

    public function show(Request $request, SavingsGoal $savingsGoal): Response
    {
        $this->authorize('view', $savingsGoal);

        $user = $request->user();
        $savingsGoal->load('label');

        $transactions = $savingsGoal->label
            ? $savingsGoal->label->transactions()
                ->with(['account.bank', 'category', 'labels'])
                ->orderBy('transaction_date')
                ->get()
            : collect();

        $stats = SavingsGoal::project(
            $savingsGoal->savedAmountInCents(),
            $savingsGoal->target_amount,
            SavingsGoal::effectiveStart($savingsGoal->created_at, $transactions->first()?->transaction_date),
            $savingsGoal->target_date,
            now(),
        );

        return Inertia::render('savings-goals/show', [
            'savingsGoal' => $savingsGoal,
            'transactions' => $transactions->values(),
            'stats' => $stats,
            'categories' => Category::query()
                ->where('user_id', $user->id)
                ->forDisplay()
                ->get(),
            'accounts' => Account::query()
                ->where('user_id', $user->id)
                ->with('bank')
                ->orderBy('name')
                ->get(),
            'banks' => Bank::query()
                ->availableForUser($user)
                ->orderBy('name')
                ->get(),
            'labels' => Label::query()
                ->where('user_id', $user->id)
                ->orderBy('name')
                ->get(),
            'currencyCode' => $user->currency_code ?? 'USD',
            // Only fetched when the link-transactions dialog asks for it.
            'recentTransactions' => Inertia::optional(fn () => Transaction::query()
                ->where('user_id', $user->id)
                ->with(['account.bank', 'category', 'labels'])
                ->orderByDesc('transaction_date')
                ->orderByDesc('created_at')
                ->limit(self::RECENT_TRANSACTIONS_LIMIT)
                ->get()),
        ]);
    }

    /**
     * Replace the set of transactions carrying this goal's label. The dialog always
     * shows every already-tagged transaction alongside the recent ones, so the ids it
     * sends back are the complete intended set.
     */
    public function syncTransactions(SyncSavingsGoalTransactionsRequest $request, SavingsGoal $savingsGoal): RedirectResponse
    {
        $this->authorize('update', $savingsGoal);

        $label = $savingsGoal->label;

        if ($label === null) {
            return back();
        }

        // Ids the user does not own are dropped by the scope rather than trusted.
        $ids = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $request->array('transaction_ids'))
            ->pluck('id')
            ->all();

        $changes = $label->transactions()->sync($ids);

        $touched = array_merge($changes['attached'], $changes['detached']);

        if ($touched !== []) {
            // Pivot writes fire no model event, so nothing would re-derive which
            // budgets track these transactions by label. Silently: relabelling an
            // existing transaction is not new spending.
            ReassignTransactionsToBudgets::dispatch($touched, notify: false);
        }

        return back();
    }

    public function update(UpdateSavingsGoalRequest $request, SavingsGoal $savingsGoal): RedirectResponse
    {
        $this->authorize('update', $savingsGoal);

        DB::transaction(function () use ($request, $savingsGoal) {
            $savingsGoal->update($request->only(['name', 'target_amount', 'target_date']));

            if ($request->has('name') && $savingsGoal->label) {
                $savingsGoal->label->update(['name' => $request->name]);
            }
        });

        return redirect()->route('savings-goals.show', $savingsGoal);
    }

    public function destroy(Request $request, SavingsGoal $savingsGoal): RedirectResponse
    {
        $this->authorize('delete', $savingsGoal);

        DB::transaction(function () use ($savingsGoal) {
            $savingsGoal->label?->delete();
            $savingsGoal->delete();
        });

        return redirect()->route('budgets.index');
    }
}
