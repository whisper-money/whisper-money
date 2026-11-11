<?php

use App\Enums\CategoryType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\AggregateTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A member of a shared space owned by someone else, each with one expense
 * there, and the member's own expense in the personal space.
 *
 * @return array{user: User, owner: User, space: Space, account: Account, theirs: Account}
 */
function mcpAggregateSpace(): array
{
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $owner = User::factory()->create(['currency_code' => 'EUR']);
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $account = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'currency_code' => 'EUR']);
    $theirs = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'currency_code' => 'EUR']);

    foreach ([[$account, -1_234], [$theirs, -9_999]] as [$on, $amount]) {
        Transaction::factory()->create([
            'user_id' => $on->user_id,
            'account_id' => $on->id,
            'category_id' => null,
            'amount' => $amount,
            'currency_code' => 'EUR',
            'transaction_date' => '2026-10-05',
        ]);
    }

    return compact('user', 'owner', 'space', 'account', 'theirs');
}

it('adds up only the caller\'s own transactions of the space, with formatted amounts', function () {
    ['user' => $user, 'space' => $space] = mcpAggregateSpace();

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, [
            'from' => '2026-09-01',
            'to' => '2026-10-31',
            'group_by' => ['month'],
            'metrics' => ['sum', 'count', 'avg'],
            'space' => $space->id,
        ])
        ->assertOk()
        ->assertSee('"space_id":"'.$space->id.'"')
        ->assertSee('{"month":"2026-09","currency":"EUR","sum":0,"count":0,"avg":null,"sum_formatted":"€0.00"}')
        ->assertSee('{"month":"2026-10","currency":"EUR","sum":-1234,"count":1,"avg":-1234,"sum_formatted":"€-12.34","avg_formatted":"€-12.34"}')
        ->assertDontSee('9999');
});

it('expands a category filter to its subcategories', function () {
    ['user' => $user, 'space' => $space, 'account' => $account] = mcpAggregateSpace();
    $food = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'type' => CategoryType::Expense]);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'type' => CategoryType::Expense, 'parent_id' => $food->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'category_id' => $groceries->id, 'amount' => -500, 'currency_code' => 'EUR', 'transaction_date' => '2026-10-06']);

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, ['from' => '2026-10-01', 'to' => '2026-10-31', 'category_ids' => [$food->id], 'space' => $space->id])
        ->assertOk()
        ->assertSee('"buckets":[{"currency":"EUR","sum":-500,"count":1,"sum_formatted":"€-5.00"}]');
});

it('refuses an account, category or label of another member', function (string $field, string $noun) {
    ['owner' => $owner, 'user' => $user, 'space' => $space, 'theirs' => $theirs] = mcpAggregateSpace();
    $id = match ($field) {
        'account_ids' => $theirs->id,
        'category_ids' => Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
        'label_ids' => Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
    };

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, ['from' => '2026-10-01', 'to' => '2026-10-31', $field => [$id], 'space' => $space->id])
        ->assertHasErrors(["One or more {$noun} ids are not ".Str::plural($noun)." of yours in space {$space->id}."]);
})->with([
    'account' => ['account_ids', 'account'],
    'category' => ['category_ids', 'category'],
    'label' => ['label_ids', 'label'],
]);

it('refuses more buckets than the cap', function () {
    ['user' => $user] = mcpAggregateSpace();

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, ['from' => '2020-01-01', 'to' => '2026-10-31', 'group_by' => ['day']])
        ->assertHasErrors(['This aggregation returns more than 1000 buckets.']);
});
