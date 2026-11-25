<?php

use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Features\TransactionSplitting;
use App\Listeners\AssignTransactionToBudget;
use App\Listeners\UnassignTransactionFromBudget;
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
use App\Services\Transactions\LegacySplits\LegacySplitConverter;
use App\Services\Transactions\TransactionWriter;
use App\Services\Transactions\TransactionWriteRules;
use App\Services\TransactionSplitter;
use Illuminate\Support\Facades\Mail;
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

/**
 * A budget over a period around today, tracking $tracked, holding whatever
 * already matches it.
 */
function budgetTrackingAroundToday(Label|Category $tracked): BudgetPeriod
{
    $budget = Budget::factory()
        ->{$tracked instanceof Label ? 'forLabels' : 'forCategories'}($tracked)
        ->create(['user_id' => test()->user->id]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(15),
        'end_date' => now()->addDays(15),
    ]);

    app(BudgetTransactionService::class)->assignHistoricalTransactionsToPeriod($period);

    return $period;
}

it('counts in a label budget only the part that keeps the label, once its labels are in place, and the original again on merge', function () {
    Mail::fake();
    $label = Label::factory()->create(['user_id' => $this->user->id]);
    $original = splittableTransaction(-5000);
    $original->update(['transaction_date' => now()->toDateString()]);
    $original->labels()->attach($label);
    $period = budgetTrackingAroundToday($label);

    expect((int) $period->budgetTransactions()->sole()->amount)->toBe(5000);

    $parts = app(TransactionSplitter::class)->split($original, [
        ['amount' => -3000, 'label_ids' => [$label->id]],
        ['amount' => -2000],
    ]);

    $held = $period->budgetTransactions()->get();

    expect($held)->toHaveCount(1)
        ->and($held->sole()->transaction_id)->toBe($parts->first()->id)
        ->and((int) $held->sole()->amount)->toBe(3000);

    app(TransactionSplitter::class)->merge($parts->last());

    expect($period->budgetTransactions()->pluck('transaction_id')->all())->toBe([$original->id])
        ->and((int) $period->budgetTransactions()->sole()->amount)->toBe(5000);

    // A split is not new spending: no budget email for its parts.
    Mail::assertNothingOutgoing();
});

it('counts in a category budget only the part filed there, for an imported original too, and the original again on merge', function (bool $imported) {
    $import = $imported ? Import::factory()->create(['user_id' => $this->user->id]) : null;
    $original = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Manual,
        'amount' => -5000,
        'transaction_date' => now()->toDateString(),
        'import_id' => $import?->id,
    ]);
    $period = budgetTrackingAroundToday($this->category);
    $elsewhere = Category::factory()->create(['user_id' => $this->user->id]);

    expect((int) $period->budgetTransactions()->sole()->amount)->toBe(5000);

    $parts = app(TransactionSplitter::class)->split($original, [
        ['amount' => -1200, 'category_id' => $elsewhere->id],
        ['amount' => -3800, 'category_id' => $this->category->id],
    ]);

    expect($period->budgetTransactions()->pluck('transaction_id')->all())->toBe([$parts->last()->id])
        ->and((int) $period->budgetTransactions()->sole()->amount)->toBe(3800);

    app(TransactionSplitter::class)->merge($parts->first());

    expect($period->budgetTransactions()->pluck('transaction_id')->all())->toBe([$original->id])
        ->and((int) $period->budgetTransactions()->sole()->amount)->toBe(5000);
})->with(['by hand' => false, 'imported' => true]);

it('puts the labels sent for a part on top of what an automation rule tagged it with', function () {
    $ruleCategory = Category::factory()->create(['user_id' => $this->user->id]);
    $ruleLabel = Label::factory()->create(['user_id' => $this->user->id]);
    $chosen = Label::factory()->create(['user_id' => $this->user->id]);
    $rule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['mercadona', ['var' => 'description']]],
        'action_category_id' => $ruleCategory->id,
    ]);
    $rule->labels()->attach($ruleLabel->id);
    $original = splittableTransaction(-6000, 'MERCADONA S.A.');

    $parts = app(TransactionSplitter::class)->split($original, [
        ['amount' => -1000],
        ['amount' => -2000, 'category_id' => $this->category->id, 'label_ids' => [$chosen->id]],
        ['amount' => -3000, 'label_ids' => [$chosen->id]],
    ])->map(fn (Transaction $part): Transaction => $part->fresh('labels'));

    $labels = $parts->map(fn (Transaction $part): array => $part->labels->pluck('id')->sort()->values()->all());

    expect($parts[0]->category_id)->toBe($ruleCategory->id)
        ->and($labels[0])->toBe([$ruleLabel->id])
        ->and($parts[1]->category_id)->toBe($this->category->id)
        ->and($labels[1])->toBe([$chosen->id])
        ->and($labels[2])->toBe(collect([$ruleLabel->id, $chosen->id])->sort()->values()->all());
});

it('checks the parts again against the row as it is under the lock, refusing a split of an amount edited meanwhile', function () {
    $stale = splittableTransaction(-5000);
    Transaction::query()->whereKey($stale->id)->update(['amount' => -7000]);

    expect(fn () => app(TransactionSplitter::class)->split($stale, [['amount' => -3000], ['amount' => -2000]]))
        ->toThrow(ValidationException::class, 'The parts must add up to the original amount of -7000');

    expect(Transaction::query()->where('split_parent_id', $stale->id)->exists())->toBeFalse()
        ->and($stale->fresh()->trashed())->toBeFalse()
        ->and($stale->fresh()->amount)->toBe(-7000);
});

it('keeps a merged original in its budgets when the unassignment the split queued runs after the merge', function () {
    $original = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'amount' => -5000,
        'transaction_date' => now()->toDateString(),
    ]);
    $period = budgetAroundToday();
    $splitter = app(TransactionSplitter::class);

    // The split's unassignment of the original, held back by a busy worker.
    $late = new TransactionDeleted($original->fresh());
    $parts = $splitter->split($original, [['amount' => -3000], ['amount' => -2000]]);
    $splitter->merge($parts->first());

    expect($period->budgetTransactions()->pluck('transaction_id')->all())->toBe([$original->id]);

    app(UnassignTransactionFromBudget::class)->handle($late);

    expect($period->budgetTransactions()->pluck('transaction_id')->all())->toBe([$original->id]);

    // A row that is still deleted is taken out as before.
    $original->delete();
    app(UnassignTransactionFromBudget::class)->handle(new TransactionDeleted($original));

    expect($period->budgetTransactions()->count())->toBe(0);
});

it('sends no budget email for a split or a merge, a rule filing a part included', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['mercadona', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);
    $period = budgetAroundToday();
    // A new row in the budget would be emailed about.
    $period->budget->update(['notify_on_new_transaction' => true]);
    $original = splittableTransaction(-5000, 'MERCADONA S.A.');
    $original->update(['transaction_date' => now()->toDateString(), 'category_id' => $this->category->id, 'category_source' => CategorySource::Manual]);
    app(BudgetTransactionService::class)->assignTransaction($original->fresh(), notify: false);
    // Faked from here on: filing the original above is an ordinary edit, which does email.
    Mail::fake();

    $parts = app(TransactionSplitter::class)->split($original, [['amount' => -3000], ['amount' => -2000, 'category_id' => $this->category->id]]);

    expect((int) $period->budgetTransactions()->sum('amount'))->toBe(5000);

    app(TransactionSplitter::class)->merge($parts->first());

    expect($period->budgetTransactions()->pluck('transaction_id')->all())->toBe([$original->id]);
    Mail::assertNothingOutgoing();
});

it('refuses a category or a label of the user\'s other space for a part', function () {
    $otherSpace = Space::factory()->create(['owner_id' => $this->user->id, 'personal' => false]);
    $foreignCategory = Category::factory()->create(['user_id' => $this->user->id, 'space_id' => $otherSpace->id]);
    $foreignLabel = Label::factory()->create(['user_id' => $this->user->id, 'space_id' => $otherSpace->id]);
    $original = splittableTransaction();

    $this->actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [
            ['amount' => -3490, 'category_id' => $foreignCategory->id],
            ['amount' => -1850, 'label_ids' => [$foreignLabel->id]],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['splits.0.category_id', 'splits.1.label_ids.0']);

    expect(Transaction::query()->where('split_parent_id', $original->id)->exists())->toBeFalse();
});

it('keeps exactly what an archived budget counted across a split and a merge, on a shared account with an odd amount', function (string $tracking) {
    $this->account->update(['ownership_percentage' => 33]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);
    $elsewhere = Category::factory()->create(['user_id' => $this->user->id]);
    $original = splittableTransaction(-1003);
    $original->update(['transaction_date' => now()->toDateString(), 'category_id' => $this->category->id, 'category_source' => CategorySource::Manual]);
    $original->labels()->attach($label);
    $period = budgetTrackingAroundToday($tracking === 'label' ? $label : $this->category);
    $period->budget->update(['archived_at' => now()]);

    // A third of 1003, rounded once for the whole row.
    expect((int) $period->budgetTransactions()->sole()->amount)->toBe(331);

    $parts = app(TransactionSplitter::class)->split($original->fresh(), [
        ['amount' => -501, 'category_id' => $this->category->id, 'label_ids' => [$label->id]],
        ['amount' => -502, 'category_id' => $elsewhere->id],
    ]);

    // Archived budgets take no new rows: the original's row moves to the
    // parts rather than going with the original, and the period keeps 331.
    expect((int) $period->budgetTransactions()->sum('amount'))->toBe(331)
        ->and($period->budgetTransactions()->pluck('transaction_id')->diff($parts->pluck('id')))->toBeEmpty();

    app(TransactionSplitter::class)->merge($parts->first());

    expect($period->budgetTransactions()->pluck('transaction_id')->all())->toBe([$original->id])
        ->and((int) $period->budgetTransactions()->sole()->amount)->toBe(331);
})->with(['category-tracked' => 'category', 'label-tracked' => 'label']);

it('shares an archived row the way the budget explains it when it can, and by part amount when it cannot', function () {
    $this->account->update(['ownership_percentage' => 33]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);
    $elsewhere = Category::factory()->create(['user_id' => $this->user->id]);
    $original = splittableTransaction(-1003);
    $original->update(['transaction_date' => now()->toDateString(), 'category_id' => $this->category->id, 'category_source' => CategorySource::Manual]);
    $original->labels()->attach($label);
    $byLabel = budgetTrackingAroundToday($label);
    $byCategory = budgetTrackingAroundToday($this->category);
    Budget::query()->whereKey([$byLabel->budget_id, $byCategory->budget_id])->update(['archived_at' => now()]);

    $parts = app(TransactionSplitter::class)->split($original->fresh(), [
        ['amount' => -2, 'category_id' => $this->category->id],
        ['amount' => -1001, 'category_id' => $elsewhere->id],
    ]);
    $held = fn (BudgetPeriod $period): array => $parts->map(fn (Transaction $part): ?int => $period->budgetTransactions()->where('transaction_id', $part->id)->value('amount'))->all();

    // The label claims both parts whole, a third of each: 1 + 330 is the 331
    // the row held. The category only claims the first part (1 of 331), so
    // the row is shared by part amount instead (0.66 rounds down to nothing),
    // the residue on the last part.
    expect($held($byLabel))->toBe([1, 330])
        ->and($held($byCategory))->toBe([null, 331]);
});

it('brings a converted split back as one uncategorized row without lines, which can be split again', function () {
    $original = splittableTransaction(-1000);
    fileUnderLegacyLines($original, [
        ['category_id' => $this->category->id, 'amount' => -700],
        ['category_id' => $this->category->id, 'amount' => -300],
    ]);
    app(LegacySplitConverter::class)->convert($original->fresh(['splits']));
    $part = Transaction::query()->where('split_parent_id', $original->id)->firstOrFail();

    app(TransactionSplitter::class)->merge($part);

    $merged = Transaction::query()->findOrFail($original->id);

    expect($merged->splits()->count())->toBe(0)
        ->and($merged->category_id)->toBeNull()
        ->and(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0);

    $parts = app(TransactionSplitter::class)->split($merged, [['amount' => -600], ['amount' => -400]]);

    expect($parts)->toHaveCount(2);
});
