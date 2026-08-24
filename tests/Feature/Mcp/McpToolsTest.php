<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListAutomationRules;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\SearchTransactions;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;

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
