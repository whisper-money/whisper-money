<?php

use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Features\SplitTransactions;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'onboarded_at' => now(),
    ]);
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->category = Category::factory()->create(['user_id' => $this->user->id]);

    Feature::for($this->user)->activate(SplitTransactions::class);
});

function splittableTransaction(int $amount = -5340): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'category_id' => null,
        'amount' => $amount,
    ]);
}

it('replaces a transaction with its parts and keeps the original out of sight', function () {
    $original = splittableTransaction();
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [
            ['amount' => -3490, 'category_id' => $this->category->id, 'label_ids' => [$label->id]],
            ['amount' => -1850],
        ],
    ]);

    $response->assertCreated();

    $parts = Transaction::query()->where('split_parent_id', $original->id)->orderBy('amount')->get();

    expect($parts)->toHaveCount(2)
        ->and($parts->sum('amount'))->toBe($original->amount)
        ->and($parts->first()->category_id)->toBe($this->category->id)
        ->and($parts->first()->category_source)->toBe(CategorySource::Manual)
        ->and($parts->first()->labels->pluck('id')->all())->toBe([$label->id])
        ->and($parts->last()->category_id)->toBeNull()
        ->and($parts->last()->category_source)->toBeNull();

    // The original is gone from every list and total, but still on the row.
    expect(Transaction::query()->find($original->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($original->id)->trashed())->toBeTrue();
});

it('keeps the dedup fingerprint on the original and off the parts, so a re-sync cannot recreate it', function () {
    $original = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -1000,
        'dedup_fingerprint' => 'fingerprint-of-the-original',
        'external_transaction_id' => 'bank-ref-1',
    ]);

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [
            ['amount' => -600],
            ['amount' => -400],
        ],
    ])->assertCreated();

    // What the sync reads to decide "already imported" — it looks at trashed rows.
    $known = $this->account->transactions()->withTrashed()->pluck('dedup_fingerprint')->filter()->values()->all();

    expect($known)->toBe(['fingerprint-of-the-original'])
        ->and(Transaction::query()->where('split_parent_id', $original->id)->pluck('external_transaction_id')->unique()->all())
        ->toBe([null]);
});

it('gives every part the original description, source and date', function () {
    $original = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -1000,
        'description' => 'MERCADONA S.A.',
        'transaction_date' => '2026-08-22',
    ]);

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -600], ['amount' => -400]],
    ])->assertCreated();

    $parts = Transaction::query()->where('split_parent_id', $original->id)->get();

    expect($parts->pluck('description')->unique()->all())->toBe(['MERCADONA S.A.'])
        ->and($parts->pluck('source')->unique()->all())->toBe([TransactionSource::EnableBanking])
        ->and($parts->pluck('transaction_date')->map->toDateString()->unique()->all())->toBe(['2026-08-22']);
});

it('refuses splits that do not add up to the original', function () {
    $original = splittableTransaction(-5340);

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3000], ['amount' => -1000]],
    ])->assertJsonValidationErrors('splits');

    expect(Transaction::query()->find($original->id))->not->toBeNull()
        ->and(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0);
});

it('refuses a part that moves money the other way', function () {
    $original = splittableTransaction(-5340);

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -6340], ['amount' => 1000]],
    ])->assertJsonValidationErrors('splits');
});

it('refuses fewer than two parts', function () {
    $original = splittableTransaction(-5340);

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -5340]],
    ])->assertJsonValidationErrors('splits');
});

it('refuses a part with no amount, even when the rest add up', function () {
    $original = splittableTransaction(-5340);

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -5340], ['amount' => 0]],
    ])->assertJsonValidationErrors('splits');

    expect(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0);
});

it('refuses splitting a part again', function () {
    $original = splittableTransaction();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3490], ['amount' => -1850]],
    ])->assertCreated();

    $part = Transaction::query()->where('split_parent_id', $original->id)->first();

    $this->actingAs($this->user)->postJson("/transactions/{$part->id}/split", [
        'splits' => [['amount' => -1745], ['amount' => -1745]],
    ])->assertStatus(422);
});

it('refuses to split someone else another user owns', function () {
    $stranger = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
    Feature::for($stranger)->activate(SplitTransactions::class);

    $original = splittableTransaction();

    $this->actingAs($stranger)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3490], ['amount' => -1850]],
    ])->assertForbidden();
});

it('brings the original back and drops the parts when merging', function () {
    $original = splittableTransaction();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3490], ['amount' => -1850]],
    ])->assertCreated();

    $part = Transaction::query()->where('split_parent_id', $original->id)->first();

    $this->actingAs($this->user)
        ->deleteJson("/transactions/{$part->id}/split")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $original->id);

    expect(Transaction::query()->find($original->id))->not->toBeNull()
        ->and(Transaction::query()->find($original->id)->amount)->toBe(-5340)
        ->and(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0);
});

it('refuses to merge a transaction that was never split', function () {
    $transaction = splittableTransaction();

    $this->actingAs($this->user)
        ->deleteJson("/transactions/{$transaction->id}/split")
        ->assertStatus(422);
});

it('refuses to delete a single part', function () {
    $original = splittableTransaction();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3490], ['amount' => -1850]],
    ])->assertCreated();

    $part = Transaction::query()->where('split_parent_id', $original->id)->first();

    $this->actingAs($this->user)->deleteJson("/transactions/{$part->id}")->assertStatus(422);

    expect(Transaction::query()->find($part->id))->not->toBeNull();
});

it('keeps a part amount locked even when the original was created by hand', function () {
    $original = splittableTransaction();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3490], ['amount' => -1850]],
    ])->assertCreated();

    $part = Transaction::query()->where('split_parent_id', $original->id)->where('amount', -3490)->first();

    $this->actingAs($this->user)->patchJson("/transactions/{$part->id}", [
        'amount' => -1,
        'description' => 'Renamed part',
    ])->assertSuccessful();

    expect($part->fresh()->amount)->toBe(-3490)
        ->and($part->fresh()->description)->toBe('Renamed part');
});

it('lists the parts and not the original, with the other parts loaded', function () {
    $original = splittableTransaction();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [
            ['amount' => -3490, 'category_id' => $this->category->id],
            ['amount' => -1850],
        ],
    ])->assertCreated();

    $this->actingAs($this->user)
        ->get('/transactions')
        ->assertInertia(function ($page) use ($original) {
            $ids = collect($page->toArray()['props']['transactions']['data'])->pluck('id');
            $rows = collect($page->toArray()['props']['transactions']['data']);

            expect($ids)->not->toContain($original->id)
                ->and($ids)->toHaveCount(2)
                ->and($rows->first()['split_parent_id'])->toBe($original->id)
                ->and($rows->first()['split_siblings'])->toHaveCount(2);
        });
});

it('moves the budget from the original to the parts, and back again on merge', function () {
    $original = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'amount' => -5000,
        'transaction_date' => now()->toDateString(),
    ]);

    $budget = Budget::factory()->forCategories($this->category)->create(['user_id' => $this->user->id]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(15),
        'end_date' => now()->addDays(15),
    ]);

    app(BudgetTransactionService::class)->assignHistoricalTransactionsToPeriod($period);

    expect(BudgetTransaction::query()->where('transaction_id', $original->id)->exists())->toBeTrue();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [
            ['amount' => -3000, 'category_id' => $this->category->id],
            ['amount' => -2000, 'category_id' => $this->category->id],
        ],
    ])->assertCreated();

    $partIds = Transaction::query()->where('split_parent_id', $original->id)->pluck('id');

    // The original leaves the budget the moment it is split, and the parts take
    // its place — same money, now counted twice over as two rows.
    expect(BudgetTransaction::query()->where('transaction_id', $original->id)->exists())->toBeFalse()
        ->and(BudgetTransaction::query()->whereIn('transaction_id', $partIds)->count())->toBe(2);

    $this->actingAs($this->user)
        ->deleteJson("/transactions/{$partIds->first()}/split")
        ->assertSuccessful();

    expect(BudgetTransaction::query()->whereIn('transaction_id', $partIds)->count())->toBe(0)
        ->and(BudgetTransaction::query()->where('transaction_id', $original->id)->exists())->toBeTrue();
});

it('refuses to split at all while the feature is off', function () {
    Feature::for($this->user)->deactivate(SplitTransactions::class);

    $original = splittableTransaction();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3490], ['amount' => -1850]],
    ])->assertStatus(400);

    expect(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0);
});

it('still merges a split back after the feature is switched off', function () {
    $original = splittableTransaction();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3490], ['amount' => -1850]],
    ])->assertCreated();

    // Nobody should be left holding parts they cannot undo.
    Feature::for($this->user)->deactivate(SplitTransactions::class);

    $part = Transaction::query()->where('split_parent_id', $original->id)->first();

    $this->actingAs($this->user)
        ->deleteJson("/transactions/{$part->id}/split")
        ->assertSuccessful();

    expect(Transaction::query()->find($original->id))->not->toBeNull();
});
