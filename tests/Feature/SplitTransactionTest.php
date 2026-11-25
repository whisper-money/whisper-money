<?php

use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Events\TransactionUpdated;
use App\Features\TransactionSplitting;
use App\Listeners\AssignTransactionToBudget;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Import;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;
use App\Services\Transactions\TransactionWriter;
use App\Services\Transactions\TransactionWriteRules;
use App\Services\TransactionSplitter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

beforeEach(function () {
    // Factory currencies are random; budget snapshots convert them 1:1.
    fakeCurrencyApi();
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'onboarded_at' => now(),
    ]);
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->category = Category::factory()->create(['user_id' => $this->user->id]);
});

function splittableTransaction(int $amount = -5340, ?string $description = null): Transaction
{
    // Plaintext: rules and the AI never read a description encrypted on the device.
    return Transaction::factory()->plaintext()->create([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'category_id' => null,
        'amount' => $amount,
        ...($description === null ? [] : ['description' => $description]),
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

it('keeps the category each part was given, even when an automation rule matches', function () {
    $ruleCategory = Category::factory()->create(['user_id' => $this->user->id]);
    $ruleLabel = Label::factory()->create(['user_id' => $this->user->id]);
    $otherCategory = Category::factory()->create(['user_id' => $this->user->id]);

    $rule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['mercadona', ['var' => 'description']]],
        'action_category_id' => $ruleCategory->id,
    ]);
    $rule->labels()->attach($ruleLabel->id);

    $original = splittableTransaction(-5340, 'MERCADONA S.A.');

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [
            ['amount' => -3490, 'category_id' => $this->category->id],
            ['amount' => -1850, 'category_id' => $otherCategory->id],
        ],
    ])->assertCreated();

    $parts = Transaction::query()
        ->where('split_parent_id', $original->id)
        ->with('labels')
        ->orderBy('amount')
        ->get();

    // The rule matches the description, but the user picked these categories a
    // second ago in the dialog — the rule does not get to overwrite them, nor to
    // put its own labels on the parts.
    expect($parts->pluck('category_id')->all())->toBe([$this->category->id, $otherCategory->id])
        ->and($parts->pluck('category_source')->unique()->all())->toBe([CategorySource::Manual])
        ->and($parts->pluck('categorized_by_rule_id')->unique()->all())->toBe([null])
        ->and($parts->pluck('labels')->flatten())->toBeEmpty();
});

it('still lets an automation rule categorize a part left uncategorized on purpose', function () {
    $ruleCategory = Category::factory()->create(['user_id' => $this->user->id]);

    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['mercadona', ['var' => 'description']]],
        'action_category_id' => $ruleCategory->id,
    ]);

    $original = splittableTransaction(-5340, 'MERCADONA S.A.');

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [
            ['amount' => -3490, 'category_id' => $this->category->id],
            ['amount' => -1850],
        ],
    ])->assertCreated();

    $parts = Transaction::query()->where('split_parent_id', $original->id)->orderBy('amount')->get();

    expect($parts->first()->category_id)->toBe($this->category->id)
        ->and($parts->last()->category_id)->toBe($ruleCategory->id)
        ->and($parts->last()->category_source)->toBe(CategorySource::Rule);
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

it('refuses splits that do not divide the original', function (array $splits) {
    $original = splittableTransaction(-5340);

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => $splits,
    ])->assertJsonValidationErrors('splits');

    expect(Transaction::query()->find($original->id))->not->toBeNull()
        ->and(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0);
})->with([
    'parts that do not add up to the original' => [[['amount' => -3000], ['amount' => -1000]]],
    'a part that moves money the other way' => [[['amount' => -6340], ['amount' => 1000]]],
    'fewer than two parts' => [[['amount' => -5340]]],
    'a part with no amount, even when the rest add up' => [[['amount' => -5340], ['amount' => 0]]],
]);

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
    fakeCurrencyApi();
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
    $original = Transaction::factory()->create([
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

/**
 * A budget tracking the test category over a period around today, holding
 * whatever already matches it.
 */
function budgetAroundToday(): BudgetPeriod
{
    $budget = Budget::factory()->forCategories(test()->category)->create(['user_id' => test()->user->id]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(15),
        'end_date' => now()->addDays(15),
    ]);

    app(BudgetTransactionService::class)->assignHistoricalTransactionsToPeriod($period);

    return $period;
}

it('refuses to split a transaction still split into the old lines', function () {
    $original = splittableTransaction(-1000);
    $original->splits()->createMany([
        ['category_id' => $this->category->id, 'amount' => -700, 'position' => 0],
        ['category_id' => $this->category->id, 'amount' => -300, 'position' => 1],
    ]);

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -600], ['amount' => -400]],
    ])->assertJsonValidationErrors('transaction_id');

    expect(Transaction::query()->find($original->id))->not->toBeNull()
        ->and(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0)
        ->and($original->splits()->count())->toBe(2);
});

it('refuses a new split while splitting is switched off, and still merges one back', function () {
    $original = splittableTransaction();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3490], ['amount' => -1850]],
    ])->assertCreated();

    $part = Transaction::query()->where('split_parent_id', $original->id)->first();

    Feature::for($this->user)->deactivate(TransactionSplitting::class);

    $other = splittableTransaction(-1000);

    $this->actingAs($this->user)->postJson("/transactions/{$other->id}/split", [
        'splits' => [['amount' => -600], ['amount' => -400]],
    ])->assertForbidden();

    expect(Transaction::query()->find($other->id))->not->toBeNull()
        ->and(Transaction::query()->where('split_parent_id', $other->id)->count())->toBe(0);

    $this->actingAs($this->user)
        ->deleteJson("/transactions/{$part->id}/split")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $original->id);
});

it('puts the parts of an imported transaction in its budget, and the original back on merge', function () {
    $import = Import::factory()->create(['user_id' => $this->user->id]);
    $original = Transaction::factory()->imported()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'amount' => -5000,
        'transaction_date' => now()->toDateString(),
        'import_id' => $import->id,
    ]);

    budgetAroundToday();

    expect(BudgetTransaction::query()->where('transaction_id', $original->id)->exists())->toBeTrue();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [
            ['amount' => -3000, 'category_id' => $this->category->id],
            ['amount' => -2000, 'category_id' => $this->category->id],
        ],
    ])->assertCreated();

    $parts = Transaction::query()->where('split_parent_id', $original->id)->get();

    // The parts keep the import, so undoing it still takes them away, and the
    // budget counts them although the creation of an imported row is skipped.
    expect($parts->pluck('import_id')->unique()->all())->toBe([$import->id])
        ->and(BudgetTransaction::query()->where('transaction_id', $original->id)->exists())->toBeFalse()
        ->and(BudgetTransaction::query()->whereIn('transaction_id', $parts->pluck('id'))->count())->toBe(2)
        ->and((int) BudgetTransaction::query()->whereIn('transaction_id', $parts->pluck('id'))->sum('amount'))->toBe(5000);

    $this->actingAs($this->user)
        ->deleteJson("/transactions/{$parts->first()->id}/split")
        ->assertSuccessful();

    expect(BudgetTransaction::query()->whereIn('transaction_id', $parts->pluck('id'))->count())->toBe(0)
        ->and((int) BudgetTransaction::query()->where('transaction_id', $original->id)->sum('amount'))->toBe(5000);
});

it('leaves no failing job behind when a budget job reaches a row split meanwhile', function () {
    $original = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'amount' => -5000,
        'transaction_date' => now()->toDateString(),
    ]);

    budgetAroundToday();

    // An edit queued the assignment; the user split the row before it ran.
    $event = new TransactionUpdated($original);
    app(TransactionSplitter::class)->split($original, [['amount' => -3000], ['amount' => -2000]]);

    app(AssignTransactionToBudget::class)->handle($event);

    expect(BudgetTransaction::query()->where('transaction_id', $original->id)->exists())->toBeFalse();
});

it('gives every part the description and notes with their device-encryption IVs, and keeps the bank ids on the original', function () {
    $original = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'amount' => -1000,
        'description_iv' => str_repeat('a', 16),
        'notes' => 'encrypted-notes',
        'notes_iv' => str_repeat('b', 16),
        'external_transaction_id' => 'bank-ref-1',
        'raw_data' => ['id' => 'bank-ref-1'],
    ]);

    $parts = app(TransactionSplitter::class)->split($original, [['amount' => -600], ['amount' => -400]]);

    expect($parts->pluck('description_iv')->unique()->all())->toBe([str_repeat('a', 16)])
        ->and($parts->pluck('notes')->unique()->all())->toBe(['encrypted-notes'])
        ->and($parts->pluck('notes_iv')->unique()->all())->toBe([str_repeat('b', 16)])
        ->and(Transaction::query()->whereIn('id', $parts->pluck('id'))->pluck('raw_data')->unique()->all())->toBe([null])
        ->and(Transaction::query()->whereIn('id', $parts->pluck('id'))->pluck('external_transaction_id')->unique()->all())->toBe([null]);
});

it('reads one movement per row: live rows and split originals, never parts or deleted rows', function () {
    $splitter = app(TransactionSplitter::class);

    $live = splittableTransaction(-100);
    $split = splittableTransaction(-5340);
    $splitter->split($split, [['amount' => -3490], ['amount' => -1850]]);
    $emptied = splittableTransaction(-1000);
    $splitter->split($emptied, [['amount' => -600], ['amount' => -400]])->each->delete();
    $deleted = splittableTransaction(-200);
    $deleted->delete();

    $movements = Transaction::query()->where('user_id', $this->user->id)->movements()->pluck('id')->sort()->values()->all();

    expect($movements)->toBe(collect([$live->id, $split->id])->sort()->values()->all());
});

it('refuses to split or merge a transaction another member owns in a shared space', function () {
    $member = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
    $household = Space::factory()->create(['owner_id' => $this->user->id]);
    $household->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $account = Account::factory()->create(['user_id' => $this->user->id, 'space_id' => $household->id]);
    $original = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'space_id' => $household->id,
        'category_id' => null,
        'amount' => -5340,
    ]);

    $this->actingAs($member)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3490], ['amount' => -1850]],
    ])->assertForbidden();

    $part = app(TransactionSplitter::class)->split($original, [['amount' => -3490], ['amount' => -1850]])->first();

    $this->actingAs($member)->deleteJson("/transactions/{$part->id}/split")->assertForbidden();

    expect(Transaction::query()->find($original->id))->toBeNull();
});

it('lets a bulk category change file the parts of a split like any other row', function () {
    $original = splittableTransaction();
    $partIds = app(TransactionSplitter::class)
        ->split($original, [['amount' => -3490], ['amount' => -1850]])
        ->pluck('id');

    $this->actingAs($this->user)->patchJson('/transactions/bulk', [
        'transaction_ids' => $partIds->all(),
        'category_id' => $this->category->id,
    ])->assertSuccessful()
        ->assertJsonPath('updated_count', 2)
        ->assertJsonPath('skipped_split_count', 0);

    expect(Transaction::query()->whereIn('id', $partIds)->pluck('category_id')->unique()->all())->toBe([$this->category->id]);
});

it('refuses deleting one part through the shared writer, and locks every core field of a part there', function () {
    $original = splittableTransaction();
    $part = app(TransactionSplitter::class)->split($original, [['amount' => -3490], ['amount' => -1850]])->first();

    expect(fn () => app(TransactionWriter::class)->delete($part, false))->toThrow(ValidationException::class)
        ->and(Transaction::query()->find($part->id))->not->toBeNull()
        ->and(array_keys(TransactionWriteRules::editRefusals($part, [
            'transaction_date' => '2026-01-01',
            'amount' => -1,
            'notes' => 'still editable',
            'category_id' => $this->category->id,
        ])))->toBe(['amount', 'transaction_date']);
});
