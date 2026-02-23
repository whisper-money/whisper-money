<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can view accounts page', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/accounts');

    $page->assertSee('Accounts')
        ->assertSee('View and manage your bank accounts')
        ->assertNoJavascriptErrors();
});

it('shows empty state when no accounts exist', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/accounts');

    $page->assertSee('Accounts')
        ->waitForText('No accounts found')
        ->assertNoJavascriptErrors();
});

it('shows account cards for existing accounts', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Test Bank', 'logo' => null]);

    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Checking',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/accounts');
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->assertSee('Accounts')
        ->waitForText('My Checking')
        ->assertSee('Test Bank')
        ->assertNoJavascriptErrors();
});

it('shows multiple accounts grouped by type', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Bank One', 'logo' => null]);

    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Daily Checking',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Rainy Day Savings',
        'type' => AccountType::Savings,
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/accounts');
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->assertSee('Accounts')
        ->waitForText('Daily Checking')
        ->assertSee('Rainy Day Savings')
        ->assertNoJavascriptErrors();
});

it('can navigate to account details page', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Nav Bank', 'logo' => null]);

    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Navigable Account',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/accounts');
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->waitForText('Navigable Account')
        ->click('Details →')
        ->wait(2)
        ->assertSee('Update balance')
        ->assertSee('Nav Bank')
        ->assertNoJavascriptErrors();
});

it('does not show other users accounts', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Shared Bank', 'logo' => null]);

    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Own Account',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);
    Account::factory()->create([
        'user_id' => $otherUser->id,
        'bank_id' => $bank->id,
        'name' => 'Someone Elses Account',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/accounts');
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->waitForText('My Own Account')
        ->assertDontSee('Someone Elses Account')
        ->assertNoJavascriptErrors();
});
