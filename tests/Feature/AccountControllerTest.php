<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

test('guests are redirected to the login page for accounts index', function () {
    auth()->logout();

    $this->get(route('accounts.list'))->assertRedirect(route('login'));
});

test('guests are redirected to the login page for account show', function () {
    auth()->logout();

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    $this->get(route('accounts.show', $account))->assertRedirect(route('login'));
});

test('authenticated users can visit the accounts index', function () {
    $this->get(route('accounts.list'))->assertOk();
});

test('accounts index returns accounts grouped by type', function () {
    $checking = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);
    $savings = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
    ]);
    $investment = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 3)
        );
});

test('accounts are ordered by type then name', function () {
    Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'name' => 'A Savings',
    ]);
    Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'B Checking',
    ]);
    Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'A Checking',
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 3)
            ->where('accounts.0.type', 'checking')
            ->where('accounts.0.name', 'A Checking')
            ->where('accounts.1.type', 'checking')
            ->where('accounts.1.name', 'B Checking')
            ->where('accounts.2.type', 'savings')
        );
});

test('accounts index only shows user accounts', function () {
    $myAccount = Account::factory()->create([
        'user_id' => $this->user->id,
    ]);
    $otherAccount = Account::factory()->create([
        'user_id' => User::factory()->create()->id,
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 1)
            ->where('accounts.0.id', $myAccount->id)
        );
});

test('authenticated users can view their own account', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account')
            ->where('account.id', $account->id)
        );
});

test('users cannot view other users accounts', function () {
    $otherUser = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertForbidden();
});

test('account show includes categories, accounts, and banks', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
    ]);

    Category::factory()->count(3)->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account')
            ->has('categories', 3)
            ->has('accounts', 1)
            ->has('banks')
        );
});

test('account balance evolution returns data for single account', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonthNoOverflow()->endOfMonth(),
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 150000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/balance-evolution?'.http_build_query([
        'from' => now()->subMonths(2)->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveKeys(['data', 'account']);
    expect($data['data'])->toHaveCount(3);
    expect($data['data'][0])->toHaveKeys(['month', 'timestamp', 'value']);
    expect($data['account']['id'])->toBe($account->id);
});

test('account balance evolution denies access to other users accounts', function () {
    $otherUser = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/balance-evolution?'.http_build_query([
        'from' => now()->subMonth()->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertForbidden();
});

test('account show includes bank information', function () {
    $bank = Bank::factory()->create([
        'name' => 'Test Bank',
        'logo' => 'https://example.com/logo.png',
    ]);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $bank->id,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account.bank')
            ->where('account.bank.name', 'Test Bank')
        );
});
