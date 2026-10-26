<?php

use App\Enums\CashflowBlock;
use App\Enums\CategoryType;
use App\Jobs\RebuildCatchAllBudgetPeriods;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\BudgetTransactionService;
use App\Services\CashflowBlockQuery;
use App\Services\PeriodComparator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Mail::fake();

    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

    $this->user = User::factory()->create(['onboarded_at' => now()]);
    $this->budgets = app(BudgetService::class);
    $this->category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $this->label = Label::factory()->create(['user_id' => $this->user->id]);
});

/**
 * A budget created the way the app creates one, its history backfilled by the
 * (sync) queue.
 *
 * @param  array<int, string>  $categoryIds
 * @param  array<int, string>  $labelIds
 */
function createBudget(User $user, string $periodType, array $categoryIds = [], array $labelIds = [], bool $isCatchAll = false): Budget
{
    return app(BudgetService::class)->create($user, [
        'name' => $isCatchAll ? 'Everything else' : 'Tracked',
        'period_type' => $periodType,
        'period_start_day' => 1,
        'rollover_type' => 'reset',
        'is_catch_all' => $isCatchAll,
    ], 100000, $categoryIds, $labelIds);
}

function yearlyCatchAll(User $user): BudgetPeriod
{
    return createBudget($user, 'yearly', isCatchAll: true)->getCurrentPeriod();
}

function expense(User $user, Category $category, string $date, int $amount = -1000, ?Label $label = null): Transaction
{
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'transaction_date' => $date,
        'amount' => $amount,
    ]);

    if ($label) {
        $transaction->labels()->attach($label);
        app(BudgetTransactionService::class)->assignTransaction($transaction->load('labels'));
    }

    return $transaction;
}

/**
 * @return array<int, string>
 */
function periodTransactionIds(BudgetPeriod $period): array
{
    return BudgetTransaction::query()->where('budget_period_id', $period->id)->pluck('transaction_id')->all();
}

/**
 * @return array<int, string>
 */
function budgetTransactionIds(Budget $budget): array
{
    return BudgetTransaction::query()
        ->whereIn('budget_period_id', $budget->periods()->select('id'))
        ->pluck('transaction_id')
        ->all();
}

test('creating a budget takes its expenses out of the catch-all', function (string $trackedBy) {
    $catchAll = yearlyCatchAll($this->user);
    $label = $trackedBy === 'label' ? $this->label : null;
    $transaction = expense($this->user, $this->category, '2026-10-05', label: $label);

    expect(periodTransactionIds($catchAll))->toBe([$transaction->id]);

    $budget = $trackedBy === 'label'
        ? createBudget($this->user, 'monthly', labelIds: [$this->label->id])
        : createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);

    expect(periodTransactionIds($catchAll))->toBe([])
        ->and(budgetTransactionIds($budget))->toBe([$transaction->id])
        ->and($catchAll->fresh()->processing_historical)->toBeFalse();

    // Moving rows around is a backfill, not spending: nobody gets an email.
    Mail::assertNothingSent();
})->with(['category', 'label']);

test('creating a catch-all budget does not queue a catch-all rebuild', function () {
    Queue::fake();

    yearlyCatchAll($this->user);

    Queue::assertNotPushed(RebuildCatchAllBudgetPeriods::class);
});

test('deleting a budget hands its expenses back to the catch-all', function () {
    $catchAll = yearlyCatchAll($this->user);
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    $transaction = expense($this->user, $this->category, '2026-10-05');

    expect(periodTransactionIds($catchAll))->toBe([]);

    $this->actingAs($this->user)->delete(route('budgets.destroy', $budget))->assertRedirect(route('budgets.index'));

    expect(periodTransactionIds($catchAll))->toBe([$transaction->id]);
});

test('archiving a budget hands the catch-all what is dated from the archive day on', function () {
    $catchAll = yearlyCatchAll($this->user);
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    $before = expense($this->user, $this->category, '2026-10-05');
    $sameDay = expense($this->user, $this->category, '2026-10-15');
    $scheduled = expense($this->user, $this->category, '2026-10-20');

    expect(budgetTransactionIds($budget))->toEqualCanonicalizing([$before->id, $sameDay->id, $scheduled->id]);

    $this->actingAs($this->user)->post(route('budgets.archive', $budget))->assertRedirect(route('budgets.show', $budget));

    // What the budget counted before it was archived stays with it, and only
    // there; from the archive day on the catch-all counts it instead.
    expect(budgetTransactionIds($budget))->toBe([$before->id])
        ->and(periodTransactionIds($catchAll))->toEqualCanonicalizing([$sameDay->id, $scheduled->id]);

    // A rebuild re-derives the same split rather than pulling the past back in.
    app(BudgetTransactionService::class)->rebuildCatchAllPeriod($catchAll);

    expect(periodTransactionIds($catchAll))->toEqualCanonicalizing([$sameDay->id, $scheduled->id]);

    // And so does assigning a transaction on its own: one dated before the
    // archive day that arrives late is still the archived budget's, and one
    // dated from that day on is the catch-all's.
    $late = expense($this->user, $this->category, '2026-10-01');
    $after = expense($this->user, $this->category, '2026-10-16');

    expect(budgetTransactionIds($budget))->toEqualCanonicalizing([$before->id, $late->id])
        ->and(periodTransactionIds($catchAll))->toEqualCanonicalizing([$sameDay->id, $scheduled->id, $after->id]);

    // A read-only budget sends no news about what it still takes in.
    Mail::assertNothingSent();
});

test('a label budget claims an expense over the whole catch-all period it overlaps, not only on its own dates', function () {
    $catchAll = yearlyCatchAll($this->user);
    // Its periods only start in September, but they overlap the yearly catch-all.
    $budget = createBudget($this->user, 'monthly', labelIds: [$this->label->id]);

    $march = expense($this->user, $this->category, '2026-03-10', label: $this->label);

    expect(periodTransactionIds($catchAll))->toBe([])
        ->and(budgetTransactionIds($budget))->toBe([]);

    // The single-transaction path agrees with the catch-all rebuild.
    app(BudgetTransactionService::class)->rebuildCatchAllPeriod($catchAll);

    expect(periodTransactionIds($catchAll))->not->toContain($march->id);
});

test('the catch-all spends what the cashflow general block counts over the same range', function () {
    $other = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    // Absorbed before any other budget existed, some of it claimed later on.
    $catchAll = yearlyCatchAll($this->user);
    expense($this->user, $this->category, '2026-02-10', -1500);
    expense($this->user, $other, '2026-03-10', -2000, $this->label);
    expense($this->user, $other, '2026-04-10', -2500);

    createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    createBudget($this->user, 'monthly', labelIds: [$this->label->id]);

    expense($this->user, $this->category, '2026-10-02', -3000);
    expense($this->user, $other, '2026-10-03', -3500, $this->label);
    expense($this->user, $other, '2026-10-04', -4000);

    $general = app(CashflowBlockQuery::class)
        ->apply(Transaction::query(), CashflowBlock::General, $this->user->id, PeriodComparator::fromRequest(['from' => '2026-01-01', 'to' => '2026-12-31']))
        ->sum('amount');

    expect($catchAll->fresh()->spentAmount())->toBe(6500)
        ->and($catchAll->fresh()->spentAmount())->toBe(-(int) $general);
});

test('the rebuild command heals a double-counted catch-all, leaving it alone on a dry run', function () {
    $catchAll = yearlyCatchAll($this->user);
    $claimed = expense($this->user, $this->category, '2026-10-05');
    $archivedCategory = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $archived = createBudget($this->user, 'monthly', categoryIds: [$archivedCategory->id]);
    $afterArchive = expense($this->user, $archivedCategory, '2026-10-20');
    // Archived the old way, still holding what is dated from its archive day
    // on, which the catch-all takes over as soon as anything rebuilds it.
    $archived->update(['archived_at' => now()]);

    // The state the bug left behind: a budget created without releasing the
    // catch-all's copy, and an expense the catch-all never picked up.
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    BudgetTransaction::factory()->create(['budget_period_id' => $catchAll->id, 'transaction_id' => $claimed->id, 'amount' => 1000]);

    $other = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $missed = expense($this->user, $other, '2026-10-06');
    BudgetTransaction::query()->where('transaction_id', $missed->id)->delete();

    expect(periodTransactionIds($catchAll))->toEqualCanonicalizing([$claimed->id, $afterArchive->id]);

    $this->artisan('budgets:rebuild-catch-all', ['--dry-run' => true])
        ->expectsOutputToContain('1 transaction(s) dated from an archive day on would be dropped from archived budgets')
        ->expectsOutputToContain('1 transaction(s) would be released, 1 would be absorbed')
        ->assertSuccessful();

    expect(periodTransactionIds($catchAll))->toEqualCanonicalizing([$claimed->id, $afterArchive->id])
        ->and(budgetTransactionIds($archived))->toBe([$afterArchive->id]);

    $this->artisan('budgets:rebuild-catch-all', ['--user' => $this->user->email])->assertSuccessful();

    expect(periodTransactionIds($catchAll))->toEqualCanonicalizing([$missed->id, $afterArchive->id])
        ->and(budgetTransactionIds($budget))->toBe([$claimed->id])
        ->and(budgetTransactionIds($archived))->toBe([]);

    Mail::assertNothingSent();
});

test('the rebuild command refuses an unknown user', function () {
    $this->artisan('budgets:rebuild-catch-all', ['--user' => 'nobody@example.com'])->assertFailed();
});
