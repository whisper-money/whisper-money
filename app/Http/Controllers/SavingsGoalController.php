<?php

namespace App\Http\Controllers;

use App\Enums\LabelColor;
use App\Enums\LabelSource;
use App\Features\SavingsGoals;
use App\Http\Requests\StoreSavingsGoalRequest;
use App\Http\Requests\UpdateSavingsGoalRequest;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class SavingsGoalController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreSavingsGoalRequest $request): RedirectResponse
    {
        abort_unless(Feature::active(SavingsGoals::class), 404);

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
        abort_unless(Feature::active(SavingsGoals::class), 404);
        $this->authorize('view', $savingsGoal);

        $user = $request->user();
        $savingsGoal->load('label');

        $transactions = $savingsGoal->label
            ? $savingsGoal->label->transactions()
                ->with(['account.bank', 'category', 'labels'])
                ->orderBy('transaction_date')
                ->get()
            : collect();

        $saved = -1 * (int) $transactions->sum('amount');

        $stats = SavingsGoal::project(
            $saved,
            $savingsGoal->target_amount,
            $savingsGoal->created_at,
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
        ]);
    }

    public function update(UpdateSavingsGoalRequest $request, SavingsGoal $savingsGoal): RedirectResponse
    {
        abort_unless(Feature::active(SavingsGoals::class), 404);
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
        abort_unless(Feature::active(SavingsGoals::class), 404);
        $this->authorize('delete', $savingsGoal);

        DB::transaction(function () use ($savingsGoal) {
            $savingsGoal->label?->delete();
            $savingsGoal->delete();
        });

        return redirect()->route('budgets.index');
    }
}
