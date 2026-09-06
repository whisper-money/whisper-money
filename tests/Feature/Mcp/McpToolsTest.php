<?php

use App\Features\Achievements;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListAchievements;
use App\Mcp\Tools\ListAutomationRules;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\SearchTransactions;
use App\Models\Account;
use App\Models\Achievement;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Pennant\Feature;

it('blocks read tools when subscriptions are enabled and the user has no paid plan', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListSpaces::class)
        ->assertHasErrors()
        ->assertSee('Pro');
});

it('allows read tools for a user on a paid plan', function () {
    // subscriptions disabled => everyone is treated as Pro (hasProPlan()).
    $user = User::factory()->create();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListSpaces::class)
        ->assertOk()
        ->assertSee('Personal');
});

it('searches transactions scoped to the user\'s space', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Blue Bottle Coffee',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['query' => 'Blue Bottle'])
        ->assertOk()
        ->assertSee('Blue Bottle Coffee');
});

it('filters transactions by label id', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id]);

    $labelled = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Labelled Lunch',
    ]);
    $labelled->labels()->attach($label->id);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Unlabelled Dinner',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['label_ids' => [$label->id]])
        ->assertOk()
        ->assertSee('Labelled Lunch')
        ->assertDontSee('Unlabelled Dinner');
});

it('rejects a label id the user cannot access', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreignLabel = Label::factory()->create(['user_id' => $other->id]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['label_ids' => [$foreignLabel->id]])
        ->assertHasErrors();
});

it('never exposes another user\'s transactions', function () {
    $user = User::factory()->create();
    $userAccount = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $userAccount->id,
        'description' => 'My Own Groceries',
    ]);

    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    Transaction::factory()->create([
        'user_id' => $other->id,
        'account_id' => $otherAccount->id,
        'description' => 'Secret Steakhouse',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, [])
        ->assertOk()
        ->assertSee('My Own Groceries')
        ->assertDontSee('Secret Steakhouse');
});

it('rejects a space id the user cannot access', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAccounts::class, ['space' => $other->personalSpace->id])
        ->assertHasErrors();
});

it('lists the user\'s accounts for the space', function () {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'name' => 'Everyday Checking']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertSee('Everyday Checking');
});

it('lists the user\'s categories for the space', function () {
    $user = User::factory()->create();
    Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListCategories::class, [])
        ->assertOk()
        ->assertSee('Groceries');
});

it('lists budgets with what the current period has spent and has left', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'Food Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
    ]);

    $budget->periods()->create([
        'start_date' => today()->startOfMonth(),
        'end_date' => today()->endOfMonth(),
        'allocated_amount' => 50_000,
        'carried_over_amount' => 0,
    ]);

    // Assigned to the period by the TransactionCreated listener.
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'transaction_date' => today(),
        'amount' => -12_000,
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk()
        ->assertSee('Food Budget')
        ->assertSee('Groceries')
        ->assertSee('"allocated_amount":50000')
        ->assertSee('"spent_amount":12000')
        ->assertSee('"remaining_amount":38000');
});

it('reports the remaining amount the app shows, ignoring carry-over', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'rollover_type' => 'carry_over',
    ]);

    $budget->periods()->create([
        'start_date' => today()->startOfMonth(),
        'end_date' => today()->endOfMonth(),
        'allocated_amount' => 50_000,
        'carried_over_amount' => 10_000,
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'transaction_date' => today(),
        'amount' => -12_000,
    ]);

    // The budget cards, the spending chart and the limit emails all measure
    // against the allocated amount alone, so the agent must not add carry-over.
    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk()
        ->assertSee('"carried_over_amount":10000')
        ->assertSee('"remaining_amount":38000');
});

it('leaves archived budgets out of the list', function () {
    $user = User::factory()->create();
    Budget::factory()->create(['user_id' => $user->id, 'name' => 'Running Budget']);
    Budget::factory()->archived()->create(['user_id' => $user->id, 'name' => 'Archived Budget']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk()
        ->assertSee('Running Budget')
        ->assertDontSee('Archived Budget');
});

it('never exposes another user\'s budgets', function () {
    $user = User::factory()->create();
    Budget::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Secret Budget']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk()
        ->assertDontSee('Secret Budget');
});

it('lists the user\'s automation rules with their actions', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Essentials']);

    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Supermarket rule',
        'priority' => 3,
        'action_category_id' => $category->id,
    ]);
    $rule->labels()->attach($label->id);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAutomationRules::class)
        ->assertOk()
        ->assertSee(['Supermarket rule', '"priority":3', $category->id, 'Essentials']);
});

it('never exposes another user\'s automation rules', function () {
    $user = User::factory()->create();
    AutomationRule::factory()->create([
        'user_id' => User::factory()->create()->id,
        'title' => 'Secret Rule',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAutomationRules::class)
        ->assertOk()
        ->assertDontSee('Secret Rule');
});

/*
 * Medals. The tool hands over the same payload the progress screen renders, so
 * a medal still to come stays a silhouette here too.
 */

function medalist(string ...$keys): User
{
    $user = User::factory()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    foreach ($keys as $key) {
        Achievement::factory()->key($key)->create([
            'user_id' => $user->id,
            'space_id' => $user->activeSpace()->id,
        ]);
    }

    return $user;
}

it('lists the medals a reader has earned alongside the ones still to come', function () {
    config()->set('achievements.enabled', true);

    WhisperMoneyServer::actingAs(medalist('transactions.1', 'net_worth.1'))
        ->tool(ListAchievements::class)
        ->assertOk()
        ->assertSee(['First transaction', '"unlocked":2', '"total":59']);
});

it('names the next rung of a track and keeps the ones past it to itself', function () {
    config()->set('achievements.enabled', true);

    WhisperMoneyServer::actingAs(medalist('transactions.1'))
        ->tool(ListAchievements::class)
        ->assertOk()
        // transactions.2 is the rung to aim at, so it arrives named and with a
        // bar to fill. safety.2 sits behind safety.1 and stays a silhouette.
        ->assertSee(['"state":"next"', 'Transactions recorded', '"goal":50'])
        ->assertSee('"state":"locked","name":null,"icon":null,"figure":null')
        ->assertDontSee('Emergency fund');
});

it('never exposes another user\'s medals', function () {
    config()->set('achievements.enabled', true);
    medalist('transactions.1');

    WhisperMoneyServer::actingAs(User::factory()->create())
        ->tool(ListAchievements::class)
        ->assertOk()
        // Not a name: the first rung of every track is revealed to everybody,
        // so what must not appear is a medal anyone actually holds.
        ->assertSee('"unlocked":0')
        ->assertDontSee('"state":"earned"');
});

it('says nothing about medals while the feature is off', function () {
    config()->set('achievements.enabled', false);
    Feature::purge(Achievements::class);

    WhisperMoneyServer::actingAs(medalist('transactions.1'))
        ->tool(ListAchievements::class)
        ->assertHasErrors()
        ->assertDontSee('First transaction');
});
