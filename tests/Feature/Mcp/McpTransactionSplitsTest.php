<?php

use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Features\TransactionSplitting;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\MergeTransactionSplits;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SplitTransaction;
use App\Mcp\Tools\UpdateTransaction;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\TransactionSplitter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Pennant\Feature;

/**
 * @param  array<string, mixed>  $transactionAttributes
 * @return array{0: User, 1: Account, 2: Transaction, 3: Category, 4: Category}
 */
function splitMcpFixture(array $transactionAttributes = []): array
{
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->plaintext()->create([
        'user_id' => $user->id,
        'space_id' => $account->space_id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -10000,
        'transaction_date' => '2026-07-18',
        'currency_code' => 'EUR',
        ...$transactionAttributes,
    ]);
    $food = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'name' => 'Food',
        'type' => CategoryType::Expense,
    ]);
    $home = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'name' => 'Home',
        'type' => CategoryType::Expense,
    ]);

    return [$user, $account, $transaction, $food, $home];
}

/**
 * @param  class-string  $tool
 * @param  array<string, mixed>  $arguments
 * @param  list<string>  $abilities
 */
function callMcpSplitTool(User $user, array $arguments, array $abilities = ['mcp:read', 'mcp:write'], string $tool = SplitTransaction::class): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', $abilities)->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

/**
 * The parts of $original, smallest amount first.
 *
 * @return Collection<int, Transaction>
 */
function mcpSplitParts(Transaction $original)
{
    return Transaction::query()->where('split_parent_id', $original->id)->orderBy('amount')->get();
}

it('splits a transaction into parts, each with its own category and labels, and keeps the original out of the lists', function () {
    [$user, , $transaction, $food] = splitMcpFixture();
    $rule = AutomationRule::factory()->create(['user_id' => $user->id, 'action_category_id' => $food->id]);
    $label = Label::factory()->create(['user_id' => $user->id, 'space_id' => $transaction->space_id, 'name' => 'Shared']);
    $transaction->forceFill([
        'category_id' => $food->id,
        'category_source' => CategorySource::Ai,
        'categorized_by_rule_id' => $rule->id,
        'ai_confidence' => 0.92,
        'ai_model' => 'test-model',
        'ai_suggested_category_at' => Carbon::parse('2026-07-17 12:00:00'),
    ])->saveQuietly();

    $payload = mcpPayload(callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [
            ['amount' => -6000, 'category_id' => Str::upper($food->id), 'label_ids' => [Str::upper($label->id)]],
            ['amount' => -4000],
        ],
    ])->assertOk());

    $parts = mcpSplitParts($transaction);

    expect($payload['split_into'])->toBe(2)
        ->and(collect($payload['parts'])->pluck('split_parent_id')->unique()->all())->toBe([$transaction->id])
        ->and(collect($payload['parts'])->pluck('amount')->all())->toBe([-6000, -4000])
        ->and($payload['parts'][0]['category_id'])->toBe($food->id)
        ->and($payload['parts'][0]['category_source'])->toBe('manual')
        ->and($payload['parts'][0]['labels'])->toBe([['id' => $label->id, 'name' => 'Shared']])
        ->and($payload['parts'][1]['category_id'])->toBeNull()
        ->and($payload['parts'][1]['is_split'])->toBeFalse()
        ->and($parts->pluck('amount')->all())->toBe([-6000, -4000])
        ->and($parts->pluck('ai_model')->unique()->all())->toBe([null])
        ->and($parts->pluck('categorized_by_rule_id')->unique()->all())->toBe([null])
        ->and(Transaction::query()->find($transaction->id))->toBeNull()
        ->and($transaction->fresh()->trashed())->toBeTrue()
        // The original keeps the category it had, for a merge to bring back.
        ->and($transaction->fresh()->category_id)->toBe($food->id);

    $listed = mcpPayload(callMcpSplitTool($user, [], tool: SearchTransactions::class)->assertOk());

    expect(collect($listed['transactions'])->pluck('id')->sort()->values()->all())->toBe($parts->pluck('id')->sort()->values()->all())
        ->and(collect($listed['transactions'])->pluck('split_parent_id')->unique()->all())->toBe([$transaction->id]);
});

it('refuses an invalid split, writing nothing', function (array $splits) {
    [$user, , $transaction] = splitMcpFixture();

    callMcpSplitTool($user, ['transaction_id' => $transaction->id, 'splits' => $splits])->assertHasErrors();

    expect(mcpSplitParts($transaction))->toHaveCount(0)
        ->and($transaction->fresh()->trashed())->toBeFalse();
})->with([
    'parts that do not add up' => [[['amount' => -6000], ['amount' => -3000]]],
    'a part that moves money the other way' => [[['amount' => -11000], ['amount' => 1000]]],
    'a single part' => [[['amount' => -10000]]],
    'no parts' => [[]],
    'a part with a field the tool does not take' => [[['amount' => -6000, 'category' => 'food'], ['amount' => -4000]]],
    'a label twice on one part' => [[['amount' => -6000, 'label_ids' => ['a', 'A']], ['amount' => -4000]]],
]);

it('says which fields a part takes, and that a label goes once on a part', function (array $part, string $message) {
    [$user, , $transaction] = splitMcpFixture();

    callMcpSplitTool($user, ['transaction_id' => $transaction->id, 'splits' => [$part, ['amount' => -4000]]])
        ->assertHasErrors([$message]);

    expect(mcpSplitParts($transaction))->toHaveCount(0);
})->with([
    'an unknown field' => [['amount' => -6000, 'description' => 'x'], 'A part takes only amount, category_id and label_ids.'],
    'a label twice' => [['amount' => -6000, 'label_ids' => ['a', 'A']], 'A label can only be given once per part.'],
]);

it('puts the same label on several parts of a split', function () {
    [$user, , $transaction] = splitMcpFixture();
    $label = Label::factory()->create(['user_id' => $user->id, 'space_id' => $transaction->space_id]);

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [['amount' => -6000, 'label_ids' => [$label->id]], ['amount' => -4000, 'label_ids' => [$label->id]]],
    ])->assertOk();

    expect(mcpSplitParts($transaction)->map(fn (Transaction $part): array => $part->labels()->pluck('labels.id')->all())->all())
        ->toBe([[$label->id], [$label->id]]);
});

it('refuses a category or label from outside the space, writing nothing', function () {
    [$user, , $transaction] = splitMcpFixture();
    $stranger = User::factory()->create();
    $foreignCategory = Category::factory()->create(['user_id' => $stranger->id, 'type' => CategoryType::Expense]);
    $foreignLabel = Label::factory()->create(['user_id' => $stranger->id]);

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [['amount' => -6000, 'category_id' => $foreignCategory->id], ['amount' => -4000]],
    ])->assertHasErrors(['category ids do not exist in space']);

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [['amount' => -6000], ['amount' => -4000, 'label_ids' => [$foreignLabel->id]]],
    ])->assertHasErrors(['label ids do not exist in space']);

    expect(mcpSplitParts($transaction))->toHaveCount(0);
});

it('refuses a transaction outside the selected space', function () {
    [$user] = splitMcpFixture();
    $other = Transaction::factory()->create(['amount' => -10000]);

    callMcpSplitTool($user, [
        'transaction_id' => $other->id,
        'splits' => [['amount' => -6000], ['amount' => -4000]],
    ])->assertHasErrors(["No transaction with id {$other->id}"]);

    expect(mcpSplitParts($other))->toHaveCount(0);
});

it('splits and merges a housemate\'s transaction of a shared space with any member\'s category and label, as the other MCP writes reach them', function () {
    $member = User::factory()->create();
    $owner = User::factory()->create();
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $theirs = Transaction::factory()->create([
        'user_id' => $owner->id,
        'account_id' => Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
        'space_id' => $space->id,
        'amount' => -5000,
    ]);
    $theirCategory = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
    $myLabel = Label::factory()->create(['user_id' => $member->id, 'space_id' => $space->id]);

    $payload = mcpPayload(callMcpSplitTool($member, [
        'transaction_id' => $theirs->id,
        'space' => $space->id,
        'splits' => [['amount' => -3000, 'category_id' => $theirCategory->id, 'label_ids' => [$myLabel->id]], ['amount' => -2000]],
    ])->assertOk());

    expect($payload['parts'][0]['category_id'])->toBe($theirCategory->id)
        ->and($payload['parts'][0]['labels'][0]['id'])->toBe($myLabel->id)
        ->and(collect($payload['parts'])->pluck('split_parent_id')->unique()->all())->toBe([$theirs->id]);

    callMcpSplitTool($member, ['transaction_id' => $payload['parts'][1]['id'], 'space' => $space->id], tool: MergeTransactionSplits::class)
        ->assertOk();

    expect($theirs->fresh()->trashed())->toBeFalse()
        ->and(mcpSplitParts($theirs))->toHaveCount(0);
});

it('merges a split back from any of its parts, with the category the original had', function () {
    [$user, , $transaction, $food, $home] = splitMcpFixture(['category_source' => CategorySource::Manual]);
    $transaction->forceFill(['category_id' => $home->id])->saveQuietly();
    $parts = app(TransactionSplitter::class)->split($transaction, [['amount' => -6000, 'category_id' => $food->id], ['amount' => -4000]]);

    $payload = mcpPayload(callMcpSplitTool($user, ['transaction_id' => $parts->last()->id], tool: MergeTransactionSplits::class)->assertOk());

    expect($payload['merged'])->toBeTrue()
        ->and($payload['transaction']['id'])->toBe($transaction->id)
        ->and($payload['transaction']['amount'])->toBe(-10000)
        ->and($payload['transaction']['category_id'])->toBe($home->id)
        ->and($payload['transaction']['split_parent_id'])->toBeNull()
        ->and(mcpSplitParts($transaction))->toHaveCount(0)
        ->and($transaction->fresh()->trashed())->toBeFalse();
});

it('refuses to merge a transaction that was never split, and one still split into the old lines', function () {
    [$user, , $transaction, $food] = splitMcpFixture();

    callMcpSplitTool($user, ['transaction_id' => $transaction->id], tool: MergeTransactionSplits::class)
        ->assertHasErrors(['not part of a split']);

    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $food->id, 'amount' => -6000, 'position' => 0]);
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $food->id, 'amount' => -4000, 'position' => 1]);

    callMcpSplitTool($user, ['transaction_id' => $transaction->id], tool: MergeTransactionSplits::class)
        ->assertHasErrors(['not part of a split']);

    expect($transaction->splits()->count())->toBe(2);
});

it('refuses to split one part of a split again, and a transaction still split into the old lines', function () {
    [$user, , $transaction, $food] = splitMcpFixture();
    $part = app(TransactionSplitter::class)->split($transaction, [['amount' => -6000], ['amount' => -4000]])->first();

    callMcpSplitTool($user, ['transaction_id' => $part->id, 'splits' => [['amount' => -3000], ['amount' => -3000]]])
        ->assertHasErrors(['already one part of a split']);

    [$owner, , $legacy, $other] = splitMcpFixture();
    TransactionSplit::factory()->create(['transaction_id' => $legacy->id, 'category_id' => $other->id, 'amount' => -6000, 'position' => 0]);
    TransactionSplit::factory()->create(['transaction_id' => $legacy->id, 'category_id' => $other->id, 'amount' => -4000, 'position' => 1]);

    callMcpSplitTool($owner, ['transaction_id' => $legacy->id, 'splits' => [['amount' => -5000], ['amount' => -5000]]])
        ->assertHasErrors(['converted']);

    expect(mcpSplitParts($part))->toHaveCount(0)
        ->and(mcpSplitParts($legacy))->toHaveCount(0)
        ->and($legacy->splits()->count())->toBe(2);
});

it('refuses to delete one part of a split, a manual one included', function () {
    [$user, $account] = splitMcpFixture();
    $manual = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => -5000]);
    $parts = app(TransactionSplitter::class)->split($manual, [['amount' => -3000], ['amount' => -2000]]);

    callMcpSplitTool($user, ['transaction_id' => $parts->first()->id], tool: DeleteTransaction::class)
        ->assertHasErrors(['one part of a split']);

    expect(Transaction::query()->whereKey($parts->pluck('id'))->count())->toBe(2);
});

it('locks every core field of a part, its date included, and still writes its notes and category', function () {
    [$user, $account, , $food] = splitMcpFixture();
    $manual = Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => -5000, 'transaction_date' => '2026-07-18']);
    $part = app(TransactionSplitter::class)->split($manual, [['amount' => -3000], ['amount' => -2000]])->first();

    callMcpSplitTool($user, ['transaction_id' => $part->id, 'amount' => -1], tool: UpdateTransaction::class)
        ->assertHasErrors(['one part of a split']);
    callMcpSplitTool($user, ['transaction_id' => $part->id, 'transaction_date' => '2026-07-01'], tool: UpdateTransaction::class)
        ->assertHasErrors(['one part of a split']);

    callMcpSplitTool($user, ['transaction_id' => $part->id, 'notes' => 'The camera, not the lens', 'category_id' => $food->id], tool: UpdateTransaction::class)
        ->assertOk()
        ->assertSee('The camera, not the lens');

    callMcpSplitTool($user, ['transaction_id' => $part->id, 'category_id' => null], tool: CategorizeTransaction::class)
        ->assertOk();

    expect($part->fresh())
        ->amount->toBe(-3000)
        ->notes->toBe('The camera, not the lens')
        ->category_id->toBeNull()
        ->and($part->fresh()->transaction_date->toDateString())->toBe('2026-07-18');
});

it('still refuses a category or amount change on a transaction split into the old lines', function () {
    [$user, , $transaction, $food] = splitMcpFixture();
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $food->id, 'amount' => -6000, 'position' => 0]);
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $food->id, 'amount' => -4000, 'position' => 1]);

    callMcpSplitTool($user, ['transaction_id' => $transaction->id, 'category_id' => $food->id], tool: CategorizeTransaction::class)
        ->assertHasErrors(['split into lines the old way']);
    callMcpSplitTool($user, ['transaction_id' => $transaction->id, 'category_id' => $food->id], tool: UpdateTransaction::class)
        ->assertHasErrors(['split into lines the old way']);

    $payload = mcpPayload(callMcpSplitTool($user, ['transaction_id' => $transaction->id, 'notes' => 'Shared'], tool: UpdateTransaction::class)->assertOk());

    expect($payload['transaction']['is_split'])->toBeTrue()
        ->and($payload['transaction']['split_parent_id'])->toBeNull()
        ->and(collect($payload['transaction']['splits'])->pluck('amount')->all())->toBe([-6000, -4000])
        ->and($transaction->fresh()->category_id)->toBeNull();
});

it('refuses a new split while splitting is switched off, and still merges one back', function () {
    [$user, , $transaction] = splitMcpFixture();
    $parts = app(TransactionSplitter::class)->split($transaction, [['amount' => -6000], ['amount' => -4000]]);
    [, , $other] = splitMcpFixture(['user_id' => $user->id, 'account_id' => $transaction->account_id, 'space_id' => $transaction->space_id]);
    Feature::for($user)->deactivate(TransactionSplitting::class);

    callMcpSplitTool($user, ['transaction_id' => $other->id, 'splits' => [['amount' => -6000], ['amount' => -4000]]])
        ->assertSee('currently disabled');

    expect(mcpSplitParts($other))->toHaveCount(0);

    callMcpSplitTool($user, ['transaction_id' => $parts->first()->id], tool: MergeTransactionSplits::class)->assertOk();

    expect($transaction->fresh()->trashed())->toBeFalse();
});

it('rejects read-only tokens without splitting or merging', function () {
    [$user, , $transaction] = splitMcpFixture();
    $parts = app(TransactionSplitter::class)->split($transaction, [['amount' => -6000], ['amount' => -4000]]);
    [, , $other] = splitMcpFixture(['user_id' => $user->id, 'account_id' => $transaction->account_id, 'space_id' => $transaction->space_id]);

    callMcpSplitTool($user, ['transaction_id' => $other->id, 'splits' => [['amount' => -6000], ['amount' => -4000]]], ['mcp:read'])
        ->assertSee('read-only');
    callMcpSplitTool($user, ['transaction_id' => $parts->first()->id], ['mcp:read'], MergeTransactionSplits::class)
        ->assertSee('read-only');

    expect(mcpSplitParts($other))->toHaveCount(0)
        ->and(mcpSplitParts($transaction))->toHaveCount(2);
});
