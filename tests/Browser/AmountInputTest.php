<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('formats amount on blur', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('Add Transaction')
        ->wait(1)
        ->fill('#amount', '123.45')
        ->click('description')
        ->wait(0.5)
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');

it('accepts comma as decimal separator', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('Add Transaction')
        ->wait(1)
        ->fill('#amount', '10,50')
        ->click('description')
        ->wait(0.5)
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');

it('can create a transaction with amount input', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('Add Transaction')
        ->wait(1)
        ->fill('description', 'Test Transaction')
        ->click('Select Account')
        ->wait(0.5)
        ->click($account->name)
        ->click('Select Category')
        ->wait(0.5)
        ->click($category->name)
        ->fill('#amount', '123.45')
        ->click('Create')
        ->wait(2)
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'description' => 'Test Transaction',
        'amount' => 12345,
    ]);
})->skip('Requires browser encryption key setup');
