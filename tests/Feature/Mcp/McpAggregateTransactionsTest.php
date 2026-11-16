<?php

use App\Enums\CategoryType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\AggregateTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A member of a shared space owned by someone else, each with one expense
 * there.
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

it('adds up every member\'s transactions of the space, like the other MCP tools, with formatted amounts', function () {
    ['user' => $user, 'space' => $space] = mcpAggregateSpace();
    // The caller's own expense in their personal space stays out.
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR'])->id,
        'amount' => -7,
        'currency_code' => 'EUR',
        'transaction_date' => '2026-10-05',
    ]);

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
        ->assertSee('{"month":"2026-10","currency":"EUR","sum":-11233,"count":2,"avg":-5617,"sum_formatted":"€-112.33","avg_formatted":"€-56.17"}');
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

it('takes an account, category or label of another member, and refuses one from another space', function (string $field, string $noun) {
    ['owner' => $owner, 'user' => $user, 'space' => $space, 'theirs' => $theirs] = mcpAggregateSpace();
    $id = match ($field) {
        'account_ids' => $theirs->id,
        'category_ids' => Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
        'label_ids' => Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
    };
    $elsewhere = match ($field) {
        'account_ids' => Account::factory()->create(['user_id' => $user->id])->id,
        'category_ids' => Category::factory()->create(['user_id' => $user->id])->id,
        'label_ids' => Label::factory()->create(['user_id' => $user->id])->id,
    };

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, ['from' => '2026-10-01', 'to' => '2026-10-31', $field => [$id], 'space' => $space->id])
        ->assertOk();

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, ['from' => '2026-10-01', 'to' => '2026-10-31', $field => [$elsewhere], 'space' => $space->id])
        ->assertHasErrors(["One or more {$noun} ids do not exist in space {$space->id}."]);
})->with([
    'account' => ['account_ids', 'account'],
    'category' => ['category_ids', 'category'],
    'label' => ['label_ids', 'label'],
]);

it('counts a split transaction by its lines, and every line under its label', function () {
    ['user' => $user, 'owner' => $owner, 'space' => $space, 'theirs' => $theirs] = mcpAggregateSpace();
    $food = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $home = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'type' => CategoryType::Expense, 'name' => 'Home']);
    $trip = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Trip']);
    $split = Transaction::factory()->create(['user_id' => $owner->id, 'account_id' => $theirs->id, 'category_id' => null, 'amount' => -5_000, 'currency_code' => 'EUR', 'transaction_date' => '2026-10-07']);
    $split->labels()->attach($trip->id);

    foreach ([[$food, -3_000], [$home, -2_000]] as $position => [$category, $amount]) {
        TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $category->id, 'amount' => $amount, 'position' => $position]);
    }

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, ['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['category'], 'metrics' => ['sum'], 'category_ids' => [$food->id, $home->id], 'space' => $space->id])
        ->assertOk()
        ->assertSee('"buckets":[{"category_id":"'.$food->id.'","category_name":"Food","currency":"EUR","sum":-3000,"sum_formatted":"€-30.00"},{"category_id":"'.$home->id.'","category_name":"Home","currency":"EUR","sum":-2000,"sum_formatted":"€-20.00"}]');

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, ['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['label'], 'label_ids' => [$trip->id], 'space' => $space->id])
        ->assertOk()
        ->assertSee('"buckets":[{"label_id":"'.$trip->id.'","label_name":"Trip","currency":"EUR","sum":-5000,"count":1,"sum_formatted":"€-50.00"}]');
});

it('takes a list as a comma-separated string too', function () {
    ['user' => $user, 'space' => $space] = mcpAggregateSpace();

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, ['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => 'month,type', 'metrics' => 'sum', 'space' => $space->id])
        ->assertOk()
        ->assertSee('"buckets":[{"month":"2026-10","type":"expense","currency":"EUR","sum":-11233,"sum_formatted":"€-112.33"}]');
});

it('refuses more buckets than the cap', function () {
    ['user' => $user] = mcpAggregateSpace();

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, ['from' => '2020-01-01', 'to' => '2026-10-31', 'group_by' => ['day']])
        ->assertHasErrors(['This aggregation returns more than 1000 buckets.']);
});

it('names an account by group as list_accounts does', function () {
    ['user' => $user, 'space' => $space, 'account' => $account] = mcpAggregateSpace();
    $account->forceFill(['name' => 'c2VjcmV0', 'name_iv' => 'aXZpdml2aXZpdml2', 'encrypted' => true])->save();

    WhisperMoneyServer::actingAs($user)
        ->tool(AggregateTransactions::class, ['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => ['account'], 'metrics' => ['count'], 'account_ids' => [$account->id], 'space' => $space->id])
        ->assertOk()
        ->assertSee('{"account_id":"'.$account->id.'","account_name":"c2VjcmV0","currency":"EUR","count":1}');
});
