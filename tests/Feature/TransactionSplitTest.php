<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Enums\TransactionSource;
use App\Features\TransactionSplitting;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\BudgetTransactionService;
use App\Services\CategorySpendingService;
use App\Services\TransactionSplitter;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

function expenseCategory(User $user, string $name = 'Cat'): Category
{
    return Category::factory()->for($user)->create([
        'name' => $name,
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
}

function splittableTransaction(User $user, int $amount = -10000): Transaction
{
    return Transaction::factory()->for($user)->create([
        'amount' => $amount,
        'category_id' => null,
        'currency_code' => $user->currency_code,
        'source' => TransactionSource::Imported,
    ]);
}

beforeEach(function () {
    // Keep every amount in the user's own currency so analytics never reaches
    // for an exchange rate (which would be a stray HTTP request under test).
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(TransactionSplitting::class);
});

it('splits a transaction into lines and nulls its own category', function () {
    $restaurant = expenseCategory($this->user, 'Restaurant');
    $travel = expenseCategory($this->user, 'Travel');
    $transaction = splittableTransaction($this->user);

    actingAs($this->user)
        ->patchJson(route('transactions.update', $transaction), [
            'splits' => [
                ['category_id' => $restaurant->id, 'amount' => -2500, 'label_ids' => []],
                ['category_id' => $travel->id, 'amount' => -7500, 'label_ids' => []],
            ],
        ])
        ->assertOk();

    $transaction->refresh();

    expect($transaction->category_id)->toBeNull();
    expect($transaction->splits)->toHaveCount(2);
    expect($transaction->splits->sum('amount'))->toBe(-10000);
    expect($transaction->splits->pluck('category_id')->all())
        ->toContain($restaurant->id, $travel->id);
});

it('moves transaction labels onto the split lines', function () {
    $category = expenseCategory($this->user);
    $label = Label::factory()->for($this->user)->create();
    $transaction = splittableTransaction($this->user);
    $transaction->labels()->attach($label->id);

    actingAs($this->user)
        ->patchJson(route('transactions.update', $transaction), [
            'splits' => [
                ['category_id' => $category->id, 'amount' => -2500, 'label_ids' => [$label->id]],
                ['category_id' => $category->id, 'amount' => -7500, 'label_ids' => []],
            ],
        ])
        ->assertOk();

    $transaction->refresh();

    expect($transaction->labels)->toHaveCount(0);
    expect($transaction->splits->first()->labels->pluck('id')->all())->toContain($label->id);
});

it('rejects splits that do not add up to the transaction amount', function () {
    $category = expenseCategory($this->user);
    $transaction = splittableTransaction($this->user);

    actingAs($this->user)
        ->patchJson(route('transactions.update', $transaction), [
            'splits' => [
                ['category_id' => $category->id, 'amount' => -2500],
                ['category_id' => $category->id, 'amount' => -2500],
            ],
        ])
        ->assertJsonValidationErrors(['splits']);

    expect($transaction->fresh()->splits)->toHaveCount(0);
});

it('rejects a single split line', function () {
    $category = expenseCategory($this->user);
    $transaction = splittableTransaction($this->user);

    actingAs($this->user)
        ->patchJson(route('transactions.update', $transaction), [
            'splits' => [
                ['category_id' => $category->id, 'amount' => -10000],
            ],
        ])
        ->assertJsonValidationErrors(['splits']);
});

it('rejects a split line whose sign differs from the transaction', function () {
    $category = expenseCategory($this->user);
    $transaction = splittableTransaction($this->user);

    actingAs($this->user)
        ->patchJson(route('transactions.update', $transaction), [
            'splits' => [
                ['category_id' => $category->id, 'amount' => -12500],
                ['category_id' => $category->id, 'amount' => 2500],
            ],
        ])
        ->assertJsonValidationErrors(['splits.1.amount']);
});

it('collapses a split back to a single category', function () {
    $category = expenseCategory($this->user);
    $target = expenseCategory($this->user, 'Target');
    $transaction = splittableTransaction($this->user);

    app(TransactionSplitter::class)->apply($transaction, [
        ['category_id' => $category->id, 'amount' => -2500],
        ['category_id' => $category->id, 'amount' => -7500],
    ]);

    actingAs($this->user)
        ->patchJson(route('transactions.update', $transaction), [
            'category_id' => $target->id,
            'splits' => [],
        ])
        ->assertOk();

    $transaction->refresh();

    expect($transaction->splits)->toHaveCount(0);
    expect($transaction->category_id)->toBe($target->id);
});

it('forbids splitting when the feature is disabled', function () {
    Feature::for($this->user)->deactivate(TransactionSplitting::class);

    $category = expenseCategory($this->user);
    $transaction = splittableTransaction($this->user);

    actingAs($this->user)
        ->patchJson(route('transactions.update', $transaction), [
            'splits' => [
                ['category_id' => $category->id, 'amount' => -2500],
                ['category_id' => $category->id, 'amount' => -7500],
            ],
        ])
        ->assertForbidden();
});

it('cascade-deletes split lines when the transaction row is removed', function () {
    $category = expenseCategory($this->user);
    $transaction = splittableTransaction($this->user);

    app(TransactionSplitter::class)->apply($transaction, [
        ['category_id' => $category->id, 'amount' => -2500],
        ['category_id' => $category->id, 'amount' => -7500],
    ]);

    // Hit the DB-level foreign-key cascade directly (a model force-delete would
    // additionally fire the queued TransactionDeleted listener).
    DB::table('transactions')->where('id', $transaction->id)->delete();

    expect(TransactionSplit::query()->where('transaction_id', $transaction->id)->count())->toBe(0);
});

it('attributes split lines to their categories in the spending breakdown', function () {
    $restaurant = expenseCategory($this->user, 'Restaurant');
    $travel = expenseCategory($this->user, 'Travel');
    $transaction = splittableTransaction($this->user, -10000);
    $transaction->update(['transaction_date' => now()]);

    app(TransactionSplitter::class)->apply($transaction, [
        ['category_id' => $restaurant->id, 'amount' => -2500],
        ['category_id' => $travel->id, 'amount' => -7500],
    ]);

    $spending = app(CategorySpendingService::class)->forPeriod(
        $this->user->id,
        now()->subDay(),
        now()->addDay(),
    );

    $byCategory = $spending->keyBy('category_id');

    expect($byCategory[$restaurant->id]['amount'])->toBe(2500);
    expect($byCategory[$travel->id]['amount'])->toBe(7500);
});

it('reflects split lines in the cashflow expense breakdown endpoint', function () {
    $restaurant = expenseCategory($this->user, 'Restaurant');
    $travel = expenseCategory($this->user, 'Travel');
    $transaction = splittableTransaction($this->user, -10000);
    $transaction->update(['transaction_date' => now()]);

    app(TransactionSplitter::class)->apply($transaction, [
        ['category_id' => $restaurant->id, 'amount' => -2500],
        ['category_id' => $travel->id, 'amount' => -7500],
    ]);

    $response = actingAs($this->user)->getJson(
        '/api/cashflow/breakdown?type=expense&from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString(),
    )->assertOk();

    $amounts = collect($response->json('data'))->keyBy('category_id');

    expect($amounts[$restaurant->id]['amount'])->toBe(2500);
    expect($amounts[$travel->id]['amount'])->toBe(7500);
});

it('attributes only the matching split line to a category budget', function () {
    $tracked = expenseCategory($this->user, 'Tracked');
    $other = expenseCategory($this->user, 'Other');
    $transaction = splittableTransaction($this->user, -10000);
    $transaction->update(['transaction_date' => now()]);

    app(TransactionSplitter::class)->apply($transaction, [
        ['category_id' => $tracked->id, 'amount' => -2500],
        ['category_id' => $other->id, 'amount' => -7500],
    ]);

    $budget = Budget::factory()->forCategories($tracked)->create(['user_id' => $this->user->id]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(15),
        'end_date' => now()->addDays(15),
    ]);

    app(BudgetTransactionService::class)->assignTransaction($transaction->fresh());

    $budgetTransaction = BudgetTransaction::query()
        ->where('transaction_id', $transaction->id)
        ->where('budget_period_id', $period->id)
        ->first();

    expect($budgetTransaction)->not->toBeNull();
    expect($budgetTransaction->amount)->toBe(2500);
});

it('finds a split transaction when filtering the list by a line category', function () {
    $restaurant = expenseCategory($this->user, 'Restaurant');
    $travel = expenseCategory($this->user, 'Travel');
    $transaction = splittableTransaction($this->user, -10000);

    app(TransactionSplitter::class)->apply($transaction, [
        ['category_id' => $restaurant->id, 'amount' => -2500],
        ['category_id' => $travel->id, 'amount' => -7500],
    ]);

    $matches = Transaction::query()
        ->where('user_id', $this->user->id)
        ->applyFilters(['category_ids' => [$restaurant->id], 'user_id' => $this->user->id])
        ->pluck('id');

    expect($matches->all())->toContain($transaction->id);
});

it('does not treat a fully-categorized split as uncategorized', function () {
    $restaurant = expenseCategory($this->user, 'Restaurant');
    $travel = expenseCategory($this->user, 'Travel');
    $split = splittableTransaction($this->user, -10000);
    $uncategorized = splittableTransaction($this->user, -5000);

    app(TransactionSplitter::class)->apply($split, [
        ['category_id' => $restaurant->id, 'amount' => -2500],
        ['category_id' => $travel->id, 'amount' => -7500],
    ]);

    $matches = Transaction::query()
        ->where('user_id', $this->user->id)
        ->applyFilters(['category_ids' => ['uncategorized'], 'user_id' => $this->user->id])
        ->pluck('id');

    expect($matches->all())->toContain($uncategorized->id);
    expect($matches->all())->not->toContain($split->id);
});

it('rejects splitting a zero-amount transaction', function () {
    $category = expenseCategory($this->user);
    $transaction = splittableTransaction($this->user, 0);

    actingAs($this->user)
        ->patchJson(route('transactions.update', $transaction), [
            'splits' => [
                ['category_id' => $category->id, 'amount' => 50],
                ['category_id' => $category->id, 'amount' => -50],
            ],
        ])
        ->assertJsonValidationErrors(['splits']);
});

it('classifies split lines by their own category type in the dashboard cash flow', function () {
    $expense = expenseCategory($this->user, 'Groceries');
    $savings = Category::factory()->for($this->user)->create([
        'name' => 'Savings',
        'type' => CategoryType::Savings,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
    $transaction = splittableTransaction($this->user, -10000);
    $transaction->update(['transaction_date' => now()]);

    app(TransactionSplitter::class)->apply($transaction, [
        ['category_id' => $expense->id, 'amount' => -3000],
        ['category_id' => $savings->id, 'amount' => -7000],
    ]);

    $response = actingAs($this->user)->getJson(
        '/api/dashboard/cash-flow?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString(),
    )->assertOk();

    // Only the expense line counts as expense; the savings line is excluded.
    expect($response->json('current.expense'))->toBe(3000);
});

it('attributes split lines per category on the analysis screen', function () {
    $restaurant = expenseCategory($this->user, 'Restaurant');
    $travel = expenseCategory($this->user, 'Travel');
    $transaction = splittableTransaction($this->user, -10000);
    $transaction->update(['transaction_date' => now()]);

    app(TransactionSplitter::class)->apply($transaction, [
        ['category_id' => $restaurant->id, 'amount' => -2500],
        ['category_id' => $travel->id, 'amount' => -7500],
    ]);

    $response = actingAs($this->user)->getJson(
        '/api/transactions/analysis?date_from='.now()->subDay()->toDateString().'&date_to='.now()->addDay()->toDateString(),
    )->assertOk();

    $byCategory = collect($response->json('by_category'))->keyBy('category_id');

    expect($byCategory[$restaurant->id]['amount'])->toBe(2500);
    expect($byCategory[$travel->id]['amount'])->toBe(7500);
});

it('skips split transactions on a bulk label assignment', function () {
    $category = expenseCategory($this->user);
    $label = Label::factory()->for($this->user)->create();
    $split = splittableTransaction($this->user, -10000);
    $plain = splittableTransaction($this->user, -5000);

    app(TransactionSplitter::class)->apply($split, [
        ['category_id' => $category->id, 'amount' => -2500],
        ['category_id' => $category->id, 'amount' => -7500],
    ]);

    actingAs($this->user)
        ->patchJson(route('transactions.bulk-update'), [
            'transaction_ids' => [$split->id, $plain->id],
            'label_ids' => [$label->id],
        ])
        ->assertOk();

    expect($split->fresh()->labels)->toHaveCount(0);
    expect($plain->fresh()->labels->pluck('id')->all())->toContain($label->id);
});

it('skips split transactions on a bulk category assignment', function () {
    $category = expenseCategory($this->user);
    $newCategory = expenseCategory($this->user, 'New');
    $split = splittableTransaction($this->user, -10000);
    $plain = splittableTransaction($this->user, -5000);

    app(TransactionSplitter::class)->apply($split, [
        ['category_id' => $category->id, 'amount' => -2500],
        ['category_id' => $category->id, 'amount' => -7500],
    ]);

    $response = actingAs($this->user)
        ->patchJson(route('transactions.bulk-update'), [
            'transaction_ids' => [$split->id, $plain->id],
            'category_id' => $newCategory->id,
        ])
        ->assertOk();

    expect($response->json('omitted_splits'))->toBe(1);
    expect($split->fresh()->category_id)->toBeNull();
    expect($plain->fresh()->category_id)->toBe($newCategory->id);
});
