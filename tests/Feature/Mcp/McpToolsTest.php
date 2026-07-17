<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\SearchTransactions;
use App\Models\Account;
use App\Models\Category;
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
